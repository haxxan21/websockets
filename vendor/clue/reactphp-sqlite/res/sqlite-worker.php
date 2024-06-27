<?php

// This child worker process will be started by the main process to start communication over process pipe I/O
//
// Communication happens via newline-delimited JSON-RPC messages, see:
// $ php res/sqlite-worker.php
// < {"id":1,"method":"open","params":["examples/users.db",null]}
// > {"id":1,"result":true}
// < {"id":2,"method":"query","params":["SELECT 42",[]]}
// > {"id":2,"result":{"columns":["42"],"rows":[{"42":42}],"insertId":0,"changed":0}}
// < {"id":3,"method":"query","params":["SELECT ? AS name",["Alice"]]}
// > {"id":3,"result":{"columns":["name"],"rows":[{"name":"Alice"}],"insertId":0,"changed":0}}
//
// Or via socket connection (used for Windows, which does not support non-blocking process pipe I/O)
// $ nc localhost 8080
// $ php res/sqlite-worker.php localhost:8080

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Clue\React\SQLite\Io\BlockingDatabase;
use Clue\React\SQLite\Result;
use React\EventLoop\Factory;
use React\Stream\DuplexResourceStream;
use React\Stream\ReadableResourceStream;
use React\Stream\ThroughStream;
use React\Stream\WritableResourceStream;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // local project development, go from /res to /vendor
    require __DIR__ . '/../vendor/autoload.php';
} else {
    // project installed as dependency, go upwards from /vendor/clue/reactphp-sqlite/res
    require __DIR__ . '/../../../autoload.php';
}

$loop = Factory::create();

if (isset($_SERVER['argv'][1])) {
    // socket address given, so try to connect through socket (Windows)
    $socket = stream_socket_client($_SERVER['argv'][1]);
    $stream = new DuplexResourceStream($socket, $loop);

    // pipe input through a wrapper stream so that an error on the input stream
    // will not immediately close the output stream without a chance to report
    // this error through the output stream.
    $through = new ThroughStream();
    $stream->on('data', function ($data) use ($through) {
        $through->write($data);
    });

    $in = new Decoder($through);
    $out = new Encoder($stream, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | (\PHP_VERSION_ID >= 50606 ? \JSON_PRESERVE_ZERO_FRACTION : 0));
} else {
    // no socket address given, use process I/O pipes
    $in = new Decoder(new ReadableResourceStream(\STDIN, $loop));
    $out = new Encoder(new WritableResourceStream(\STDOUT, $loop), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | (\PHP_VERSION_ID >= 50606 ? \JSON_PRESERVE_ZERO_FRACTION : 0));
}

// report error when input is invalid NDJSON
$in->on('error', function (Exception $e) use ($out) {
    $out->end(array(
        'error' => array(
            'code' => -32700, // parse error
            'message' => 'input error: ' . $e->getMessage()
        )
    ));
});

$db = null;
$in->on('data', function ($data) use (&$db, $in, $out) {
    if (!isset($data->id, $data->method, $data->params) || !\is_scalar($data->id) || !\is_string($data->method) || !\is_array($data->params)) {
        // input is valid JSON, but not JSON-RPC => close input and end output with error
        $in->close();
        $out->end(array(
            'error' => array(
                'code' => -32600, // invalid message
                'message' => 'malformed message'
            )
        ));
        return;
    }

    if ($data->method === 'open' && \count($data->params) === 2 && \is_string($data->params[0]) && ($data->params[1] === null || \is_int($data->params[1]))) {
        // open database with two parameters: $filename, $flags
        try {
            $db = new BlockingDatabase($data->params[0], $data->params[1]);

            $out->write(array(
                'id' => $data->id,
                'result' => true
            ));
        } catch (Exception $e) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $e->getMessage())
            ));
        } catch (Error $e) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $e->getMessage())
            ));
        }
    } elseif ($data->method === 'exec' && $db !== null && \count($data->params) === 1 && \is_string($data->params[0])) {
        // execute statement: $db->exec($sql)
        $db->exec($data->params[0])->then(function (Result $result) use ($data, $out) {
            $out->write(array(
                'id' => $data->id,
                'result' => array(
                    'insertId' => $result->insertId,
                    'changed' => $result->changed
                )
            ));
        }, function (Exception $e) use ($data, $out) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $e->getMessage())
            ));
        });
    } elseif ($data->method === 'query' && $db !== null && \count($data->params) === 2 && \is_string($data->params[0]) && (\is_array($data->params[1]) || \is_object($data->params[1]))) {
        // execute statement: $db->query($sql, $params)
        $params = [];
        foreach ($data->params[1] as $index => $value) {
            if (isset($value->float)) {
                $params[$index] = (float)$value->float;
            } elseif (isset($value->base64)) {
                // base64-decode string parameters as BLOB
                $params[$index] = \base64_decode($value->base64);
            } else {
                $params[$index] = $value;
            }
        }

        $db->query($data->params[0], $params)->then(function (Result $result) use ($data, $out) {
            $rows = null;
            if ($result->rows !== null) {
                $rows = [];
                foreach ($result->rows as $row) {
                    // base64-encode any string that is not valid UTF-8 without control characters (BLOB)
                    foreach ($row as &$value) {
                        if (\is_string($value) && \preg_match('/[\x00-\x08\x11\x12\x14-\x1f\x7f]/u', $value) !== 0) {
                            $value = ['base64' => \base64_encode($value)];
                        } elseif (\is_float($value) && \PHP_VERSION_ID < 50606) {
                            $value = ['float' => $value];
                        }
                    }
                    $rows[] = $row;
                }
            }

            $out->write(array(
                'id' => $data->id,
                'result' => array(
                    'columns' => $result->columns,
                    'rows' => $rows,
                    'insertId' => $result->insertId,
                    'changed' => $result->changed
                )
            ));
        }, function (Exception $e) use ($data, $out) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $e->getMessage())
            ));
        });
    } elseif ($data->method === 'close' && $db !== null && \count($data->params) === 0) {
        // close database and remove reference
        $db->close();
        $db = null;

        $out->write(array(
            'id' => $data->id,
            'result' => null
        ));
    } else {
        // no matching method found => report soft error and keep stream alive
        $out->write(array(
            'id' => $data->id,
            'error' => array(
                'code' => -32601, // invalid method
                'message' => 'invalid method call'
            )
        ));
    }
});

$loop->run();

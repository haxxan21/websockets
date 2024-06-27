<?php

namespace Clue\React\SQLite\Io;

use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Result;
use Evenement\EventEmitter;

/**
 * [Internal] The `BlockingDatabase` class is responsible for communicating with
 * the SQLite3 extension (ext-sqlite3) and mapping its API to async return values.
 *
 * @internal see DatabaseInterface instead
 * @see DatabaseInterface
 */
class BlockingDatabase extends EventEmitter implements DatabaseInterface
{
    /** @var \SQLite3 */
    private $sqlite;

    /** @var bool */
    private $closed = false;

    /**
     * @param string $filename
     * @param ?int $flags
     * @throws \Exception
     * @throws \Error
     * @internal see Factory instead
     */
    public function __construct($filename, $flags = null)
    {
        if ($flags === null) {
            $this->sqlite = new \SQLite3($filename);
        } else {
            $this->sqlite = new \SQLite3($filename, $flags);
        }
    }

    public function exec($sql)
    {
        if ($this->closed) {
            return \React\Promise\reject(new \RuntimeException('Database closed'));
        }

        // execute statement and suppress PHP warnings
        $ret = @$this->sqlite->exec($sql);

        if ($ret === false) {
            return \React\Promise\reject(new \RuntimeException(
                $this->sqlite->lastErrorMsg()
            ));
        }

        $result = new Result();
        $result->changed = $this->sqlite->changes();
        $result->insertId = $this->sqlite->lastInsertRowID();

        return \React\Promise\resolve($result);
    }

    public function query($sql, array $params = array())
    {
        if ($this->closed) {
            return \React\Promise\reject(new \RuntimeException('Database closed'));
        }

        // execute statement and suppress PHP warnings
        if ($params === []) {
            $result = @$this->sqlite->query($sql);
        } else {
            $statement = @$this->sqlite->prepare($sql);
            if ($statement === false) {
                $result = false;
            } else {
                assert($statement instanceof \SQLite3Stmt);
                foreach ($params as $index => $value) {
                    if ($value === null) {
                        $type = \SQLITE3_NULL;
                    } elseif ($value === true || $value === false) {
                        // explicitly cast bool to int because SQLite does not have a native boolean
                        $type = \SQLITE3_INTEGER;
                        $value = (int) $value;
                    } elseif (\is_int($value)) {
                        $type = \SQLITE3_INTEGER;
                    } elseif (\is_float($value)) {
                        $type = \SQLITE3_FLOAT;
                    } elseif (\preg_match('/[\x00-\x08\x11\x12\x14-\x1f\x7f]/u', $value) !== 0) {
                        $type = \SQLITE3_BLOB;
                    } else {
                        $type = \SQLITE3_TEXT;
                    }

                    $statement->bindValue(
                        \is_int($index) ? $index + 1 : $index,
                        $value,
                        $type
                    );
                }
                $result = @$statement->execute();
            }
        }

        if ($result === false) {
            return \React\Promise\reject(new \RuntimeException(
                $this->sqlite->lastErrorMsg()
            ));
        }

        assert($result instanceof \SQLite3Result);
        if ($result->numColumns() !== 0) {
            // Fetch all rows only if this result set has any columns.
            // INSERT/UPDATE/DELETE etc. do not return any columns, trying
            // to fetch the results here will issue the same query again.
            $rows = $columns = [];
            for ($i = 0, $n = $result->numColumns(); $i < $n; ++$i) {
                $columns[] = $result->columnName($i);
            }

            while (($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
                $rows[] = $row;
            }
        } else {
            $rows = $columns = null;
        }
        $result->finalize();

        $result = new Result();
        $result->changed = $this->sqlite->changes();
        $result->insertId = $this->sqlite->lastInsertRowID();
        $result->columns = $columns;
        $result->rows = $rows;

        return \React\Promise\resolve($result);
    }

    public function quit()
    {
        if ($this->closed) {
            return \React\Promise\reject(new \RuntimeException('Database closed'));
        }

        $this->close();

        return \React\Promise\resolve(null);
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->sqlite->close();

        $this->emit('close');
        $this->removeAllListeners();
    }
}

<?php

namespace Teamspk\Websockets\Test;

use Teamspk\Websockets\Contracts\ChannelManager;
use Teamspk\Websockets\Contracts\StatisticsCollector;
use Teamspk\Websockets\Contracts\StatisticsStore;
use Teamspk\Websockets\Facades\WebSocketRouter;
use Teamspk\Websockets\Helpers;
use Teamspk\Websockets\Server\Loggers\HttpLogger;
use Teamspk\Websockets\Server\Loggers\WebSocketsLogger;
use Teamspk\Websockets\ServerFactory;
use Clue\React\Buzz\Browser;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\BrowserKit\TestCase as Orchestra;
use Ratchet\Server\IoServer;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Output\BufferedOutput;

use function Clue\React\Block\await;

abstract class TestCase extends Orchestra
{
    const AWAIT_TIMEOUT = 5.0;

    /**
     * The test Browser.
     *
     * @var \Clue\React\Buzz\Browser
     */
    protected $browser;

    /**
     * The test WebSocket server.
     *
     * @var IoServer
     */
    protected $server;

    /**
     * A test Pusher server.
     *
     * @var \Teamspk\Websockets\Server\WebSocketHandler
     */
    protected $pusherServer;

    /**
     * The test Channel manager.
     *
     * @var \Teamspk\Websockets\Contracts\ChannelManager
     */
    protected $channelManager;

    /**
     * The test Channel manager.
     *
     * @var \Teamspk\Websockets\Contracts\StatisticsCollector
     */
    protected $statisticsCollector;

    /**
     * The test Channel manager.
     *
     * @var \Teamspk\Websockets\Contracts\StatisticsStore
     */
    protected $statisticsStore;

    /**
     * Get the loop instance.
     *
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * The Redis manager instance.
     *
     * @var \Illuminate\Redis\RedisManager
     */
    protected $redis;

    /**
     * Get the replication mode it is used for testing.
     *
     * @var string
     */
    protected $replicationMode = 'local';

    /**
     * {@inheritdoc}
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->loop = LoopFactory::create();

        $this->app->singleton(LoopInterface::class, function () {
            return $this->loop;
        });

        $this->browser = (new Browser($this->loop))
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false);

        $this->app->singleton(HttpLogger::class, function () {
            return (new HttpLogger(new BufferedOutput()))
                ->enable(false)
                ->verbose(false);
        });

        $this->app->singleton(WebSocketsLogger::class, function () {
            return (new WebSocketsLogger(new BufferedOutput()))
                ->enable(false)
                ->verbose(false);
        });

        $this->replicationMode = getenv('REPLICATION_MODE') ?: 'local';

        $this->resetDatabase();
        $this->loadLaravelMigrations(['--database' => 'sqlite']);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->withFactories(__DIR__.'/database/factories');

        $this->registerCustomPath();

        $this->registerPromiseResolver();

        $this->registerManagers();

        $this->registerStatisticsCollectors();

        $this->registerStatisticsStores();

        $this->pusherServer = $this->app->make(config('websockets.handlers.websocket'));

        if ($this->replicationMode === 'redis') {
            $this->registerRedis();
        }

        if (method_exists($this->channelManager, 'getPublishClient')) {
            $this->getPublishClient()->resetAssertions();
            $this->getSubscribeClient()->resetAssertions();
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->server) {
            $this->server->socket->close();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getPackageProviders($app)
    {
        return [
            \Teamspk\Websockets\WebSocketsServiceProvider::class,
            TestServiceProvider::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvironmentSetUp($app)
    {
        $this->replicationMode = getenv('REPLICATION_MODE') ?: 'local';

        $app['config']->set('database.default', 'sqlite');

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => __DIR__.'/database.sqlite',
            'prefix' => '',
        ]);

        $app['config']->set('broadcasting.connections.websockets', [
            'driver' => 'pusher',
            'key' => 'TestKey',
            'secret' => 'TestSecret',
            'app_id' => '1234',
            'options' => [
                'cluster' => 'mt1',
                'encrypted' => true,
                'host' => '127.0.0.1',
                'port' => 6001,
                'scheme' => 'http',
            ],
        ]);

        $app['config']->set('queue.default', 'async-redis');

        $app['config']->set('queue.connections.async-redis', [
            'driver' => 'async-redis',
            'connection' => env('WEBSOCKETS_REDIS_REPLICATION_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
        ]);

        $app['config']->set('auth.providers.users.model', Models\User::class);

        $app['config']->set('app.key', 'wslxrEFGWY6GfGhvN9L3wH3KSRJQQpBD');

        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ]);

        $app['config']->set(
            'websockets.replication.mode', $this->replicationMode
        );

        if ($this->replicationMode === 'redis') {
            $app['config']->set('broadcasting.default', 'pusher');
            $app['config']->set('cache.default', 'redis');
        }

        $app['config']->set('websockets.apps', [
            [
                'name' => 'Test App',
                'id' => '1234',
                'key' => 'TestKey',
                'secret' => 'TestSecret',
                'host' => 'localhost',
                'capacity' => null,
                'enable_client_messages' => false,
                'enable_statistics' => true,
                'allowed_origins' => [],
            ],
            [
                'name' => 'Origin Test App',
                'id' => '1234',
                'key' => 'TestOrigin',
                'secret' => 'TestSecret',
                'capacity' => null,
                'enable_client_messages' => false,
                'enable_statistics' => true,
                'allowed_origins' => [
                    'test.origin.com',
                ],
            ],
            [
                'name' => 'Test App 2',
                'id' => '12345',
                'key' => 'TestKey2',
                'secret' => 'TestSecret2',
                'host' => 'localhost',
                'capacity' => null,
                'enable_client_messages' => false,
                'enable_statistics' => true,
                'allowed_origins' => [],
            ],
        ]);

        $app['config']->set('websockets.replication.modes', [
            'local' => [
                'channel_manager' => \Teamspk\Websockets\ChannelManagers\LocalChannelManager::class,
                'collector' => \Teamspk\Websockets\Statistics\Collectors\MemoryCollector::class,
            ],
            'redis' => [
                'channel_manager' => \Teamspk\Websockets\ChannelManagers\RedisChannelManager::class,
                'connection' => 'default',
                'collector' => \Teamspk\Websockets\Statistics\Collectors\RedisCollector::class,
            ],
        ]);
    }

    /**
     * Register custom paths.
     *
     * @return void
     */
    protected function registerCustomPath()
    {
        WebSocketRouter::addCustomRoute('GET', '/test', Handlers\TestHandler::class);
        WebSocketRouter::addCustomRoute('POST', '/test', Handlers\TestHandler::class);
        WebSocketRouter::addCustomRoute('PUT', '/test', Handlers\TestHandler::class);
        WebSocketRouter::addCustomRoute('PATCH', '/test', Handlers\TestHandler::class);
        WebSocketRouter::addCustomRoute('DELETE', '/test', Handlers\TestHandler::class);
    }

    /**
     * Register the test promise resolver.
     *
     * @return void
     */
    protected function registerPromiseResolver()
    {
        Helpers::$loop = $this->loop;

        $this->app['config']->set(
            'websockets.promise_resolver',
            \Teamspk\Websockets\Test\Mocks\PromiseResolver::class
        );
    }

    /**
     * Register the managers that are not resolved
     * by the package service provider.
     *
     * @return void
     */
    protected function registerManagers()
    {
        $this->app->singleton(ChannelManager::class, function () {
            $mode = config('websockets.replication.mode', $this->replicationMode);

            $class = config("websockets.replication.modes.{$mode}.channel_manager");

            return new $class($this->loop, Mocks\RedisFactory::class);
        });

        $this->channelManager = $this->app->make(ChannelManager::class);
    }

    protected function await(PromiseInterface $promise, LoopInterface $loop = null, $timeout = null)
    {
        return await($promise, $loop ?? $this->loop, $timeout ?? static::AWAIT_TIMEOUT);
    }

    /**
     * Unregister the managers for testing purposes.
     *
     * @return void
     */
    protected function unregisterManagers()
    {
        $this->app->offsetUnset(ChannelManager::class);
    }

    /**
     * Register the statistics collectors.
     *
     * @return void
     */
    protected function registerStatisticsCollectors()
    {
        $this->statisticsCollector = $this->app->make(StatisticsCollector::class);

        $this->artisan('websockets:flush');
    }

    /**
     * Register the statistics stores that are
     * not resolved by the package service provider.
     *
     * @return void
     */
    protected function registerStatisticsStores()
    {
        $this->app->singleton(StatisticsStore::class, function () {
            $class = config('websockets.statistics.store');

            return new $class;
        });

        $this->statisticsStore = $this->app->make(StatisticsStore::class);
    }

    /**
     * Register the Redis components for testing.
     *
     * @return void
     */
    protected function registerRedis()
    {
        $this->redis = Redis::connection();

        $this->redis->flushdb();
    }

    /**
     * Get the websocket connection for a specific key.
     *
     * @param  string  $appKey
     * @param  array  $headers
     * @return Mocks\Connection
     */
    protected function newConnection(string $appKey = 'TestKey', array $headers = [])
    {
        $connection = new Mocks\Connection;

        $connection->lastPongedAt = now();
        $connection->httpRequest = new Request('GET', "/?appKey={$appKey}", $headers);

        return $connection;
    }

    protected function joinWebSocketServer(array $channelsToJoin = [], string $appKey = 'TestKey', array $headers = [])
    {
        $promise = new Deferred();

        \Ratchet\Client\connect("ws://localhost:4000/app/{$appKey}", [], [], $this->loop)->then(function ($conn) use ($promise) {
            $conn->on('message', function ($msg) use ($promise) {
                $promise->resolve($msg);
            });
        });

        return $promise->promise();
    }

    /**
     * Get a connected websocket connection.
     *
     * @param  array  $channelsToJoin
     * @param  string  $appKey
     * @param  array  $headers
     * @return Mocks\Connection
     */
    protected function newActiveConnection(array $channelsToJoin = [], string $appKey = 'TestKey', array $headers = [])
    {
        $connection = $this->newConnection($appKey, $headers);

        $this->pusherServer->onOpen($connection);

        foreach ($channelsToJoin as $channel) {
            $message = new Mocks\Message([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => $channel,
                ],
            ]);

            $this->pusherServer->onMessage($connection, $message);
        }

        return $connection;
    }

    /**
     * Join a presence channel.
     *
     * @param  string  $channel
     * @param  array  $user
     * @param  string  $appKey
     * @param  array  $headers
     * @return Mocks\Connection
     */
    protected function newPresenceConnection($channel, array $user = [], string $appKey = 'TestKey', array $headers = [])
    {
        $connection = $this->newConnection($appKey, $headers);

        $this->pusherServer->onOpen($connection);

        $user = $user ?: [
            'user_id' => 1,
            'user_info' => ['name' => 'Rick'],
        ];

        $encodedUser = json_encode($user);

        $message = new Mocks\SignedMessage([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => $channel,
                'channel_data' => $encodedUser,
            ],
        ], $connection, $channel, $encodedUser);

        $this->pusherServer->onMessage($connection, $message);

        return $connection;
    }

    /**
     * Join a private channel.
     *
     * @param  string  $channel
     * @param  string  $appKey
     * @param  array  $headers
     * @return Mocks\Connection
     */
    protected function newPrivateConnection($channel, string $appKey = 'TestKey', array $headers = [])
    {
        $connection = $this->newConnection($appKey, $headers);

        $this->pusherServer->onOpen($connection);

        $message = new Mocks\SignedMessage([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => $channel,
            ],
        ], $connection, $channel);

        $this->pusherServer->onMessage($connection, $message);

        return $connection;
    }

    /**
     * Get the subscribed client for the replication.
     *
     * @return Mocks\LazyClient
     */
    protected function getSubscribeClient()
    {
        return $this->channelManager->getSubscribeClient();
    }

    /**
     * Get the publish client for the replication.
     *
     * @return Mocks\LazyClient
     */
    protected function getPublishClient()
    {
        return $this->channelManager->getPublishClient();
    }

    /**
     * Reset the database.
     *
     * @return void
     */
    protected function resetDatabase()
    {
        file_put_contents(__DIR__.'/database.sqlite', null);
    }

    protected function runOnlyOnRedisReplication()
    {
        if ($this->replicationMode !== 'redis') {
            $this->markTestSkipped('Skipped test because the replication mode is not set to Redis.');
        }
    }

    protected function runOnlyOnLocalReplication()
    {
        if ($this->replicationMode !== 'local') {
            $this->markTestSkipped('Skipped test because the replication mode is not set to Local.');
        }
    }

    protected function skipOnRedisReplication()
    {
        if ($this->replicationMode === 'redis') {
            $this->markTestSkipped('Skipped test because the replication mode is Redis.');
        }
    }

    protected function skipOnLocalReplication()
    {
        if ($this->replicationMode === 'local') {
            $this->markTestSkipped('Skipped test because the replication mode is Local.');
        }
    }

    protected function startServer()
    {
        $server = new ServerFactory('0.0.0.0', 4000);

        WebSocketRouter::registerRoutes();

        $this->server = $server
            ->setLoop($this->loop)
            ->withRoutes(WebSocketRouter::getRoutes())
            ->setConsoleOutput(new BufferedOutput())
            ->createServer();
    }
}

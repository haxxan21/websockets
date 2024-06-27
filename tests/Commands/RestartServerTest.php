<?php

namespace Codespace\Websockets\Test\Commands;

use Codespace\Websockets\Test\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\InteractsWithTime;

class RestartServerTest extends TestCase
{
    use InteractsWithTime;

    public function test_it_can_broadcast_restart_signal()
    {
        $start = $this->currentTime();

        $this->artisan('websockets:restart');

        $this->assertGreaterThanOrEqual(
            $start, Cache::get('beyondcode:websockets:restart', 0)
        );
    }
}

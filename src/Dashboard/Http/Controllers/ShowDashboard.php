<?php

namespace Codespace\Websockets\Dashboard\Http\Controllers;

use Codespace\Websockets\Contracts\AppManager;
use Codespace\Websockets\DashboardLogger;
use Illuminate\Http\Request;
use React\EventLoop\LoopInterface;

use function Clue\React\Block\await;

class ShowDashboard
{
    /**
     * Show the dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Codespace\Websockets\Contracts\AppManager  $apps
     * @return void
     */
    public function __invoke(Request $request, AppManager $apps)
    {
        return view('websockets::dashboard', [
            'apps' => await($apps->all(), app(LoopInterface::class), 2.0),
            'port' => config('websockets.dashboard.port', 6001),
            'channels' => DashboardLogger::$channels,
            'logPrefix' => DashboardLogger::LOG_CHANNEL_PREFIX,
            'refreshInterval' => config('websockets.statistics.interval_in_seconds'),
        ]);
    }
}

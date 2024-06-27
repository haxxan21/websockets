<?php

namespace Codespace\Websockets\Dashboard\Http\Controllers;

use Codespace\Websockets\Contracts\AppManager;
use Illuminate\Http\Request;
use React\EventLoop\LoopInterface;

use function Clue\React\Block\await;

class ShowApps
{
    /**
     * Show the configured apps.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Codespace\Websockets\Contracts\AppManager  $apps
     * @return void
     */
    public function __invoke(Request $request, AppManager $apps)
    {
        return view('websockets::apps', [
            'apps' => await($apps->all(), app(LoopInterface::class), 2.0),
            'port' => config('websockets.dashboard.port', 6001),
        ]);
    }
}

<?php

namespace Teamspk\Websockets\Facades;

use Teamspk\Websockets\Contracts\StatisticsStore as StatisticsStoreInterface;
use Illuminate\Support\Facades\Facade;

class StatisticsStore extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return StatisticsStoreInterface::class;
    }
}

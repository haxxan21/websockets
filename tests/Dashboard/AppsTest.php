<?php

namespace Teamspk\Websockets\Test\Dashboard;

use Teamspk\Websockets\Apps\SQLiteAppManager;
use Teamspk\Websockets\Test\Models\User;
use Teamspk\Websockets\Test\TestCase;

class AppsTest extends TestCase
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('websockets.managers.app', SQLiteAppManager::class);
    }

    public function test_can_list_all_apps()
    {
        $this->actingAs(factory(User::class)->create())
            ->get(route('laravel-websockets.apps'))
            ->assertViewHas('apps', []);
    }

    public function test_can_create_app()
    {
        $this->actingAs(factory(User::class)->create())
            ->post(route('laravel-websockets.apps.store', [
                'name' => 'New App',
            ]));

        $this->actingAs(factory(User::class)->create())
            ->get(route('laravel-websockets.apps'))
            ->assertViewHas('apps', function ($apps) {
                return count($apps) === 1 && $apps[0]['name'] === 'New App';
            });
    }
}

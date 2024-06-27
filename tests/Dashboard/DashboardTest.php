<?php

namespace Codespace\Websockets\Test\Dashboard;

use Codespace\Websockets\Test\Models\User;
use Codespace\Websockets\Test\TestCase;

class DashboardTest extends TestCase
{
    public function test_cant_see_dashboard_without_authorization()
    {
        $this->get(route('laravel-websockets.dashboard'))
            ->assertResponseStatus(403);
    }

    public function test_can_see_dashboard()
    {
        $this->actingAs(factory(User::class)->create())
            ->get(route('laravel-websockets.dashboard'))
            ->assertResponseOk();
    }
}

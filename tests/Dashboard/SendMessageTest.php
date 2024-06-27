<?php

namespace Codespace\Websockets\Test\Dashboard;

use Codespace\Websockets\Test\Models\User;
use Codespace\Websockets\Test\TestCase;

class SendMessageTest extends TestCase
{
    public function test_can_send_message()
    {
        $this->actingAs(factory(User::class)->create())
            ->json('POST', route('laravel-websockets.event'), [
                'appId' => '1234',
                'key' => 'TestKey',
                'secret' => 'TestSecret',
                'channel' => 'test-channel',
                'event' => 'some-event',
                'data' => json_encode(['data' => 'yes']),
            ])
            ->seeJson([
                'ok' => false,
            ]);

        $this->markTestIncomplete(
            'Broadcasting is not possible to be tested without receiving a Pusher error.'
        );
    }

    public function test_cant_send_message_for_invalid_app()
    {
        $this->actingAs(factory(User::class)->create())
            ->json('POST', route('laravel-websockets.event'), [
                'appId' => '9999',
                'key' => 'TestKey',
                'secret' => 'TestSecret',
                'channel' => 'test-channel',
                'event' => 'some-event',
                'data' => json_encode(['data' => 'yes']),
            ])
            ->assertResponseStatus(422);
    }
}

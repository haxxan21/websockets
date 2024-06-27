<?php

namespace Codespace\Websockets\Server\Exceptions;

class InvalidSignature extends WebSocketException
{
    /**
     * Initialize the instance.
     *
     * @see    https://pusher.com/docs/pusher_protocol#error-codes
     *
     * @return void
     */
    public function __construct()
    {
        $this->trigger('Invalid Signature', 4009);
    }
}

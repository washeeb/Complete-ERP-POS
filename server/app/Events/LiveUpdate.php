<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * One small message to the tills, back office or storefront. Sent by the relay after the change has
 * committed (never from inside a transaction), so nobody is told about something that rolled back.
 * Payloads carry ids and public figures only; clients re-read details through their own API.
 */
final class LiveUpdate implements ShouldBroadcastNow
{
    public function __construct(public string $channel, public bool $private, public string $name, public array $data) {}

    public function broadcastOn(): array
    {
        return [$this->private ? new PrivateChannel($this->channel) : new Channel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}

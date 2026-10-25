<?php

namespace App\Events;

use App\Models\Call;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An incoming call stopped ringing (declined, or rang out), so every open page hides it.
 */
class RingingStopped implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public User $user, public Call $call) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(NoticeSent::channelFor($this->user))];
    }

    public function broadcastAs(): string
    {
        return 'ring-stopped';
    }

    /** @return array{call_id: int} */
    public function broadcastWith(): array
    {
        return ['call_id' => $this->call->id];
    }
}

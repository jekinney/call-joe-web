<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A contact is writing a reply ("…" in the thread).
 */
class ContactTyping implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public User $user, public int $userContactId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(NoticeSent::channelFor($this->user))];
    }

    public function broadcastAs(): string
    {
        return 'typing';
    }

    /** @return array{user_contact_id: int} */
    public function broadcastWith(): array
    {
        return ['user_contact_id' => $this->userContactId];
    }
}

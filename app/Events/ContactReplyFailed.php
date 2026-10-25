<?php

namespace App\Events;

use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A contact couldn't answer a text (the AI provider failed); the page offers to try again.
 */
class ContactReplyFailed implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public User $user, public Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(NoticeSent::channelFor($this->user))];
    }

    public function broadcastAs(): string
    {
        return 'reply-failed';
    }

    /** @return array{id: int, user_contact_id: int} */
    public function broadcastWith(): array
    {
        return ['id' => $this->message->id, 'user_contact_id' => $this->message->user_contact_id];
    }
}

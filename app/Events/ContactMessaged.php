<?php

namespace App\Events;

use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A text from a contact, delivered live to the user's open page. Sent from the reply job (already in the
 * background), so it goes out right away.
 */
class ContactMessaged implements ShouldBroadcastNow
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
        return 'message';
    }

    /** @return array{id: int, user_contact_id: int, body: string, at: string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'user_contact_id' => $this->message->user_contact_id,
            'body' => $this->message->body,
            'at' => $this->message->created_at->toIso8601String(),
        ];
    }
}

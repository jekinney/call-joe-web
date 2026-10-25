<?php

namespace App\Events;

use App\Models\Call;
use App\Models\User;
use App\Models\UserContact;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A contact is calling: the user's open page rings (resources/js/ringing.js) with Answer and Decline.
 */
class ContactCalling implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public User $user, public Call $call, public UserContact $entry) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(NoticeSent::channelFor($this->user))];
    }

    public function broadcastAs(): string
    {
        return 'ringing';
    }

    /** @return array{call_id: int, user_contact_id: int, name: string, avatar: string|null, answer_url: string, decline_url: string} */
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->call->id,
            'user_contact_id' => $this->entry->id,
            'name' => $this->entry->displayName(),
            'avatar' => $this->entry->contact->avatarUrl(),
            'answer_url' => route('app.call', ['contact' => $this->entry->contact, 'answer' => $this->call->id]),
            'decline_url' => route('app.calls.decline', $this->call),
        ];
    }
}

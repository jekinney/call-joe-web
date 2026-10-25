<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A short notice that pops up on the user's open page, live over Reverb ("Elena's schedule changed", "Tidied up").
 * Sent from the queue, on the user's private channel.
 */
class NoticeSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public User $user, public string $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelFor($this->user))];
    }

    public function broadcastAs(): string
    {
        return 'notice';
    }

    /**
     * @return array{message: string, at: string}
     */
    public function broadcastWith(): array
    {
        return ['message' => $this->message, 'at' => now()->toIso8601String()];
    }

    /** Each user's private channel; only they can listen (routes/channels.php). */
    public static function channelFor(User $user): string
    {
        return 'App.Models.User.'.$user->id;
    }
}

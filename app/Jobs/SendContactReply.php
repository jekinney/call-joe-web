<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Texting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes a contact's reply to a user's text, in the background. Failures are handled inside (the user sees
 * "couldn't answer, try again"), so the job itself never retries and never double-replies.
 */
class SendContactReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(Texting $texting): void
    {
        $message = Message::find($this->messageId);
        if ($message) {
            $texting->reply($message);
        }
    }
}

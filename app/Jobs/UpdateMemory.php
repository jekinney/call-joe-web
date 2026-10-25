<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\UserContact;
use App\Services\MemoryWriter;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Updates a contact's notes once the texting has gone quiet for QUIET_MINUTES. Only one waits per contact at a
 * time; if texts are still coming when it runs, it waits again instead of updating mid-conversation.
 */
class UpdateMemory implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const QUIET_MINUTES = 20;

    /** Each wait counts as a try; a long conversation can wait many times. */
    public int $tries = 50;

    public function __construct(public int $userContactId) {}

    public function uniqueId(): string
    {
        return (string) $this->userContactId;
    }

    /** Waits for a quiet spell after the latest text. */
    public static function afterQuiet(int $userContactId): void
    {
        self::dispatch($userContactId)->delay(now()->addMinutes(self::QUIET_MINUTES));
    }

    public function handle(MemoryWriter $writer): void
    {
        $entry = UserContact::withTrashed()->find($this->userContactId);
        if (! $entry) {
            return;
        }

        $latest = Message::where('user_contact_id', $entry->id)->max('created_at');
        if ($latest && now()->subMinutes(self::QUIET_MINUTES)->lt($latest)) {
            $this->release(self::QUIET_MINUTES * 60); // still talking: check again later

            return;
        }

        $writer->update($entry);
    }
}

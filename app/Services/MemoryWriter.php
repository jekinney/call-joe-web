<?php

namespace App\Services;

use App\Enums\LlmPurpose;
use App\Exceptions\LlmException;
use App\Models\Memory;
use App\Models\MemoryVersion;
use App\Models\Message;
use App\Models\UserContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps each contact's notes about their user: rewritten from the newest texts after a conversation goes quiet,
 * one set per user_contact (contacts never see each other's notes), every change versioned. The user can make a
 * contact forget a note, or forget everything.
 */
class MemoryWriter
{
    /** The notes never grow past this many characters. */
    public const MAX_CHARS = 4000;

    /** At most this many new texts per update (older ones are already in the notes, or too old to matter). */
    public const MAX_NEW_MESSAGES = 200;

    public function __construct(private LlmGateway $gateway) {}

    public function memoryFor(UserContact $entry): Memory
    {
        return Memory::firstOrCreate(['user_contact_id' => $entry->id]);
    }

    public function notesFor(UserContact $entry): string
    {
        return (string) $entry->memory?->notes_md;
    }

    /**
     * Rewrites the notes from texts newer than the last update. Returns whether they changed.
     */
    public function update(UserContact $entry): bool
    {
        $entry->loadMissing(['user', 'contact']);
        $memory = $this->memoryFor($entry);

        $new = $entry->messages()->where('id', '>', $memory->last_message_id ?? 0)->orderBy('id')->limit(self::MAX_NEW_MESSAGES)->get();
        if ($new->isEmpty()) {
            return false;
        }

        $buddy = $entry->user->preferredName();
        $contact = $entry->contact->firstName();
        $transcript = $new->map(fn (Message $m) => ($m->fromUser() ? $buddy : $contact).': '.$m->body)->implode("\n");
        $forgotten = $memory->forgotten ? "\n\n{$buddy} asked you to forget these; never write them down again:\n- ".implode("\n- ", $memory->forgotten) : '';

        try {
            $answer = $this->gateway->chat(LlmPurpose::Memory, [
                ['role' => 'system', 'content' => "You keep {$contact}'s private notes about {$buddy}, so {$contact} remembers them next time. Write only what {$buddy} shared or what happened between you: people in their life, work, plans, likes, worries, important dates (as real dates, today is ".now($entry->user->timezone)->toFormattedDateString().'). Keep everything still true from the old notes, update what changed, drop small talk, and never invent anything. Reply with only the notes, as short bullet points starting with "- ", under 350 words.'],
                ['role' => 'user', 'content' => "Old notes:\n".($memory->notes_md !== '' ? $memory->notes_md : '(none yet)')."\n\nNew texts:\n{$transcript}{$forgotten}"],
            ], $entry->user, $entry->contact);
        } catch (LlmException $e) {
            Log::warning('Memory update failed; keeping the old notes.', ['user_contact_id' => $entry->id, 'error' => $e->getMessage()]);

            return false;
        }

        $this->save($memory, $this->clean($answer->text), 'update', (int) $new->last()->id);

        return true;
    }

    /**
     * Forgets one note (by its position in Memory::lines()), and keeps it from being written back.
     */
    public function forget(UserContact $entry, int $index): void
    {
        $memory = $this->memoryFor($entry);
        $lines = $memory->lines();
        if (! isset($lines[$index])) {
            return;
        }

        $memory->forgotten = [...($memory->forgotten ?? []), $lines[$index]];
        unset($lines[$index]);
        $this->save($memory, $this->asBullets($lines), 'forget', $memory->last_message_id);
    }

    /**
     * Forgets everything: the notes are emptied, and texts so far won't be learned again.
     */
    public function reset(UserContact $entry): void
    {
        $memory = $this->memoryFor($entry);
        $this->save($memory, '', 'reset', $entry->messages()->max('id') ?? $memory->last_message_id);
    }

    private function save(Memory $memory, string $notes, string $reason, ?int $lastMessageId): void
    {
        DB::transaction(function () use ($memory, $notes, $reason, $lastMessageId) {
            $memory->fill(['notes_md' => $notes, 'last_message_id' => $lastMessageId, 'version' => $memory->version + 1])->save();
            MemoryVersion::create(['memory_id' => $memory->id, 'version' => $memory->version, 'notes_md' => $notes, 'reason' => $reason]);
        });
    }

    /** Bullet lines only, within the length limit. */
    private function clean(string $text): string
    {
        $lines = (new Memory(['notes_md' => $text]))->lines();
        $kept = [];
        $length = 0;
        foreach ($lines as $line) {
            $length += mb_strlen($line) + 3;
            if ($length > self::MAX_CHARS) {
                break;
            }
            $kept[] = $line;
        }

        return $this->asBullets($kept);
    }

    /** @param  array<int, string>  $lines */
    private function asBullets(array $lines): string
    {
        return implode("\n", array_map(fn (string $line) => "- {$line}", $lines));
    }
}

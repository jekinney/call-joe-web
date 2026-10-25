<?php

namespace App\Services;

use App\Enums\LlmPurpose;
use App\Enums\Metric;
use App\Events\ContactMessaged;
use App\Events\ContactReplyFailed;
use App\Events\ContactTyping;
use App\Exceptions\LlmException;
use App\Jobs\SendContactReply;
use App\Jobs\UpdateMemory;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserContact;
use DomainException;

/**
 * Texting with a contact. A user's text counts against the plan's daily texts; the contact's reply is written in the
 * background (App\Jobs\SendContactReply) with the conversation's frozen prompt and recent history, split into a few
 * bubbles like real texts, and delivered live over Reverb.
 */
class Texting
{
    /** A conversation (and its frozen prompt) ends after this long without a text. */
    public const CONVERSATION_GAP_HOURS = 6;

    /** How many recent texts the contact sees when replying. */
    public const HISTORY = 30;

    /** At most this many bubbles per reply. */
    public const MAX_BUBBLES = 4;

    public const MAX_LENGTH = 2000;

    public function __construct(
        private Entitlements $entitlements,
        private PromptBuilder $prompts,
        private LlmGateway $gateway,
        private MemoryWriter $memory,
    ) {}

    /**
     * Sends the user's text and asks the contact to reply.
     *
     * @throws DomainException when it's empty, too long, or today's texts are used up.
     */
    public function send(User $user, UserContact $entry, string $body): Message
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw new DomainException('A text needs 1 to '.self::MAX_LENGTH.' characters.');
        }

        if (! $this->entitlements->consume($user, Metric::TextMessages)) {
            $limit = $this->entitlements->limit($user, Metric::TextMessages);
            $resets = $this->entitlements->resetsAt($user, Metric::TextMessages)->format('g:i A');

            throw new DomainException("You've used today's {$limit} texts. They reset at {$resets}.");
        }

        $message = $entry->messages()->create(['sender' => Message::FROM_USER, 'body' => $body]);
        SendContactReply::dispatch($message->id);

        return $message;
    }

    /**
     * Asks again for a reply that failed (not counted as another text).
     */
    public function retry(Message $message): void
    {
        $message->update(['meta' => null]);
        SendContactReply::dispatch($message->id);
    }

    /**
     * Writes and delivers the contact's reply to this text. Returns the new bubbles (none if the AI failed).
     *
     * @return list<Message>
     */
    public function reply(Message $trigger): array
    {
        $entry = $trigger->userContact()->with(['user', 'contact', 'relationship'])->firstOrFail();
        $user = $entry->user;
        $conversation = $this->conversationFor($entry);
        $trigger->update(['conversation_id' => $conversation->id]);

        ContactTyping::dispatch($user, $entry->id);

        try {
            $answer = $this->gateway->chat(LlmPurpose::Chat, $this->messagesFor($conversation, $entry, $trigger), $user, $entry->contact);
        } catch (LlmException) {
            $trigger->update(['meta' => ['reply_failed' => true]]);
            ContactReplyFailed::dispatch($user, $trigger);

            return [];
        }

        $bubbles = [];
        foreach ($this->bubbles($answer->text) as $text) {
            $bubbles[] = $message = $entry->messages()->create([
                'conversation_id' => $conversation->id, 'sender' => Message::FROM_CONTACT, 'body' => $text,
            ]);
            ContactMessaged::dispatch($user, $message);
        }
        $conversation->update(['last_message_at' => now()]);
        UpdateMemory::afterQuiet($entry->id);

        return $bubbles;
    }

    /**
     * The conversation still going (texted within the gap), or a new one with a freshly built prompt.
     */
    public function conversationFor(UserContact $entry): Conversation
    {
        $current = $entry->conversations()->where('last_message_at', '>=', now()->subHours(self::CONVERSATION_GAP_HOURS))->latest('id')->first();
        if ($current) {
            return $current;
        }

        $snapshot = $this->prompts->build($entry, $this->memory->notesFor($entry));

        return $entry->conversations()->create([
            'prompt_text' => $snapshot->text, 'prompt_hash' => $snapshot->hash, 'started_at' => now(), 'last_message_at' => now(),
        ]);
    }

    /**
     * A reply split on blank lines into a few bubbles (anything past the limit stays in the last one).
     *
     * @return list<string>
     */
    public function bubbles(string $text): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', trim($text)) ?: []), fn ($p) => $p !== ''));
        if (count($parts) > self::MAX_BUBBLES) {
            $parts = [...array_slice($parts, 0, self::MAX_BUBBLES - 1), implode("\n\n", array_slice($parts, self::MAX_BUBBLES - 1))];
        }

        return $parts ?: ['…'];
    }

    /**
     * The frozen prompt, then recent texts up to this one, as user/assistant turns.
     *
     * @return list<array{role: string, content: string}>
     */
    private function messagesFor(Conversation $conversation, UserContact $entry, Message $trigger): array
    {
        $history = $entry->messages()->where('id', '<=', $trigger->id)->orderByDesc('id')->limit(self::HISTORY)->get()->reverse();

        return [
            ['role' => 'system', 'content' => $conversation->prompt_text],
            ...$history->map(fn (Message $m) => ['role' => $m->fromUser() ? 'user' : 'assistant', 'content' => $m->body])->values()->all(),
        ];
    }
}

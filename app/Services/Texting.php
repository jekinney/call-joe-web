<?php

namespace App\Services;

use App\Enums\LlmPurpose;
use App\Enums\Metric;
use App\Enums\ModerationCategory;
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
use Illuminate\Support\Facades\RateLimiter;

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

    /** Most texts one user can send in a minute. */
    public const BURST_PER_MINUTE = 20;

    public function __construct(
        private Entitlements $entitlements,
        private PromptBuilder $prompts,
        private LlmGateway $gateway,
        private MemoryWriter $memory,
        private Moderator $moderator,
        private Pictures $pictures,
    ) {}

    /** What a contact says instead of a reply the safety check stopped. */
    public const STOPPED_REPLY = "I'm not going there. Let's talk about something else.";

    /**
     * Sends the user's text and asks the contact to reply. The safety check comes first: a blocked text isn't sent
     * (or counted), and a text that suggests someone may be in danger is marked so the reply follows the crisis path.
     *
     * @throws DomainException when it's empty, too long, blocked, or today's texts are used up.
     */
    public function send(User $user, UserContact $entry, string $body): Message
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw new DomainException('A text needs 1 to '.self::MAX_LENGTH.' characters.');
        }

        // A burst limit on top of the plan's daily texts, so a script can't run up AI cost in seconds.
        if (RateLimiter::tooManyAttempts("text:{$user->id}", self::BURST_PER_MINUTE)) {
            throw new DomainException('Slow down a little. Try again in a minute.');
        }
        RateLimiter::hit("text:{$user->id}", 60);

        $verdict = $this->moderator->checkUserText($user, $body);
        if ($verdict->blocked) {
            throw new DomainException("That's something no one here will talk about. Nothing involving anyone under 18, ever.");
        }

        if (! $this->entitlements->consume($user, Metric::TextMessages)) {
            $limit = $this->entitlements->limit($user, Metric::TextMessages);
            $resets = $this->entitlements->resetsAt($user, Metric::TextMessages)->format('g:i A');

            throw new DomainException("You've used today's {$limit} texts. They reset at {$resets}.");
        }

        $message = $entry->messages()->create([
            'sender' => Message::FROM_USER, 'body' => $body, 'meta' => $verdict->isCrisis() ? ['crisis' => $verdict->category?->value] : null,
        ]);
        $verdict->event?->about($message);
        $entry->markHeard();
        SendContactReply::dispatch($message->id);

        return $message;
    }

    /**
     * Asks again for a reply that failed (not counted as another text).
     */
    public function retry(Message $message): void
    {
        $meta = $message->meta ?? [];
        unset($meta['reply_failed']);
        $message->update(['meta' => $meta ?: null]);
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
            $trigger->update(['meta' => [...($trigger->meta ?? []), 'reply_failed' => true]]);
            ContactReplyFailed::dispatch($user, $trigger);

            return [];
        }

        if ($entry->relief_stage !== null) {
            $entry->clearRelief(); // the relieved welcome is in this reply
        }

        $reply = $this->moderator->checkReply($user, $answer->text)->blocked ? self::STOPPED_REPLY : $answer->text;
        [$reply, $photo] = $this->pictures->extract($reply); // a [photo: ...] follows as a picture

        $bubbles = [];
        foreach ($reply === '' && $photo ? [] : $this->bubbles($reply) as $text) {
            $bubbles[] = $message = $entry->messages()->create([
                'conversation_id' => $conversation->id, 'sender' => Message::FROM_CONTACT, 'body' => $text,
            ]);
            ContactMessaged::dispatch($user, $message);
        }
        if ($photo) {
            $this->pictures->request($entry, $photo);
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
     * The frozen prompt, then recent texts up to this one in time order (texts written for a backlog keep their
     * earlier times), as user/assistant turns. Notes go at the end, so the cached prompt stays the same: a relieved
     * welcome after a worried stretch, and the crisis path when the text suggests someone may be in danger.
     *
     * @return list<array{role: string, content: string}>
     */
    private function messagesFor(Conversation $conversation, UserContact $entry, Message $trigger): array
    {
        $history = $entry->messages()->where('created_at', '<=', $trigger->created_at)->where('id', '!=', $trigger->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::HISTORY - 1)->get()->reverse()->push($trigger);
        $crisis = ModerationCategory::tryFrom((string) ($trigger->meta['crisis'] ?? ''));
        $notes = array_filter([$entry->reliefNote(), $crisis ? Moderator::crisisNote($crisis) : null]);

        return [
            ['role' => 'system', 'content' => $conversation->prompt_text],
            ...$history->map(fn (Message $m) => ['role' => $m->fromUser() ? 'user' : 'assistant', 'content' => $m->body])->values()->all(),
            ...array_map(fn (string $note) => ['role' => 'system', 'content' => $note], array_values($notes)),
        ];
    }
}

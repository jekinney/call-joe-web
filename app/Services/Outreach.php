<?php

namespace App\Services;

use App\Enums\Feature;
use App\Enums\LlmPurpose;
use App\Events\ContactCalling;
use App\Events\ContactMessaged;
use App\Events\RingingStopped;
use App\Exceptions\LlmException;
use App\Jobs\ExpireRinging;
use App\Jobs\WriteOutreach;
use App\Models\Call;
use App\Models\Message;
use App\Models\OutreachEvent;
use App\Models\Relationship;
use App\Models\User;
use App\Models\UserContact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Contacts reaching out on their own, on the server, whether or not the user is online. Three steps:
 *
 * 1. plan(): the longer the user goes without texting or calling a contact, the more that contact worries, following
 *    their relationship's worry profile (a mom notices within a day, a coworker after a week), capped by the plan
 *    (worry_max_stage). Each stage has its own texts and calls per day. Planning only records *that* they reach out
 *    (an outreach_events row), never the words.
 * 2. deliverDue(): calls ring live over Reverb if the user is online, or become missed calls if not (no AI needed).
 *    Texts and voicemails are written only while the user is online, so nobody pays for words never read.
 * 3. write(): one reach-out, written with the contact's prompt and the thread up to that moment, saved with its
 *    original time. A backlog is written in order, each one knowing about the ones before it.
 *
 * Guardrails: contacts wait for a reply (MAX_UNANSWERED_TEXTS, one more per worry stage), stop entirely after the
 * plan's away_outreach_days without the user opening the app, never write between 9 PM and 8 AM the user's time,
 * never worry while the user is in away mode or has turned worry off for them, and never use guilt or pretend
 * emergencies (crossesLine).
 */
class Outreach
{
    /** Unanswered texts a contact sends before going quiet until the user answers (one more per worry stage). */
    public const MAX_UNANSWERED_TEXTS = 2;

    /** How long an incoming call rings before it becomes a missed call. */
    public const RING_SECONDS = 30;

    /** A call delivered later than this after its time (the user just came online) is a missed call, not a ring. */
    public const RING_LATE_MINUTES = 5;

    /** Contacts don't reach out from QUIET_FROM until QUIET_UNTIL, in the user's timezone. */
    public const QUIET_FROM = 21;

    public const QUIET_UNTIL = 8;

    /** Things a caring person doesn't text: guilt, pressure, and pretend emergencies. */
    private const OVER_THE_LINE = [
        '/\babandon/i',
        '/\bforg[eo]t (about )?me\b/i',
        "/\bdon'?t (even )?(care|matter)\b/i",
        "/\bi guess i'?m not\b/i",
        '/\bafter (all|everything) i\b/i',
        "/\byou'?ll regret\b/i",
        "/\bif you don'?t (answer|reply|call|text)\b/i",
        '/\bpolice\b|\bcops\b|\b911\b|\bhospital|\bmissing person/i',
    ];

    public function __construct(
        private Entitlements $entitlements,
        private Presence $presence,
        private Texting $texting,
        private LlmGateway $gateway,
        private Moderator $moderator,
    ) {}

    /** Plans for every contact of everyone who has used the app. Returns how many reach-outs were planned. */
    public function planAll(): int
    {
        $planned = 0;
        UserContact::query()->where('is_active', true)->whereHas('user', fn ($q) => $q->whereNotNull('last_seen_at')->whereNull('suspended_at'))
            ->with(['user', 'contact', 'relationship'])
            ->chunkById(200, function (Collection $entries) use (&$planned) {
                foreach ($entries as $entry) {
                    $planned += count($this->plan($entry));
                }
            });

        return $planned;
    }

    /**
     * Updates this contact's worry and plans any text or call that's due now.
     *
     * @return list<OutreachEvent>
     */
    public function plan(UserContact $entry): array
    {
        $user = $entry->user;
        if (! $entry->is_active || $user->last_seen_at === null) {
            return [];
        }
        if ($user->isAway() || $entry->worry_setting === 'off') {
            $this->setStage($entry, 0);

            return [];
        }
        $awayDays = (int) $this->entitlements->setting($user, 'away_outreach_days', 2);
        if ($user->last_seen_at->lt(now()->subDays($awayDays))) {
            return []; // they haven't opened the app in a while: everyone waits for them
        }

        $hours = $entry->quietSince()->diffInMinutes(now(), true) / 60;
        if ($entry->worry_setting === 'chill') {
            $hours /= 2;
        }
        $worry = ($entry->relationship ?? Relationship::bySlug('friend'))->worry()
            ->at($hours, (int) $this->entitlements->setting($user, 'worry_max_stage', 2));
        $this->setStage($entry, $worry['stage'] ?? 0);
        if ($worry === null || $this->isQuietHours($user)) {
            return [];
        }

        $planned = [];
        if ($this->isDue($entry, OutreachEvent::TEXT, $worry['texts_per_day'], self::MAX_UNANSWERED_TEXTS + $worry['stage'] - 1)) {
            $planned[] = $this->schedule($entry, OutreachEvent::TEXT, $worry['stage'], $worry['tone']);
        }
        if ($entry->contact->voice() !== null && $this->isDue($entry, OutreachEvent::CALL, $worry['calls_per_day'])) {
            $planned[] = $this->schedule($entry, OutreachEvent::CALL, $worry['stage'], $worry['tone']);
        }

        return $planned;
    }

    /**
     * Carries out everyone's due reach-outs: calls right here (they ring or become missed calls), and for users who are
     * online, their texts in the background (App\Jobs\WriteOutreach). Returns how many users had something due.
     */
    public function deliverAll(): int
    {
        $users = User::whereIn('id', UserContact::select('user_id')->whereIn('id', $this->due()->select('user_contact_id')))->get();
        foreach ($users as $user) {
            $this->presence->isOnline($user) ? WriteOutreach::dispatch($user->id) : $this->deliverDue($user);
        }

        return $users->count();
    }

    /**
     * This user's due reach-outs, oldest first. Calls go first and always go through (a missed one may leave a
     * voicemail to write). Texts and voicemails are written only while they're online, stopping at the first one that
     * can't be written, to keep the order.
     */
    public function deliverDue(User $user): void
    {
        $online = $this->presence->isOnline($user);
        $mine = fn () => $this->due()->whereHas('userContact', fn ($q) => $q->where('user_id', $user->id))->orderBy('due_at')->orderBy('id');

        foreach ($mine()->where('kind', OutreachEvent::CALL)->get() as $event) {
            // Only a call that's due right now rings; one from a while back was missed at its time.
            $this->placeCall($event, $online && $event->due_at->gt(now()->subMinutes(self::RING_LATE_MINUTES)));
        }

        if ($online) {
            foreach ($mine()->get() as $event) {
                if (! $this->write($event)) {
                    return;
                }
            }
        }
    }

    /** Whether texts or voicemails are waiting to be written for this user. */
    public function hasWritingDue(User $user): bool
    {
        return $this->due()->where('kind', '!=', OutreachEvent::CALL)->whereHas('userContact', fn ($q) => $q->where('user_id', $user->id))->exists();
    }

    /**
     * Writes one text or voicemail with the contact's prompt and the thread up to its time, and saves it with that
     * time. Returns false if the AI couldn't answer (it stays planned and is tried again).
     */
    public function write(OutreachEvent $event): bool
    {
        $entry = $event->userContact()->with(['user', 'contact', 'relationship'])->firstOrFail();
        $conversation = $this->texting->conversationFor($entry);
        $history = $entry->messages()->where('created_at', '<=', $event->due_at)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(Texting::HISTORY)->get()->reverse();
        $messages = [
            ['role' => 'system', 'content' => $conversation->prompt_text],
            ...$history->map(fn (Message $m) => ['role' => $m->fromUser() ? 'user' : 'assistant', 'content' => $m->body])->values()->all(),
            ['role' => 'system', 'content' => $this->instruction($event, $entry)],
        ];

        try {
            $text = $this->gateway->chat(LlmPurpose::Chat, $messages, $entry->user, $entry->contact)->text;
            if ($this->crossesLine($text)) {
                $messages[] = ['role' => 'system', 'content' => '(Write it again, kinder: no guilt, no pressure, nothing about emergencies.)'];
                $text = $this->gateway->chat(LlmPurpose::Chat, $messages, $entry->user, $entry->contact)->text;
            }
        } catch (LlmException) {
            return false;
        }

        if ($this->crossesLine($text) || $this->moderator->checkReply($entry->user, $text)->blocked) {
            $event->update(['status' => OutreachEvent::SKIPPED, 'materialized_at' => now()]);

            return true;
        }

        $voicemail = $event->kind === OutreachEvent::VOICEMAIL;
        $parts = $voicemail ? [trim($text)] : $this->texting->bubbles($text);
        $first = null;
        foreach ($parts as $part) {
            $message = $this->save($entry, $part, $event->due_at, $voicemail ? ['voicemail' => true, 'call_id' => $event->call_id] : ['outreach' => $event->id]);
            $first ??= $message;
        }
        $conversation->update(['last_message_at' => now()]);
        $event->update(['status' => OutreachEvent::DONE, 'materialized_at' => now(), 'message_id' => $first?->id]);

        return true;
    }

    /**
     * A ringing call that wasn't answered (declined, or rang out): it becomes a missed call. With voicemail on their
     * plan, the contact leaves one.
     */
    public function missed(Call $call): void
    {
        if ($call->status !== Call::RINGING) {
            return;
        }

        $call->update(['status' => Call::MISSED, 'ended_at' => now()]);
        $entry = $call->userContact()->with('user')->firstOrFail();
        RingingStopped::dispatch($entry->user, $call);
        $this->recordMissed($entry, $call, now());
        if ($this->hasWritingDue($entry->user)) {
            WriteOutreach::dispatch($entry->user->id);
        }
    }

    /** Guilt, pressure or a pretend emergency: a caring contact never sends it. */
    public function crossesLine(string $text): bool
    {
        foreach (self::OVER_THE_LINE as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /** Between QUIET_FROM and QUIET_UNTIL where the user is. */
    public function isQuietHours(User $user): bool
    {
        $hour = (int) CarbonImmutable::now($user->timezone)->format('G');

        return $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL;
    }

    /** Ring the user if they're here (and not already on a call); otherwise it's a missed call at its time. */
    private function placeCall(OutreachEvent $event, bool $online): void
    {
        $entry = $event->userContact()->with(['user', 'contact'])->firstOrFail();
        $busy = $online && Call::whereIn('status', [Call::RINGING, Call::STARTING, Call::ACTIVE])
            ->whereHas('userContact', fn ($q) => $q->where('user_id', $entry->user_id))->exists();

        $call = new Call(['direction' => Call::INCOMING, 'status' => $online && ! $busy ? Call::RINGING : Call::MISSED]);
        $call->created_at = $event->due_at;
        $call->ended_at = $call->status === Call::MISSED ? $event->due_at : null;
        $entry->calls()->save($call);
        $event->update(['status' => OutreachEvent::DONE, 'materialized_at' => now(), 'call_id' => $call->id]);

        if ($call->status === Call::RINGING) {
            ContactCalling::dispatch($entry->user, $call, $entry);
            ExpireRinging::dispatch($call->id)->delay(now()->addSeconds(self::RING_SECONDS));

            return;
        }

        $this->recordMissed($entry, $call, $event->due_at);
    }

    /** "📞 Missed call" in the thread at that time, and a voicemail to write if their plan has voicemail. */
    private function recordMissed(UserContact $entry, Call $call, Carbon $at): void
    {
        $this->save($entry, '📞 Missed call', $at, ['call_id' => $call->id, 'missed' => true]);

        $event = OutreachEvent::where('call_id', $call->id)->first();
        if ($event && $this->entitlements->can($entry->user, Feature::Voicemail)) {
            $entry->outreachEvents()->create([
                'kind' => OutreachEvent::VOICEMAIL, 'stage' => $event->stage, 'tone' => $event->tone, 'due_at' => $at, 'call_id' => $call->id,
            ]);
        }
    }

    /**
     * A message from the contact with its own time (a backlog keeps the times it was "sent").
     *
     * @param  array<string, mixed>  $meta
     */
    private function save(UserContact $entry, string $body, Carbon $at, array $meta): Message
    {
        $message = new Message(['sender' => Message::FROM_CONTACT, 'body' => $body, 'meta' => $meta]);
        $message->created_at = $at;
        $entry->messages()->save($message);
        ContactMessaged::dispatch($entry->user, $message); // live if their page is open

        return $message;
    }

    /** What the contact is asked to write: a first text, or a voicemail after an unanswered call. */
    private function instruction(OutreachEvent $event, UserContact $entry): string
    {
        $buddy = $entry->user->preferredName();
        $care = 'Show care, never guilt: never say they forgot you, abandoned you or don\'t care, don\'t pressure them to answer, and never mention police, hospitals or emergencies.';

        if ($event->kind === OutreachEvent::VOICEMAIL) {
            return "(You just called {$buddy} and they didn't pick up. Leave a short voicemail, two or three sentences, the way you'd talk after the beep: {$event->tone}. {$care})";
        }

        $time = CarbonImmutable::instance($event->due_at)->setTimezone($entry->user->timezone)->format('l g:i A');
        $hours = (int) $entry->quietSince()->diffInHours($event->due_at, true);
        $silence = $hours >= 48 ? intdiv($hours, 24).' days' : "{$hours} hours";

        return "(This isn't a reply: you're texting {$buddy} first. It's {$time} where they are, and you haven't heard from them in {$silence}. What you'd send: {$event->tone}. One or two short texts, in your own voice. {$care})";
    }

    /**
     * Whether a contact reaches out this way now: the stage allows it, today's count isn't used up, they're spaced
     * out through the day, and (for texts) they aren't waiting on a reply.
     */
    private function isDue(UserContact $entry, string $kind, int $perDay, ?int $maxUnanswered = null): bool
    {
        if ($perDay <= 0) {
            return false;
        }

        $sent = $entry->outreachEvents()->where('kind', $kind)->where('status', '!=', OutreachEvent::SKIPPED);
        $today = (clone $sent)->where('due_at', '>', now()->subDay());
        if ($today->count() >= $perDay) {
            return false;
        }
        $last = $today->max('due_at');
        if ($last !== null && Carbon::parse($last)->gt(now()->subMinutes(intdiv(24 * 60, $perDay)))) {
            return false;
        }

        return $maxUnanswered === null || (clone $sent)->where('due_at', '>', $entry->quietSince())->count() < $maxUnanswered;
    }

    private function schedule(UserContact $entry, string $kind, int $stage, string $tone): OutreachEvent
    {
        return $entry->outreachEvents()->create(['kind' => $kind, 'stage' => $stage, 'tone' => mb_substr($tone, 0, 200), 'due_at' => now()]);
    }

    private function setStage(UserContact $entry, int $stage): void
    {
        if ($entry->worry_stage !== $stage) {
            $entry->forceFill(['worry_stage' => $stage, 'worry_started_at' => $stage > 0 ? ($entry->worry_started_at ?? now()) : null])->save();
        }
    }

    /** @return Builder<OutreachEvent> */
    private function due(): Builder
    {
        return OutreachEvent::where('status', OutreachEvent::PLANNED)->where('due_at', '<=', now());
    }
}

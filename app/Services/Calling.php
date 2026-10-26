<?php

namespace App\Services;

use App\Contracts\VoiceProvider;
use App\Enums\LlmPurpose;
use App\Enums\Metric;
use App\Exceptions\LlmException;
use App\Exceptions\VoiceException;
use App\Jobs\UpdateMemory;
use App\Models\Call;
use App\Models\LlmCall;
use App\Models\Message;
use App\Models\OutreachEvent;
use App\Models\User;
use App\Models\UserContact;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Voice calls through our voice service (voice/, Pipecat speaking with Gemini Live). Starting a call makes a one-off
 * session there with the contact's prompt, voice and memory at that moment (so the prompt stays on the server and the
 * same for the whole call), limited to the plan's call time left. The service's end-of-call report
 * (App\Http\Controllers\VoiceWebhookController) then records the length and transcript, prices the call from its token
 * usage, uses up the call time, adds the call to the conversation, and updates the contact's memory.
 */
class Calling
{
    /** Fewer seconds left than this and a call can't start. */
    public const MIN_SECONDS = 30;

    /** No single call runs longer than this. */
    public const MAX_SECONDS = 30 * 60;

    /** The header the voice service and Laravel send each other, so nobody else can post fake reports or sessions. */
    public const SECRET_HEADER = 'X-Call-Joe-Secret';

    public function __construct(
        private VoiceProvider $voice,
        private Entitlements $entitlements,
        private PromptBuilder $prompts,
        private MemoryWriter $memory,
        private LlmGateway $gateway,
        private Moderator $moderator,
    ) {}

    /**
     * Gets a call ready: returns the call, with the voice session the browser connects to. With $answering, it's the
     * user picking up the contact's ringing call (App\Services\Outreach), and the contact says why they called.
     *
     * @throws DomainException with a reason the user can read.
     */
    public function start(User $user, UserContact $entry, ?Call $answering = null): Call
    {
        $entry->loadMissing(['contact', 'user', 'relationship']);
        if ($answering && ($answering->user_contact_id !== $entry->id || $answering->status !== Call::RINGING)) {
            throw new DomainException('That call already ended.');
        }
        $voice = $entry->contact->voice ?: throw new DomainException("{$entry->displayName()} can't take calls yet.");

        $left = $this->entitlements->remaining($user, Metric::VoiceSeconds);
        if ($left !== null && $left < self::MIN_SECONDS) {
            $resets = $this->entitlements->resetsAt($user, Metric::VoiceSeconds)->format('M j');

            throw new DomainException("You've used this month's call time. It resets on {$resets}.");
        }
        $max = min($left ?? self::MAX_SECONDS, self::MAX_SECONDS);

        // Calling (or picking up) counts as hearing from the user: any worry ends, and turns into relief on this call.
        $entry->markHeard();
        $prompt = $this->prompts->build($entry, $this->memory->notesFor($entry))->text;
        if ($relief = $entry->reliefNote()) {
            $prompt .= "\n\n# Before this call\n{$relief}";
            $entry->clearRelief();
        }
        if ($answering) {
            $why = OutreachEvent::where('call_id', $answering->id)->value('tone') ?? 'to catch up';
            $prompt .= "\n\n# This call\nYou called {$user->preferredName()} and they just picked up. Say hello and why you called: {$why}.";
        }
        try {
            $model = $this->gateway->modelFor(LlmPurpose::Voice, $user);
        } catch (LlmException) {
            throw new DomainException("{$entry->displayName()} can't take calls right now.");
        }

        $fields = ['status' => Call::STARTING, 'prompt_hash' => hash('sha256', $prompt), 'max_seconds' => $max, 'llm_model_id' => $model->id];
        $call = $answering ? tap($answering)->update($fields) : $entry->calls()->create($fields);

        try {
            $sessionId = $this->voice->createSession([
                'call_id' => $call->id,
                'prompt' => $prompt,
                'voice' => $voice,
                'model' => $model->model_id,
                'max_seconds' => $max,
            ]);
        } catch (VoiceException $e) {
            $call->update(['status' => Call::FAILED, 'ended_reason' => mb_substr($e->getMessage(), 0, 100)]);

            throw new DomainException("Couldn't reach {$entry->displayName()} right now. Try again in a minute.");
        }

        $call->update(['voice_session_id' => $sessionId]);

        return $call;
    }

    /** Where the browser connects for this call. */
    public function connectUrl(Call $call): string
    {
        return $this->voice->connectUrl((string) $call->voice_session_id);
    }

    /** The browser connected (or the voice service said the call is in progress). */
    public function connected(Call $call): void
    {
        if ($call->isOver()) {
            return;
        }

        $call->update(['status' => Call::ACTIVE, 'started_at' => $call->started_at ?? now()]);
    }

    /** The browser says the call ended; the voice service's report fills in the details. */
    public function hungUp(Call $call): void
    {
        if (! $call->isOver()) {
            $call->update(['status' => Call::ENDED, 'ended_at' => now()]);
        }
    }

    /**
     * The voice service's status update. Returns false when the call isn't ours.
     *
     * @param  array<string, mixed>  $message
     */
    public function handleStatus(array $message): bool
    {
        $call = $this->callFor($message);
        if (! $call) {
            return false;
        }

        match (Arr::get($message, 'status')) {
            'in-progress' => $this->connected($call),
            'ended' => $this->hungUp($call),
            default => null,
        };

        return true;
    }

    /**
     * The voice service's end-of-call report: length, transcript, token usage (priced at the call's model), call time
     * used, the call in the conversation, memory.
     * Safe to receive twice. Returns false when the call isn't ours.
     *
     * @param  array<string, mixed>  $message
     */
    public function handleReport(array $message): bool
    {
        $call = $this->callFor($message);
        if (! $call) {
            return false;
        }
        if ($call->duration_seconds !== null) {
            return true; // already recorded
        }

        $entry = $call->userContact()->with(['user', 'contact'])->firstOrFail();
        $seconds = max(0, min($call->max_seconds, $this->durationOf($message, $call)));
        $in = max(0, (int) Arr::get($message, 'usage.inputTokens', 0));
        $out = max(0, (int) Arr::get($message, 'usage.outputTokens', 0));
        $model = $call->llmModel;
        $cost = $model ? $model->costMicros($in, $out) : 0;

        $call->update([
            'status' => Call::ENDED,
            'ended_at' => $call->ended_at ?? now(),
            'duration_seconds' => $seconds,
            'ended_reason' => mb_substr((string) Arr::get($message, 'endedReason', Arr::get($message, 'call.endedReason', '')), 0, 100) ?: null,
            'transcript' => trim((string) Arr::get($message, 'artifact.transcript', Arr::get($message, 'transcript', ''))) ?: null,
            'cost_micros' => $cost,
        ]);

        if ($model && ($in > 0 || $out > 0)) {
            LlmCall::create([
                'user_id' => $entry->user_id, 'contact_id' => $entry->contact_id, 'llm_model_id' => $model->id, 'model' => $model->model_id,
                'purpose' => LlmPurpose::Voice, 'prompt_tokens' => $in, 'completion_tokens' => $out, 'latency_ms' => $seconds * 1000,
                'status' => 'ok', 'cost_micros' => $cost,
            ]);
        }

        $this->useCallTime($entry->user, $seconds);

        // If they talked about hurting themselves, it's noted and the chat shows where to get help.
        $crisis = $this->moderator->checkCall($entry->user, $call)->isCrisis();
        $entry->messages()->create([
            'sender' => Message::FROM_CONTACT,
            'body' => $seconds > 0 ? '📞 Call · '.$call->lengthLabel() : '📞 Missed call',
            'meta' => ['call_id' => $call->id, ...($crisis ? ['crisis' => 'self_harm'] : [])],
            'read_at' => now(),
        ]);

        if ($call->transcript) {
            UpdateMemory::afterQuiet($entry->id);
        }

        if ($call->voice_session_id) {
            $this->voice->deleteSession($call->voice_session_id);
        }

        return true;
    }

    /** Uses up the call's seconds, never past what's left (the call can't have run longer than the limit anyway). */
    private function useCallTime(User $user, int $seconds): void
    {
        $left = $this->entitlements->remaining($user, Metric::VoiceSeconds);
        $charge = $left === null ? $seconds : min($seconds, $left);
        if ($charge > 0) {
            $this->entitlements->consume($user, Metric::VoiceSeconds, $charge);
        }
    }

    /** @param  array<string, mixed>  $message */
    private function durationOf(array $message, Call $call): int
    {
        $seconds = Arr::get($message, 'durationSeconds', Arr::get($message, 'call.durationSeconds'));
        if (is_numeric($seconds)) {
            return (int) ceil((float) $seconds);
        }

        $from = Arr::get($message, 'call.startedAt') ?? $call->started_at;
        $to = Arr::get($message, 'call.endedAt') ?? $call->ended_at ?? now();

        return $from ? (int) ceil(Carbon::parse($from)->diffInSeconds(Carbon::parse($to), true)) : 0;
    }

    /**
     * Our call for a voice service message: by its session (each call has its own), or the call id we gave the session.
     *
     * @param  array<string, mixed>  $message
     */
    private function callFor(array $message): ?Call
    {
        $assistantId = Arr::get($message, 'call.assistantId', Arr::get($message, 'assistant.id'));
        if (is_string($assistantId) && ($call = Call::where('voice_session_id', $assistantId)->first())) {
            return $call;
        }

        $callId = Arr::get($message, 'assistant.metadata.call_id', Arr::get($message, 'call.metadata.call_id'));

        return is_numeric($callId) ? Call::find((int) $callId) : null;
    }
}

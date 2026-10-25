<?php

namespace App\Services;

use App\Contracts\VoiceProvider;
use App\Enums\LlmPurpose;
use App\Enums\Metric;
use App\Exceptions\LlmException;
use App\Exceptions\VoiceException;
use App\Jobs\UpdateMemory;
use App\Models\Call;
use App\Models\Message;
use App\Models\User;
use App\Models\UserContact;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Voice calls through Vapi. Starting a call builds a one-off Vapi assistant with the contact's prompt and memory at
 * that moment (so the prompt stays on the server and the same for the whole call), limited to the plan's call time
 * left. Vapi's end-of-call report (App\Http\Controllers\VapiWebhookController) then records the length and
 * transcript, uses up the call time, adds the call to the conversation, and updates the contact's memory.
 */
class Calling
{
    /** Fewer seconds left than this and a call can't start. */
    public const MIN_SECONDS = 30;

    /** No single call runs longer than this. */
    public const MAX_SECONDS = 30 * 60;

    /** The header Vapi sends with every report, so nobody else can post fake ones. */
    public const SECRET_HEADER = 'X-Call-Joe-Secret';

    public function __construct(
        private VoiceProvider $voice,
        private Entitlements $entitlements,
        private PromptBuilder $prompts,
        private MemoryWriter $memory,
        private LlmGateway $gateway,
    ) {}

    /**
     * Gets a call ready: returns the call, with the Vapi assistant the browser starts.
     *
     * @throws DomainException with a reason the user can read.
     */
    public function start(User $user, UserContact $entry): Call
    {
        $entry->loadMissing(['contact', 'user', 'relationship']);
        $voice = $entry->contact->voice() ?? throw new DomainException("{$entry->displayName()} can't take calls yet.");

        $left = $this->entitlements->remaining($user, Metric::VoiceSeconds);
        if ($left !== null && $left < self::MIN_SECONDS) {
            $resets = $this->entitlements->resetsAt($user, Metric::VoiceSeconds)->format('M j');

            throw new DomainException("You've used this month's call time. It resets on {$resets}.");
        }
        $max = min($left ?? self::MAX_SECONDS, self::MAX_SECONDS);

        $snapshot = $this->prompts->build($entry, $this->memory->notesFor($entry));
        try {
            $model = $this->gateway->modelFor(LlmPurpose::Voice, $user);
        } catch (LlmException) {
            $model = $this->gateway->modelFor(LlmPurpose::Chat, $user);
        }

        $call = $entry->calls()->create(['prompt_hash' => $snapshot->hash, 'max_seconds' => $max]);

        try {
            $assistantId = $this->voice->createAssistant([
                'name' => "Call Joe call {$call->id}",
                'firstMessageMode' => 'assistant-speaks-first-with-model-generated-message',
                'model' => [
                    'provider' => $model->provider,
                    'model' => $model->model_id,
                    'messages' => [['role' => 'system', 'content' => $snapshot->text]],
                    'temperature' => 0.8,
                    'maxTokens' => 250,
                ],
                'voice' => $voice,
                'maxDurationSeconds' => $max,
                'server' => ['url' => config('services.vapi.webhook_url'), 'headers' => [self::SECRET_HEADER => config('services.vapi.webhook_secret')]],
                'serverMessages' => ['status-update', 'end-of-call-report'],
                'metadata' => ['call_id' => $call->id],
            ]);
        } catch (VoiceException $e) {
            $call->update(['status' => Call::FAILED, 'ended_reason' => mb_substr($e->getMessage(), 0, 100)]);

            throw new DomainException("Couldn't reach {$entry->displayName()} right now. Try again in a minute.");
        }

        $call->update(['vapi_assistant_id' => $assistantId]);

        return $call;
    }

    public function publicKey(): string
    {
        return $this->voice->publicKey();
    }

    /** The browser connected (or Vapi said the call is in progress). */
    public function connected(Call $call, ?string $vapiCallId = null): void
    {
        if ($call->isOver()) {
            return;
        }

        $call->update(['status' => Call::ACTIVE, 'started_at' => $call->started_at ?? now(), 'vapi_call_id' => $call->vapi_call_id ?? $vapiCallId]);
    }

    /** The browser says the call ended; Vapi's report fills in the details. */
    public function hungUp(Call $call): void
    {
        if (! $call->isOver()) {
            $call->update(['status' => Call::ENDED, 'ended_at' => now()]);
        }
    }

    /**
     * Vapi's status update. Returns false when the call isn't ours.
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
            'in-progress' => $this->connected($call, Arr::get($message, 'call.id')),
            'ended' => $this->hungUp($call),
            default => null,
        };

        return true;
    }

    /**
     * Vapi's end-of-call report: length, transcript, call time used, the call in the conversation, memory.
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
        $cost = Arr::get($message, 'cost', Arr::get($message, 'call.cost'));

        $call->update([
            'status' => Call::ENDED,
            'ended_at' => $call->ended_at ?? now(),
            'duration_seconds' => $seconds,
            'ended_reason' => mb_substr((string) Arr::get($message, 'endedReason', Arr::get($message, 'call.endedReason', '')), 0, 100) ?: null,
            'transcript' => trim((string) Arr::get($message, 'artifact.transcript', Arr::get($message, 'transcript', ''))) ?: null,
            'cost_micros' => is_numeric($cost) ? (int) round($cost * 1_000_000) : null,
        ]);

        $this->useCallTime($entry->user, $seconds);

        $entry->messages()->create([
            'sender' => Message::FROM_CONTACT,
            'body' => $seconds > 0 ? '📞 Call · '.$call->lengthLabel() : '📞 Missed call',
            'meta' => ['call_id' => $call->id],
            'read_at' => now(),
        ]);

        if ($call->transcript) {
            UpdateMemory::afterQuiet($entry->id);
        }

        if ($call->vapi_assistant_id) {
            $this->voice->deleteAssistant($call->vapi_assistant_id);
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
     * Our call for a Vapi message: by its assistant (each call has its own), or the call id we put in its metadata.
     *
     * @param  array<string, mixed>  $message
     */
    private function callFor(array $message): ?Call
    {
        $assistantId = Arr::get($message, 'call.assistantId', Arr::get($message, 'assistant.id'));
        if (is_string($assistantId) && ($call = Call::where('vapi_assistant_id', $assistantId)->first())) {
            return $call;
        }

        $callId = Arr::get($message, 'assistant.metadata.call_id', Arr::get($message, 'call.metadata.call_id'));

        return is_numeric($callId) ? Call::find((int) $callId) : null;
    }
}

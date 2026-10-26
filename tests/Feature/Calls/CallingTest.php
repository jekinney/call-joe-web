<?php

use App\Enums\Metric;
use App\Jobs\UpdateMemory;
use App\Models\Call;
use App\Models\Contact;
use App\Models\Llm;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Calling;
use App\Services\Entitlements;
use App\Services\LlmGateway;
use App\Services\MemoryWriter;
use App\Services\Phonebook;
use Illuminate\Support\Facades\Queue;

// Starting a call (minutes, prompt, assistant), and Vapi's reports.

beforeEach(function () {
    config(['services.vapi.webhook_url' => 'https://example.test/webhooks/vapi', 'services.vapi.webhook_secret' => 'shh']);
    $this->user = User::factory()->create(['name' => 'Jamie Rivera']);
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman', 'voice_provider_id' => 'maya-voice', 'voice_settings' => ['provider' => '11labs']]);
    $this->entry = app(Phonebook::class)->add($this->user, $this->maya, Relationship::bySlug('sister'));
});

function calling(): Calling
{
    return app(Calling::class);
}

it('sets up a call: a one-off assistant with the prompt, memory, voice, time limit and webhook', function () {
    app(MemoryWriter::class)->memoryFor($this->entry)->update(['notes_md' => '- Has a dog named Biscuit.']);

    $call = calling()->start($this->user, $this->entry);
    $assistant = $this->voice->assistants[$call->vapi_assistant_id];

    expect($call->status)->toBe(Call::STARTING)
        ->and($call->max_seconds)->toBe(600) // Free: 10 minutes a month, none used
        ->and($assistant['model']['provider'])->toBe('google') // Vapi's name for Gemini
        ->and($assistant['model']['model'])->toBe('gemini-3.5-flash-lite') // the starting model for calls
        ->and($assistant['model']['messages'][0]['content'])->toContain("You are Jamie's sister.")->toContain('Biscuit')
        ->and(hash('sha256', $assistant['model']['messages'][0]['content']))->toBe($call->prompt_hash)
        ->and($assistant['voice'])->toBe(['provider' => '11labs', 'voiceId' => 'maya-voice'])
        ->and($assistant['maxDurationSeconds'])->toBe(600)
        ->and($assistant['server'])->toBe(['url' => 'https://example.test/webhooks/vapi', 'headers' => ['X-Call-Joe-Secret' => 'shh']])
        ->and($assistant['serverMessages'])->toBe(['status-update', 'end-of-call-report'])
        ->and($assistant['metadata'])->toBe(['call_id' => $call->id])
        ->and(calling()->publicKey())->toBe('pk_fake');
});

it('uses the voice model set up in admin, on any provider', function () {
    Llm::create(['provider' => 'xai', 'model_id' => 'grok-voice', 'label' => 'Voice', 'purpose' => 'voice']);

    $call = calling()->start($this->user, $this->entry);

    expect($this->voice->assistants[$call->vapi_assistant_id]['model']['model'])->toBe('grok-voice')
        ->and($this->voice->assistants[$call->vapi_assistant_id]['model']['provider'])->toBe('xai');
});

it('uses the texting model when no model is set up for calls', function () {
    Llm::where('purpose', 'voice')->delete();
    app(LlmGateway::class)->forgetModels();

    $call = calling()->start($this->user, $this->entry);

    expect($this->voice->assistants[$call->vapi_assistant_id]['model']['model'])->toBe('grok-4.20-0309-non-reasoning')
        ->and($this->voice->assistants[$call->vapi_assistant_id]['model']['provider'])->toBe('xai');
});

it('limits a call to the call time left, and no more than 30 minutes', function () {
    app(Entitlements::class)->consume($this->user, Metric::VoiceSeconds, 500);
    expect(calling()->start($this->user, $this->entry)->max_seconds)->toBe(100);

    $paidUser = User::factory()->create();
    app(Entitlements::class)->assignPlan($paidUser, Plan::where('slug', 'paid')->firstOrFail(), null);
    $paidEntry = app(Phonebook::class)->add($paidUser, $this->maya);
    expect(calling()->start($paidUser, $paidEntry)->max_seconds)->toBe(Calling::MAX_SECONDS);
});

it('refuses a call when the month\'s call time is used up', function () {
    app(Entitlements::class)->consume($this->user, Metric::VoiceSeconds, 590);

    calling()->start($this->user, $this->entry);
})->throws(DomainException::class, "You've used this month's call time.");

it('refuses a call to a contact without a voice', function () {
    $this->maya->forceFill(['voice_provider_id' => null])->save();

    calling()->start($this->user, $this->entry->fresh());
})->throws(DomainException::class, "Maya can't take calls yet.");

it('marks the call failed when Vapi will not set it up', function () {
    $this->voice->fail();

    expect(fn () => calling()->start($this->user, $this->entry))->toThrow(DomainException::class, "Couldn't reach Maya right now.");
    expect(Call::sole()->status)->toBe(Call::FAILED)->and(Call::sole()->isOver())->toBeTrue();
});

it('marks the call connected, and ended when the browser hangs up', function () {
    $call = calling()->start($this->user, $this->entry);

    calling()->connected($call, 'call_abc');
    expect($call->refresh()->status)->toBe(Call::ACTIVE)->and($call->vapi_call_id)->toBe('call_abc')->and($call->started_at)->not->toBeNull();

    calling()->hungUp($call);
    expect($call->refresh()->status)->toBe(Call::ENDED);

    calling()->connected($call, 'later'); // a late "connected" doesn't reopen it
    calling()->hungUp($call);
    expect($call->refresh()->status)->toBe(Call::ENDED);
});

function endOfCall(Call $call, array $extra = []): array
{
    return array_replace_recursive([
        'type' => 'end-of-call-report',
        'endedReason' => 'customer-ended-call',
        'call' => ['id' => 'call_abc', 'assistantId' => $call->vapi_assistant_id],
        'durationSeconds' => 245.3,
        'cost' => 0.37,
        'artifact' => ['transcript' => "AI: Hey you!\nUser: Guess who got the job?"],
    ], $extra);
}

it('records the end-of-call report: length, transcript, call time, the conversation, memory, cleanup', function () {
    Queue::fake();
    $call = calling()->start($this->user, $this->entry);

    expect(calling()->handleReport(endOfCall($call)))->toBeTrue();

    $call->refresh();
    expect($call->duration_seconds)->toBe(246)
        ->and($call->status)->toBe(Call::ENDED)
        ->and($call->ended_reason)->toBe('customer-ended-call')
        ->and($call->transcript)->toContain('Guess who got the job?')
        ->and($call->cost_micros)->toBe(370000)
        ->and($call->lengthLabel())->toBe('4 min')
        ->and(app(Entitlements::class)->used($this->user, Metric::VoiceSeconds))->toBe(246)
        ->and(Message::sole()->only(['sender', 'body']))->toBe(['sender' => 'contact', 'body' => '📞 Call · 4 min'])
        ->and(Message::sole()->meta)->toBe(['call_id' => $call->id])
        ->and($this->voice->deleted)->toBe([$call->vapi_assistant_id]);
    Queue::assertPushed(UpdateMemory::class);
});

it('handles the same report twice without counting twice', function () {
    $call = calling()->start($this->user, $this->entry);
    calling()->handleReport(endOfCall($call));
    calling()->handleReport(endOfCall($call));

    expect(app(Entitlements::class)->used($this->user, Metric::VoiceSeconds))->toBe(246)->and(Message::count())->toBe(1);
});

it('finds the call by the id in its metadata, and works out the length from the times', function () {
    $call = calling()->start($this->user, $this->entry);

    calling()->handleReport([
        'type' => 'end-of-call-report',
        'assistant' => ['metadata' => ['call_id' => $call->id]],
        'call' => ['startedAt' => '2026-10-25T10:00:00Z', 'endedAt' => '2026-10-25T10:00:42Z'],
    ]);

    expect($call->refresh()->duration_seconds)->toBe(42)->and($call->lengthLabel())->toBe('42 sec')->and($call->transcript)->toBeNull();
});

it('records a call that never connected as missed, and does not update memory', function () {
    Queue::fake();
    $call = calling()->start($this->user, $this->entry);

    calling()->handleReport(['type' => 'end-of-call-report', 'call' => ['assistantId' => $call->vapi_assistant_id]]);

    expect(Message::sole()->body)->toBe('📞 Missed call')->and(app(Entitlements::class)->used($this->user, Metric::VoiceSeconds))->toBe(0);
    Queue::assertNotPushed(UpdateMemory::class);
});

it('never counts more than the call was allowed', function () {
    app(Entitlements::class)->consume($this->user, Metric::VoiceSeconds, 500);
    $call = calling()->start($this->user, $this->entry); // 100 seconds left

    calling()->handleReport(endOfCall($call, ['durationSeconds' => 900]));

    expect($call->refresh()->duration_seconds)->toBe(100)
        ->and(app(Entitlements::class)->used($this->user, Metric::VoiceSeconds))->toBe(600);
});

it('ignores reports and updates for calls that are not ours', function () {
    expect(calling()->handleReport(['call' => ['assistantId' => 'someone-else']]))->toBeFalse()
        ->and(calling()->handleStatus(['status' => 'in-progress']))->toBeFalse();
});

it('follows Vapi\'s status updates', function () {
    $call = calling()->start($this->user, $this->entry);

    calling()->handleStatus(['status' => 'in-progress', 'call' => ['id' => 'call_xyz', 'assistantId' => $call->vapi_assistant_id]]);
    expect($call->refresh()->status)->toBe(Call::ACTIVE)->and($call->vapi_call_id)->toBe('call_xyz');

    calling()->handleStatus(['status' => 'ringing', 'call' => ['assistantId' => $call->vapi_assistant_id]]);
    expect($call->refresh()->status)->toBe(Call::ACTIVE);

    calling()->handleStatus(['status' => 'ended', 'call' => ['assistantId' => $call->vapi_assistant_id]]);
    expect($call->refresh()->status)->toBe(Call::ENDED)->and($call->userContact->is($this->entry))->toBeTrue();
});

it('teaches memory from the call transcript', function () {
    $call = calling()->start($this->user, $this->entry);
    calling()->handleReport(endOfCall($call));
    $this->llm->reply('- Got a new job.');

    app(MemoryWriter::class)->update($this->entry);

    expect($this->llm->calls[0]['messages'][1]['content'])->toContain('Phone call on')->toContain('Guess who got the job?')
        ->and($this->entry->memory()->first()->last_call_id)->toBe($call->id);

    // Nothing new after that, and forgetting everything skips old calls too.
    expect(app(MemoryWriter::class)->update($this->entry))->toBeFalse();
    app(MemoryWriter::class)->reset($this->entry);
    expect($this->entry->memory()->first()->last_call_id)->toBe($call->id);
});

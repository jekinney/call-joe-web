<?php

use App\Enums\LlmPurpose;
use App\Enums\Metric;
use App\Jobs\UpdateMemory;
use App\Models\Call;
use App\Models\Contact;
use App\Models\Llm;
use App\Models\LlmCall;
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

// Starting a call (minutes, prompt, voice session), and the voice service's reports.

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Jamie Rivera']);
    $this->maya = Contact::factory()->published()->create(['name' => 'Maya Brooks', 'gender' => 'woman', 'voice' => 'Kore']);
    $this->entry = app(Phonebook::class)->add($this->user, $this->maya, Relationship::bySlug('sister'));
});

function calling(): Calling
{
    return app(Calling::class);
}

it('sets up a call: a one-off voice session with the prompt, memory, voice, model and time limit', function () {
    app(MemoryWriter::class)->memoryFor($this->entry)->update(['notes_md' => '- Has a dog named Biscuit.']);

    $call = calling()->start($this->user, $this->entry);
    $session = $this->voice->sessions[$call->voice_session_id];

    expect($call->status)->toBe(Call::STARTING)
        ->and($call->max_seconds)->toBe(600) // Free: 10 minutes a month, none used
        ->and($session['call_id'])->toBe($call->id)
        ->and($session['model'])->toBe('gemini-3.8-live') // the starting model for calls
        ->and($call->llmModel->model_id)->toBe('gemini-3.8-live')
        ->and($session['prompt'])->toContain("You are Jamie's sister.")->toContain('Biscuit')
        ->and(hash('sha256', $session['prompt']))->toBe($call->prompt_hash)
        ->and($session['voice'])->toBe('Kore')
        ->and($session['max_seconds'])->toBe(600)
        ->and(calling()->connectUrl($call))->toBe("https://voice.test/api/offer?session={$call->voice_session_id}");
});

it('uses the voice model set up in admin', function () {
    Llm::where('purpose', 'voice')->update(['model_id' => 'gemini-3.1-flash-live-preview']);
    app(LlmGateway::class)->forgetModels();

    $call = calling()->start($this->user, $this->entry);

    expect($this->voice->sessions[$call->voice_session_id]['model'])->toBe('gemini-3.1-flash-live-preview');
});

it('refuses a call when no model is set up for calls', function () {
    Llm::where('purpose', 'voice')->delete();
    app(LlmGateway::class)->forgetModels();

    calling()->start($this->user, $this->entry);
})->throws(DomainException::class, "Maya can't take calls right now.");

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
    $this->maya->forceFill(['voice' => null])->save();

    calling()->start($this->user, $this->entry->fresh());
})->throws(DomainException::class, "Maya can't take calls yet.");

it('marks the call failed when the voice service will not set it up', function () {
    $this->voice->fail();

    expect(fn () => calling()->start($this->user, $this->entry))->toThrow(DomainException::class, "Couldn't reach Maya right now.");
    expect(Call::sole()->status)->toBe(Call::FAILED)->and(Call::sole()->isOver())->toBeTrue();
});

it('marks the call connected, and ended when the browser hangs up', function () {
    $call = calling()->start($this->user, $this->entry);

    calling()->connected($call);
    expect($call->refresh()->status)->toBe(Call::ACTIVE)->and($call->started_at)->not->toBeNull();

    calling()->hungUp($call);
    expect($call->refresh()->status)->toBe(Call::ENDED);

    calling()->connected($call); // a late "connected" doesn't reopen it
    calling()->hungUp($call);
    expect($call->refresh()->status)->toBe(Call::ENDED);
});

function endOfCall(Call $call, array $extra = []): array
{
    return array_replace_recursive([
        'type' => 'end-of-call-report',
        'endedReason' => 'customer-ended-call',
        'assistant' => ['id' => $call->voice_session_id],
        'durationSeconds' => 245.3,
        'usage' => ['inputTokens' => 60000, 'outputTokens' => 4000],
        'transcript' => "AI: Hey you!\nUser: Guess who got the job?",
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
        ->and($call->cost_micros)->toBe(228000) // 60,000 in at 3.00/M plus 4,000 out at 12.00/M
        ->and(LlmCall::sole()->only(['purpose', 'prompt_tokens', 'completion_tokens', 'cost_micros']))
        ->toBe(['purpose' => LlmPurpose::Voice, 'prompt_tokens' => 60000, 'completion_tokens' => 4000, 'cost_micros' => 228000])
        ->and($call->lengthLabel())->toBe('4 min')
        ->and(app(Entitlements::class)->used($this->user, Metric::VoiceSeconds))->toBe(246)
        ->and(Message::sole()->only(['sender', 'body']))->toBe(['sender' => 'contact', 'body' => '📞 Call · 4 min'])
        ->and(Message::sole()->meta)->toBe(['call_id' => $call->id])
        ->and($this->voice->deleted)->toBe([$call->voice_session_id]);
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

    calling()->handleReport(['type' => 'end-of-call-report', 'assistant' => ['id' => $call->voice_session_id]]);

    expect(Message::sole()->body)->toBe('📞 Missed call')->and(app(Entitlements::class)->used($this->user, Metric::VoiceSeconds))->toBe(0)
        ->and(LlmCall::count())->toBe(0); // no usage reported, nothing to log
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
    expect(calling()->handleReport(['assistant' => ['id' => 'someone-else']]))->toBeFalse()
        ->and(calling()->handleStatus(['status' => 'in-progress']))->toBeFalse();
});

it('follows the voice service\'s status updates', function () {
    $call = calling()->start($this->user, $this->entry);

    calling()->handleStatus(['status' => 'in-progress', 'assistant' => ['id' => $call->voice_session_id]]);
    expect($call->refresh()->status)->toBe(Call::ACTIVE);

    calling()->handleStatus(['status' => 'ringing', 'assistant' => ['id' => $call->voice_session_id]]);
    expect($call->refresh()->status)->toBe(Call::ACTIVE);

    calling()->handleStatus(['status' => 'ended', 'assistant' => ['id' => $call->voice_session_id]]);
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

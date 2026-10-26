<?php

use App\Enums\Metric;
use App\Jobs\SendContactReply;
use App\Models\Call;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Calling;
use App\Services\Entitlements;
use App\Services\Phonebook;
use App\Services\Texting;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;

// Coming back: answering a contact's call, and a worried contact's relief.

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Jamie Rivera', 'last_seen_at' => now()]);
    app(Entitlements::class)->assignPlan($this->user, Plan::where('slug', 'paid')->firstOrFail(), null);
    $this->mom = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create(['name' => 'Linda Rivera', 'gender' => 'woman']), Relationship::bySlug('mom'));
});

function worried(int $stage = 4, int $hours = 80): void
{
    test()->mom->forceFill(['worry_stage' => $stage, 'last_heard_at' => now()->subHours($hours), 'worry_started_at' => now()->subDay()])->save();
}

function ringingCall(?string $tone = 'trying to call; worried'): Call
{
    $call = test()->mom->calls()->create(['direction' => 'incoming', 'status' => 'ringing']);
    if ($tone) {
        test()->mom->outreachEvents()->create(['kind' => 'call', 'stage' => 3, 'tone' => $tone, 'due_at' => now(), 'status' => 'done', 'call_id' => $call->id]);
    }

    return $call;
}

it('picks up a ringing call: the same call, and the contact says why they called', function () {
    $ringing = ringingCall();

    $call = app(Calling::class)->start($this->user, $this->mom, $ringing);

    $prompt = $this->voice->sessions[$call->voice_session_id]['prompt'];
    expect($call->id)->toBe($ringing->id)
        ->and($call->only(['direction', 'status', 'max_seconds']))->toBe(['direction' => 'incoming', 'status' => 'starting', 'max_seconds' => Calling::MAX_SECONDS])
        ->and($prompt)->toContain("# This call\nYou called Jamie and they just picked up. Say hello and why you called: trying to call; worried.")
        ->and(hash('sha256', $prompt))->toBe($call->prompt_hash);
});

it('says the contact called to catch up when there\'s no reason on file', function () {
    $call = app(Calling::class)->start($this->user, $this->mom, ringingCall(null));

    expect($this->voice->sessions[$call->voice_session_id]['prompt'])->toContain('why you called: to catch up.');
});

it('won\'t pick up a call that already ended, or someone else\'s', function () {
    $ended = ringingCall();
    $ended->update(['status' => 'missed']);
    expect(fn () => app(Calling::class)->start($this->user, $this->mom, $ended))->toThrow(DomainException::class, 'That call already ended.');

    $sister = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create(['gender' => 'woman']), Relationship::bySlug('sister'));
    expect(fn () => app(Calling::class)->start($this->user, $sister, ringingCall()))->toThrow(DomainException::class, 'That call already ended.');
});

it('ends the worry with a call, and the contact is relieved on it', function () {
    worried(4, 80);

    $call = app(Calling::class)->start($this->user, $this->mom);

    expect($this->voice->sessions[$call->voice_session_id]['prompt'])
        ->toContain('# Before this call')->toContain("you hadn't heard from Jamie in about 3 days")->toContain('thank goodness')
        ->and($this->mom->fresh()->only(['worry_stage', 'relief_stage']))->toBe(['worry_stage' => 0, 'relief_stage' => null]);
});

it('ends the worry with a text, and the reply is relieved, once', function () {
    Queue::fake();
    worried(3, 60);

    $text = app(Texting::class)->send($this->user, $this->mom, 'Hi Mom, sorry, crazy week');
    expect($this->mom->fresh()->only(['worry_stage', 'relief_stage', 'relief_hours']))->toBe(['worry_stage' => 0, 'relief_stage' => 3, 'relief_hours' => 60]);
    Queue::assertPushed(SendContactReply::class);

    app(Texting::class)->reply($text);
    $last = end($this->llm->calls[0]['messages']);
    expect($last)->toBe(['role' => 'system', 'content' => $this->mom->fresh()->forceFill(['relief_stage' => 3, 'relief_hours' => 60])->reliefNote()])
        ->and($this->mom->fresh()->relief_stage)->toBeNull();

    // The next reply is back to normal.
    app(Texting::class)->reply(app(Texting::class)->send($this->user, $this->mom, 'How are you?'));
    expect(end($this->llm->calls[1]['messages'])['role'])->toBe('user');
});

it('replies knowing texts written for a backlog, in time order', function () {
    Queue::fake();
    $old = new Message(['sender' => 'contact', 'body' => 'Hope your trip went well!']);
    $old->created_at = now()->subHours(3);
    $this->mom->messages()->save($old);
    $text = app(Texting::class)->send($this->user, $this->mom, 'It did!');

    app(Texting::class)->reply($text);

    expect(array_slice(array_column($this->llm->calls[0]['messages'], 'content'), 1))->toBe(['Hope your trip went well!', 'It did!']);
});

it('answers from the call page, or turns the ring into a missed call when it can\'t', function () {
    $ringing = ringingCall();
    $this->actingAs($this->user)->get(route('app.call', ['contact' => $this->mom->contact, 'answer' => $ringing->id]))->assertSee('data-answering', false);
    $this->actingAs($this->user)->get(route('app.call', $this->mom->contact))->assertDontSee('data-answering', false);

    Volt::actingAs($this->user)->test('app.call', ['contact' => $this->mom->contact])->set('answer', $ringing->id)
        ->call('start')
        ->assertSet('state', 'connecting')->assertSet('answer', null)
        ->assertDispatched('voice-start', callId: $ringing->id);

    app(Entitlements::class)->consume($this->user, Metric::VoiceSeconds, 300 * 60);
    $another = ringingCall();
    Volt::actingAs($this->user)->test('app.call', ['contact' => $this->mom->contact])->set('answer', $another->id)
        ->call('start')->assertSet('state', 'idle')->assertSee('used this month&#039;s call time', false);
    expect($another->fresh()->status)->toBe('missed');
});

it('declines a ringing call, only for its own user', function () {
    $call = ringingCall();

    $this->actingAs(User::factory()->create(['last_seen_at' => now()]))->post(route('app.calls.decline', $call))->assertNotFound();
    expect($call->fresh()->status)->toBe('ringing');

    $this->actingAs($this->user)->post(route('app.calls.decline', $call))->assertNoContent();
    expect($call->fresh()->status)->toBe('missed')->and(Message::where('body', '📞 Missed call')->exists())->toBeTrue();
});

<?php

use App\Events\ContactCalling;
use App\Events\ContactMessaged;
use App\Events\NoticeSent;
use App\Events\RingingStopped;
use App\Jobs\ExpireRinging;
use App\Jobs\WriteOutreach;
use App\Models\Call;
use App\Models\Contact;
use App\Models\Message;
use App\Models\OutreachEvent;
use App\Models\Plan;
use App\Models\Relationship;
use App\Models\User;
use App\Models\UserContact;
use App\Services\Catchup;
use App\Services\Entitlements;
use App\Services\Outreach;
use App\Services\Phonebook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// Carrying out reach-outs: calls ring or are missed, texts are written only when the user is there, in order,
// with their original times.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 15:00:00', 'UTC'));
    $this->user = User::factory()->create(['name' => 'Jamie Rivera', 'last_seen_at' => now()->subHours(30)]); // offline
    app(Entitlements::class)->assignPlan($this->user, Plan::where('slug', 'paid')->firstOrFail(), null);
    $this->mom = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create(['name' => 'Linda Rivera', 'gender' => 'woman']), Relationship::bySlug('mom'));
    $this->mom->forceFill(['last_heard_at' => now()->subHours(50), 'worry_stage' => 3])->save();
});

function reachOut(UserContact $entry, string $kind, int $hoursAgo = 1, string $tone = 'gentle concern'): OutreachEvent
{
    return $entry->outreachEvents()->create(['kind' => $kind, 'stage' => 3, 'tone' => $tone, 'due_at' => now()->subHours($hoursAgo)]);
}

function goOnline(): void
{
    test()->user->forceFill(['last_seen_at' => now()])->save();
}

it('turns a call while the user is away into a missed call at its time, and a voicemail to write later', function () {
    $event = reachOut($this->mom, 'call', 3);

    app(Outreach::class)->deliverDue($this->user);

    $call = Call::sole();
    $missed = Message::sole();
    expect($call->only(['direction', 'status']))->toBe(['direction' => 'incoming', 'status' => 'missed'])
        ->and($call->created_at->equalTo($event->due_at))->toBeTrue()
        ->and($missed->body)->toBe('📞 Missed call')
        ->and($missed->meta)->toEqual(['call_id' => $call->id, 'missed' => true])
        ->and($missed->created_at->equalTo($event->due_at))->toBeTrue()
        ->and($missed->cameUnasked())->toBeTrue()
        ->and($event->fresh()->only(['status', 'call_id']))->toBe(['status' => 'done', 'call_id' => $call->id])
        ->and(OutreachEvent::where('kind', 'voicemail')->sole()->only(['status', 'call_id', 'tone']))->toBe(['status' => 'planned', 'call_id' => $call->id, 'tone' => 'gentle concern'])
        ->and($this->llm->calls)->toBe([]); // nothing written while they're away
});

it('leaves no voicemail on a plan without voicemail', function () {
    app(Entitlements::class)->assignPlan($this->user, Plan::where('slug', 'free')->firstOrFail(), null);
    reachOut($this->mom, 'call');

    app(Outreach::class)->deliverDue($this->user);

    expect(Call::sole()->status)->toBe('missed')->and(OutreachEvent::where('kind', 'voicemail')->exists())->toBeFalse();
});

it('rings the user\'s open page, and a call nobody answers becomes a missed call', function () {
    Event::fake([ContactCalling::class, RingingStopped::class, ContactMessaged::class]);
    Queue::fake();
    goOnline();
    reachOut($this->mom, 'call', 0);

    app(Outreach::class)->deliverDue($this->user);

    $call = Call::sole();
    expect($call->status)->toBe('ringing')->and(Message::count())->toBe(0);
    Event::assertDispatched(ContactCalling::class, fn ($e) => $e->call->is($call) && $e->user->is($this->user));
    Queue::assertPushed(ExpireRinging::class, fn ($job) => $job->callId === $call->id && $job->delay->equalTo(now()->addSeconds(Outreach::RING_SECONDS)));

    (new ExpireRinging($call->id))->handle(app(Outreach::class));

    expect($call->fresh()->status)->toBe('missed')->and($call->fresh()->ended_at)->not->toBeNull()
        ->and(Message::sole()->body)->toBe('📞 Missed call');
    Event::assertDispatched(RingingStopped::class);
    Event::assertDispatched(ContactMessaged::class);
    Queue::assertPushed(WriteOutreach::class, fn ($job) => $job->userId === $this->user->id); // the voicemail
});

it('does nothing to a call that was answered or is already over', function () {
    Event::fake([RingingStopped::class]);
    $call = $this->mom->calls()->create(['direction' => 'incoming', 'status' => 'starting']);

    (new ExpireRinging($call->id))->handle(app(Outreach::class));
    (new ExpireRinging(999))->handle(app(Outreach::class));

    expect($call->fresh()->status)->toBe('starting')->and(Message::count())->toBe(0);
    Event::assertNotDispatched(RingingStopped::class);
});

it('doesn\'t ring through a call the user is already on', function () {
    Event::fake([ContactCalling::class]);
    goOnline();
    $sister = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create(['gender' => 'woman']), Relationship::bySlug('sister'));
    $sister->calls()->create(['status' => 'active', 'prompt_hash' => str_repeat('a', 64), 'max_seconds' => 600]);
    reachOut($this->mom, 'call', 0);

    app(Outreach::class)->deliverDue($this->user);

    expect($this->mom->calls()->sole()->status)->toBe('missed');
    Event::assertNotDispatched(ContactCalling::class);
});

it('keeps texts unwritten while the user is away', function () {
    $event = reachOut($this->mom, 'text');

    app(Outreach::class)->deliverDue($this->user);

    expect($event->fresh()->status)->toBe('planned')->and(Message::count())->toBe(0)->and($this->llm->calls)->toBe([]);
});

it('writes a backlog in order when the user is back, each knowing the ones before, with their original times', function () {
    goOnline();
    $first = reachOut($this->mom, 'text', 5, 'a casual check-in');
    $call = reachOut($this->mom, 'call', 4);
    $second = reachOut($this->mom, 'text', 2, 'trying to call; worried');
    $this->llm->reply("Hey honey, how was your week?\n\nCall me when you can", "It's Mom. Just wanted to hear your voice. Call me back.", 'Tried calling you, everything ok?');

    app(Outreach::class)->deliverDue($this->user);

    // The call from four hours ago doesn't ring now: it was missed back then, and left a voicemail.
    $thread = $this->mom->messages()->orderBy('created_at')->orderBy('id')->get();
    expect($thread->pluck('body')->all())->toBe(['Hey honey, how was your week?', 'Call me when you can', '📞 Missed call', "It's Mom. Just wanted to hear your voice. Call me back.", 'Tried calling you, everything ok?'])
        ->and($thread[0]->created_at->equalTo($first->due_at))->toBeTrue()
        ->and($thread[0]->meta)->toBe(['outreach' => $first->id])
        ->and($thread[2]->created_at->equalTo($call->due_at))->toBeTrue()
        ->and($thread[3]->created_at->equalTo($call->due_at))->toBeTrue()
        ->and($thread[4]->created_at->equalTo($second->due_at))->toBeTrue()
        ->and($first->fresh()->only(['status', 'message_id']))->toBe(['status' => 'done', 'message_id' => $thread[0]->id])
        ->and($call->fresh()->status)->toBe('done')
        ->and(Call::sole()->status)->toBe('missed');

    // The voicemail is one message, marked as a voicemail.
    expect($thread[3]->meta)->toEqual(['voicemail' => true, 'call_id' => Call::sole()->id])
        ->and(end($this->llm->calls[1]['messages'])['content'])->toContain("didn't pick up")->toContain('voicemail');

    // The last text was written knowing about everything before it.
    $prompt = $this->llm->calls[2]['messages'];
    expect(array_column($prompt, 'content'))->toContain('Call me when you can', '📞 Missed call', "It's Mom. Just wanted to hear your voice. Call me back.")
        ->and(end($prompt)['role'])->toBe('system')
        ->and(end($prompt)['content'])->toContain("you're texting Jamie first")->toContain('trying to call; worried')->toContain('Wednesday 1:00 PM')
        ->and(end($prompt)['content'])->toContain('in 2 days')->toContain('never mention police');
});

it('stops at a text the AI can\'t write, keeping the order for next time', function () {
    goOnline();
    $first = reachOut($this->mom, 'text', 3);
    $second = reachOut($this->mom, 'text', 2);
    $this->llm->failAll();

    app(Outreach::class)->deliverDue($this->user);

    expect($first->fresh()->status)->toBe('planned')->and($second->fresh()->status)->toBe('planned')
        ->and(count($this->llm->calls))->toBe(2); // the model and its fallback, for the first text only
});

it('rewrites a text that uses guilt, and drops it if it still does', function (array $replies, ?string $sent) {
    goOnline();
    $event = reachOut($this->mom, 'text');
    $this->llm->reply(...$replies);

    app(Outreach::class)->deliverDue($this->user);

    expect(Message::first()?->body)->toBe($sent)
        ->and($event->fresh()->status)->toBe($sent ? 'done' : 'skipped')
        ->and(count($this->llm->calls))->toBe(2)
        ->and(end($this->llm->calls[1]['messages'])['content'])->toContain('kinder');
})->with([
    'better the second time' => [['I guess I don\'t matter to you anymore', 'Thinking of you, call when you can'], 'Thinking of you, call when you can'],
    'still not okay' => [['You abandoned me', 'If you don\'t answer I\'m calling the police'], null],
]);

it('knows guilt, pressure and pretend emergencies when it sees them', function (string $text, bool $crosses) {
    expect(app(Outreach::class)->crossesLine($text))->toBe($crosses);
})->with([
    ['Hey you! How was the game?', false],
    ['Thinking of you. Call me when you can 💛', false],
    ['Did you forget about me?', true],
    ['I guess I\'m not important', true],
    ['After everything I did for you', true],
    ['You\'ll regret this', true],
    ['If you don\'t call back I\'ll worry', true],
    ['Should I call the hospital?', true],
    ['I almost called 911', true],
    ['You don\'t even care', true],
]);

it('writes for online users in the background and handles calls inline for everyone else', function () {
    Queue::fake();
    Event::fake([ContactCalling::class]);
    $online = User::factory()->create(['last_seen_at' => now()]);
    $theirs = app(Phonebook::class)->add($online, Contact::factory()->published()->create());
    reachOut($theirs, 'text');
    reachOut($this->mom, 'call');
    reachOut($this->mom, 'text');

    expect(app(Outreach::class)->deliverAll())->toBe(2);

    Queue::assertPushed(WriteOutreach::class, 1);
    Queue::assertPushed(WriteOutreach::class, fn ($job) => $job->userId === $online->id);
    expect($this->mom->calls()->sole()->status)->toBe('missed');
});

it('writes the backlog when the user comes back, then welcomes them', function () {
    Event::fake([NoticeSent::class]);
    reachOut($this->mom, 'text', 3);
    reachOut($this->mom, 'text', 2);

    Queue::fake();
    $this->actingAs($this->user)->post(route('app.presence'))->assertNoContent();
    Queue::assertPushed(WriteOutreach::class, fn ($job) => $job->userId === $this->user->id && $job->welcome);

    (new WriteOutreach($this->user->id, true))->handle(app(Outreach::class), app(Catchup::class));

    expect(Message::count())->toBe(2);
    Event::assertDispatched(NoticeSent::class, fn ($e) => $e->message === 'Welcome back! Linda texted you 2 times.');
});

it('writes without a welcome after a short break, and skips a user that\'s gone', function () {
    Event::fake([NoticeSent::class]);
    goOnline();
    reachOut($this->mom, 'text');

    (new WriteOutreach($this->user->id))->handle(app(Outreach::class), app(Catchup::class));
    (new WriteOutreach(999, true))->handle(app(Outreach::class), app(Catchup::class));

    expect(Message::count())->toBe(1)->and((new WriteOutreach($this->user->id))->uniqueId())->toBe((string) $this->user->id);
    Event::assertNotDispatched(NoticeSent::class);
});

it('runs every minute: plans, then delivers', function () {
    Queue::fake();
    goOnline(); // Mom, quiet for 50 hours, texts and calls

    $this->artisan('outreach:run')->expectsOutput('Planned 2 reach-outs; delivered for 1 users.')->assertSuccessful();
    $this->artisan('schedule:list')->expectsOutputToContain('outreach:run');
});

it('shares a ringing call with the page: who, their picture, and where Answer and Decline go', function () {
    $call = $this->mom->calls()->create(['direction' => 'incoming', 'status' => 'ringing']);
    $event = new ContactCalling($this->user, $call, $this->mom);

    expect($event->broadcastAs())->toBe('ringing')
        ->and($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$this->user->id)
        ->and($event->broadcastWith())->toBe([
            'call_id' => $call->id,
            'user_contact_id' => $this->mom->id,
            'name' => 'Linda',
            'avatar' => $this->mom->contact->avatarUrl(),
            'answer_url' => route('app.call', ['contact' => $this->mom->contact, 'answer' => $call->id]),
            'decline_url' => route('app.calls.decline', $call),
        ]);

    $stopped = new RingingStopped($this->user, $call);
    expect($stopped->broadcastAs())->toBe('ring-stopped')->and($stopped->broadcastWith())->toBe(['call_id' => $call->id])
        ->and($stopped->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$this->user->id);
});

<?php

use App\Enums\ModerationCategory;
use App\Models\Contact;
use App\Models\ModerationEvent;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Outreach;
use App\Services\Phonebook;
use App\Services\PromptBuilder;
use Livewire\Volt\Volt;

// Suspension, the safety queue, the terms, and being honest about AI.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->user = User::factory()->create(['email' => 'jamie@example.com', 'last_seen_at' => now()]);
});

function safetyEvent(User $user, string $category = 'minors', string $action = 'blocked', array $extra = []): ModerationEvent
{
    return ModerationEvent::create(['user_id' => $user->id, 'source' => 'user_text', 'category' => $category, 'action' => $action, 'excerpt' => "something {$category}", ...$extra]);
}

it('keeps a suspended account out of the app, with a way to ask about it', function () {
    $suspended = User::factory()->suspended()->create();

    $this->actingAs($suspended)->get(route('app.home'))->assertForbidden()->assertSee('Your account is suspended')->assertSee(config('legal.contact_email'));
    $this->actingAs($suspended)->post(route('app.presence'))->assertForbidden();
    $this->actingAs($this->user)->get(route('app.home'))->assertOk();
});

it('stops contacts reaching out to a suspended account', function () {
    $entry = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create(['gender' => 'woman']), Relationship::bySlug('mom'));
    $entry->forceFill(['last_heard_at' => now()->subHours(50)])->save();
    $this->travelTo(now()->setTime(12, 0));
    $this->user->forceFill(['last_seen_at' => now()])->save();
    $this->user->suspend('Testing', $this->admin);

    expect(app(Outreach::class)->planAll())->toBe(0);

    $this->user->unsuspend();
    expect(app(Outreach::class)->planAll())->toBeGreaterThan(0);
});

it('suspends and unsuspends from the user page, with a reason', function () {
    $page = Volt::actingAs($this->admin)->test('admin.users.show', ['user' => $this->user]);
    $page->call('suspend')->assertHasErrors('suspendReason');

    $page->set('suspendReason', 'Repeated blocked texts')->call('suspend')->assertHasNoErrors()->assertSee('Repeated blocked texts');
    expect($this->user->fresh()->isSuspended())->toBeTrue()
        ->and($this->user->fresh()->flags)->toEqual(['suspension_reason' => 'Repeated blocked texts', 'suspended_by' => $this->admin->id]);

    $page->call('unsuspend');
    expect($this->user->fresh()->isSuspended())->toBeFalse()->and($this->user->fresh()->flags)->toBeNull();
});

it('keeps strikes when unsuspending, and won\'t let an admin suspend themselves', function () {
    $this->user->forceFill(['flags' => ['strikes' => 2]])->save();
    $this->user->suspend('x', $this->admin);
    $this->user->unsuspend();
    expect($this->user->fresh()->flags)->toBe(['strikes' => 2]);

    Volt::actingAs($this->admin)->test('admin.users.show', ['user' => $this->admin])->set('suspendReason', 'oops')->call('suspend')->assertStatus(422);
    expect($this->admin->fresh()->isSuspended())->toBeFalse();
    $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))->assertSee('2 blocked texts')->assertSee('Safety events');
});

it('lists what needs review, filters it, and marks it reviewed', function () {
    $minors = safetyEvent($this->user, 'minors', 'blocked', ['details' => ['strikes' => 3]]);
    $crisis = safetyEvent($this->user, 'self_harm', 'flagged');
    $other = safetyEvent(User::factory()->create(), 'real_person');
    $done = safetyEvent($this->user, 'explicit', 'blocked', ['reviewed_at' => now()]);

    $this->actingAs($this->admin)->get(route('admin.moderation'))->assertOk()->assertSee('3 items to review')->assertSee('3 strikes')
        ->assertSee('something minors')->assertDontSee('something explicit');

    $page = Volt::actingAs($this->admin)->test('admin.moderation');
    $page->set('category', 'self_harm')->assertSee('something self_harm')->assertDontSee('something minors');
    $page->set('category', '')->set('user', $other->user_id)->assertSee('something real_person')->assertDontSee('something minors')->assertSee($other->user->email);
    $page->set('user', null)->set('show', 'all')->assertSee('something explicit');

    $page->call('review', $crisis->id);
    expect($crisis->fresh()->reviewed_at)->not->toBeNull()->and($crisis->fresh()->reviewed_by)->toBe($this->admin->id)
        ->and($minors->fresh()->reviewed_at)->toBeNull();
});

it('suspends from the queue', function () {
    $event = safetyEvent($this->user);

    Volt::actingAs($this->admin)->test('admin.moderation')->call('suspend', $event->id)->assertSee('Nothing here.'); // reviewed, so off the list

    expect($this->user->fresh()->isSuspended())->toBeTrue()
        ->and($this->user->fresh()->flags['suspension_reason'])->toBe("Safety: Minors (event #{$event->id})")
        ->and($event->fresh()->reviewed_at)->not->toBeNull();

    $mine = safetyEvent($this->admin);
    Volt::actingAs($this->admin)->test('admin.moderation')->call('suspend', $mine->id)->assertStatus(422);
});

it('shows the queue to admins only, with a count in the menu', function () {
    safetyEvent($this->user);

    $this->actingAs($this->user)->get(route('admin.moderation'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.home'))->assertSee(route('admin.moderation'))->assertSee('Safety');
    $this->actingAs($this->admin)->get(route('admin.moderation', ['show' => 'open']))->assertDontSee('Nothing here.');
    ModerationEvent::query()->update(['reviewed_at' => now()]);
    $this->actingAs($this->admin)->get(route('admin.moderation'))->assertSee('Nothing here.')->assertSee('0 items to review');
});

it('records the terms accepted at sign-up', function () {
    $this->get(route('register'))->assertSee(route('legal.terms'))->assertSee(route('legal.privacy'))->assertSee(route('legal.ai'));

    Volt::test('auth.register')->set('name', 'Pat Lee')->set('email', 'pat@example.com')->set('password', 'password')
        ->set('password_confirmation', 'password')->set('date_of_birth', now()->subYears(40)->toDateString())->set('timezone', 'UTC')
        ->call('register')->assertHasErrors('terms')
        ->set('terms', true)->call('register')->assertHasNoErrors();

    $pat = User::firstWhere('email', 'pat@example.com');
    expect($pat->terms_version)->toBe(config('legal.terms_version'))->and($pat->terms_accepted_at)->not->toBeNull();
});

it('asks everyone to accept changed terms before using the app', function () {
    $old = User::factory()->withOldTerms()->create();

    $this->actingAs($old)->get(route('app.contacts'))->assertRedirect(route('terms.accept'));
    $this->actingAs($old)->postJson(route('app.presence'))->assertStatus(409);
    $this->actingAs($old)->get(route('terms.accept'))->assertOk()->assertSee('We updated our terms');

    Volt::actingAs($old)->test('app.terms')->call('accept')->assertHasErrors('terms')
        ->set('terms', true)->call('accept')->assertRedirect(route('app.contacts')); // back where they were going
    expect($old->fresh()->hasAcceptedTerms())->toBeTrue();

    Volt::actingAs($old->fresh())->test('app.terms')->assertRedirect(route('app.home'));
});

it('publishes the terms, privacy policy and AI disclosure', function (string $route, string $see) {
    $this->get(route($route))->assertOk()->assertSee($see)->assertSee('Version '.config('legal.terms_version'));
})->with([
    ['legal.terms', 'Terms of Service'],
    ['legal.privacy', 'Privacy Policy'],
    ['legal.ai', 'not a real person'],
]);

it('names the company on the terms and privacy pages, and 988 on the AI page', function () {
    $this->get(route('legal.terms'))->assertSee('120 Customs LLC')->assertSee(config('legal.contact_email'));
    $this->get(route('legal.privacy'))->assertSee('Groq')->assertSee('Vapi');
    $this->get(route('legal.ai'))->assertSee('988')->assertSee("they're an AI", false);
});

it('tells every contact to be honest about being an AI, and never to play younger', function () {
    $entry = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create());
    $prompt = app(PromptBuilder::class)->build($entry)->text;

    expect($prompt)->toContain("say honestly that you're an AI companion")
        ->toContain('never play or pretend to be younger or a teenager')
        ->toContain("never take on a real person's identity");
});

it('ties an event to what it was about', function () {
    $entry = app(Phonebook::class)->add($this->user, Contact::factory()->published()->create());
    $message = $entry->messages()->create(['sender' => 'user', 'body' => 'hi']);
    $event = safetyEvent($this->user, ModerationCategory::SelfHarm->value, 'flagged');

    $event->about($message);

    expect($event->fresh()->only(['subject_type', 'subject_id']))->toBe(['subject_type' => 'message', 'subject_id' => $message->id]);
});

<?php

use App\Models\Contact;
use App\Models\OutreachEvent;
use App\Models\Plan;
use App\Models\Relationship;
use App\Models\User;
use App\Models\UserContact;
use App\Services\Entitlements;
use App\Services\Outreach;
use App\Services\Phonebook;
use Carbon\CarbonImmutable;

// Contacts noticing the user's silence: worry stages by relationship, plan caps, and the guardrails.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 08:00:00', 'UTC')); // a Monday morning
    $this->user = User::factory()->create(['last_seen_at' => now()]);
});

function onPlan(string $slug): void
{
    app(Entitlements::class)->assignPlan(test()->user, Plan::where('slug', $slug)->firstOrFail(), null);
}

function relative(string $relationship, string $gender = 'woman', bool $voice = true): UserContact
{
    $contact = Contact::factory()->published()->create(['gender' => $gender]);
    if (! $voice) {
        $contact->forceFill(['voice' => null])->save();
    }

    return app(Phonebook::class)->add(test()->user, $contact, Relationship::bySlug($relationship));
}

/** Hour by hour: the user keeps opening the app (unless $present is false) but never texts or calls this contact. */
function liveHours(UserContact $entry, int $hours, bool $present = true): void
{
    for ($i = 0; $i < $hours; $i++) {
        test()->travel(1)->hours();
        if ($present) {
            test()->user->forceFill(['last_seen_at' => now()])->save();
        }
        app(Outreach::class)->plan($entry->fresh());
    }
}

/** A worried contact: quiet for this many hours. */
function quietFor(UserContact $entry, int $hours): UserContact
{
    $entry->forceFill(['last_heard_at' => now()->subHours($hours)])->save();

    return $entry->fresh();
}

it('moves each relationship through its worry stages over a week of silence', function (string $relationship, string $gender, array $checkpoints) {
    onPlan('paid');
    $entry = relative($relationship, $gender);

    $elapsed = 0;
    foreach ($checkpoints as $hour => $stage) {
        liveHours($entry, $hour - $elapsed);
        $elapsed = $hour;
        expect($entry->fresh()->worry_stage)->toBe($stage, "{$relationship} after {$hour} hours");
    }
    expect($entry->fresh()->worry_started_at)->not->toBeNull();
})->with([
    'mom' => ['mom', 'woman', [23 => 0, 24 => 1, 36 => 2, 48 => 3, 72 => 4, 96 => 5, 168 => 5]],
    'sister' => ['sister', 'woman', [35 => 0, 36 => 1, 60 => 2, 72 => 3, 100 => 4, 120 => 5]],
    'grandparent' => ['grandparent', 'man', [71 => 0, 72 => 1, 120 => 2, 168 => 2]],
    'partner' => ['partner', 'man', [7 => 0, 8 => 1, 24 => 2, 48 => 3]],
    'best friend' => ['best-friend', 'woman', [59 => 0, 60 => 1, 96 => 2, 120 => 3]],
    'uncle' => ['uncle', 'man', [95 => 0, 96 => 1, 168 => 2]],
    'coworker' => ['coworker', 'man', [167 => 0, 168 => 1]],
]);

it('caps worry at the plan\'s stage', function (string $plan, int $stage) {
    onPlan($plan);
    app(Outreach::class)->plan(quietFor(relative('mom'), 100));

    expect(UserContact::sole()->worry_stage)->toBe($stage);
})->with(['free' => ['free', 2], 'paid' => ['paid', 5]]);

it('reaches out a handful of times over a silent week, not dozens', function () {
    onPlan('paid');
    $entry = relative('mom');
    liveHours($entry, 7 * 24);

    $texts = $entry->outreachEvents()->where('kind', OutreachEvent::TEXT)->count();
    expect($texts)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(Outreach::MAX_UNANSWERED_TEXTS + 5 - 1)
        ->and($entry->outreachEvents()->where('kind', OutreachEvent::CALL)->count())->toBeGreaterThan(0)
        ->and($entry->outreachEvents()->pluck('due_at')->every(fn ($at) => $at->hour >= Outreach::QUIET_UNTIL && $at->hour < Outreach::QUIET_FROM))->toBeTrue();
});

it('waits for a reply after a few unanswered texts', function (int $unanswered, int $planned) {
    onPlan('paid');
    $entry = quietFor(relative('mom', voice: false), 73); // stage 4: two texts a day, up to 2 + 3 unanswered
    foreach (range(1, $unanswered) as $i) {
        $entry->outreachEvents()->create(['kind' => 'text', 'stage' => 1, 'tone' => 'hi', 'due_at' => now()->subHours(25 + $i)]);
    }

    expect(app(Outreach::class)->plan($entry))->toHaveCount($planned);
})->with(['room for one more' => [4, 1], 'waiting' => [5, 0]]);

it('spaces reach-outs through the day', function () {
    onPlan('paid');
    $entry = quietFor(relative('mom', voice: false), 73); // two texts a day: twelve hours apart
    $entry->outreachEvents()->create(['kind' => 'text', 'stage' => 4, 'tone' => 'hi', 'due_at' => now()->subHours(11)]);
    expect(app(Outreach::class)->plan($entry))->toBeEmpty();

    $this->travel(1)->hours();
    $this->user->forceFill(['last_seen_at' => now()])->save();
    expect(app(Outreach::class)->plan($entry->fresh()))->toHaveCount(1);
});

it('calls only when the stage calls for it and the contact has a voice', function () {
    onPlan('paid');
    $kinds = fn (UserContact $entry) => collect(app(Outreach::class)->plan($entry))->pluck('kind')->all();

    expect($kinds(quietFor(relative('mom'), 49)))->toBe(['text', 'call'])
        ->and($kinds(quietFor(relative('sister', voice: false), 73)))->toBe(['text'])
        ->and($kinds(quietFor(relative('coworker', 'man'), 169)))->toBe(['text']);
});

it('stops reaching out once the user has stayed away longer than their plan allows', function (string $plan, int $hoursAway, array $kinds) {
    onPlan($plan);
    $this->user->forceFill(['last_seen_at' => now()->subHours($hoursAway)])->save();

    expect(collect(app(Outreach::class)->plan(quietFor(relative('mom'), 100)))->pluck('kind')->all())->toBe($kinds);
})->with([
    'free, 47 hours' => ['free', 47, ['text']], // Free stops at stage 2: texts only
    'free, 49 hours' => ['free', 49, []],
    'paid, 13 days' => ['paid', 13 * 24, ['text', 'call']],
    'paid, 15 days' => ['paid', 15 * 24, []],
]);

it('lets nobody worry in away mode', function () {
    onPlan('paid');
    $entry = quietFor(relative('mom'), 100);
    $entry->forceFill(['worry_stage' => 4])->save();
    $this->user->forceFill(['away_until' => now()->addDays(3)])->save();

    expect(app(Outreach::class)->plan($entry->fresh()))->toBeEmpty()
        ->and($entry->fresh()->worry_stage)->toBe(0);
});

it('follows the user\'s worry setting for each contact', function () {
    onPlan('paid');
    $off = quietFor(relative('mom'), 100);
    $off->update(['worry_setting' => 'off']);
    expect(app(Outreach::class)->plan($off->fresh()))->toBeEmpty();

    $chill = quietFor(relative('sister'), 30); // counts as 15 hours: not yet
    $chill->update(['worry_setting' => 'chill']);
    app(Outreach::class)->plan($chill->fresh());
    expect($chill->fresh()->worry_stage)->toBe(0);

    app(Outreach::class)->plan(quietFor($chill, 80)); // counts as 40 hours: stage 1
    expect($chill->fresh()->worry_stage)->toBe(1);
});

it('never reaches out at night where the user lives', function () {
    onPlan('paid');
    $this->user->update(['timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 22:30:00', 'America/Chicago'));
    $this->user->forceFill(['last_seen_at' => now()])->save();
    $entry = quietFor(relative('mom'), 50);

    expect(app(Outreach::class)->plan($entry))->toBeEmpty()
        ->and($entry->fresh()->worry_stage)->toBe(3) // still worried, just not texting at 10:30 PM
        ->and(app(Outreach::class)->isQuietHours($this->user->fresh()))->toBeTrue();
});

it('plans for everyone, skipping people who never opened the app and inactive contacts', function () {
    onPlan('paid');
    quietFor(relative('mom'), 49);
    $paused = quietFor(relative('sister'), 73);
    $paused->update(['is_active' => false]);
    $newcomer = User::factory()->create(['last_seen_at' => null]);
    app(Phonebook::class)->add($newcomer, Contact::factory()->published()->create());

    expect(app(Outreach::class)->planAll())->toBe(2)
        ->and(app(Outreach::class)->plan($paused->fresh()))->toBeEmpty();
});

it('turns worry into relief when the user gets in touch, dropping reach-outs planned for later', function () {
    onPlan('paid');
    $entry = quietFor(relative('mom'), 74);
    $entry->forceFill(['worry_stage' => 4, 'worry_started_at' => now()->subDays(2)])->save();
    $due = $entry->outreachEvents()->create(['kind' => 'text', 'stage' => 4, 'tone' => 'worried', 'due_at' => now()->subHour()]);
    $later = $entry->outreachEvents()->create(['kind' => 'call', 'stage' => 4, 'tone' => 'worried', 'due_at' => now()->addHour()]);

    $entry->markHeard();

    expect($entry->only(['worry_stage', 'relief_stage', 'relief_hours']))->toBe(['worry_stage' => 0, 'relief_stage' => 4, 'relief_hours' => 74])
        ->and($entry->worry_started_at)->toBeNull()
        ->and($entry->last_heard_at->equalTo(now()))->toBeTrue()
        ->and($due->fresh()->status)->toBe('planned') // already "sent": still written, so the user sees it
        ->and($later->fresh()->status)->toBe('skipped')
        ->and($entry->reliefNote())->toContain('in about 3 days')->toContain('thank goodness')->toContain('Never scold');

    $entry->clearRelief();
    expect($entry->fresh()->reliefNote())->toBeNull();
});

it('is only mildly relieved after a little worry, and not at all after none', function () {
    $entry = quietFor(relative('mom'), 40);
    $entry->forceFill(['worry_stage' => 2])->save();
    $entry->markHeard();
    expect($entry->reliefNote())->toContain('about 40 hours')->toContain('started to wonder')->not->toContain('thank goodness');

    $calm = relative('sister');
    $calm->forceFill(['worry_stage' => 1])->save();
    $calm->markHeard();
    expect($calm->reliefNote())->toBeNull();
});

it('starts the silence over when a removed contact is added back', function () {
    onPlan('paid');
    $entry = quietFor(relative('mom'), 100);
    $entry->forceFill(['worry_stage' => 5])->save();
    app(Phonebook::class)->remove($this->user, $entry->contact);

    $back = app(Phonebook::class)->add($this->user, $entry->contact, Relationship::bySlug('mom'));

    expect($back->worry_stage)->toBe(0)->and($back->quietSince()->equalTo(now()))->toBeTrue();
});

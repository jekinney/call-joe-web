<?php

use App\Models\Contact;
use App\Models\Plan;
use App\Models\User;
use App\Services\Catchup;
use App\Services\Entitlements;
use App\Services\Phonebook;
use App\Services\Presence;

// Welcome back: what you missed while away.

beforeEach(function () {
    // On Paid, so there's room for several contacts.
    $this->user = User::factory()->create();
    app(Entitlements::class)->assignPlan($this->user, Plan::where('slug', 'paid')->firstOrFail(), null);
});

function contactNamed(string $name)
{
    return app(Phonebook::class)->add(test()->user, Contact::factory()->published()->create(['name' => $name]));
}

function textsFrom($entry, int $count): void
{
    foreach (range(1, $count) as $i) {
        $entry->messages()->create(['sender' => 'contact', 'body' => "Text {$i} from {$entry->contact->firstName()}"]);
    }
}

it('lists contacts with unread texts, newest first, with the latest one', function () {
    $maya = contactNamed('Maya Brooks');
    $joe = contactNamed('Joe Tucker');
    textsFrom($maya, 2);
    textsFrom($joe, 1);
    $joe->messages()->create(['sender' => 'user', 'body' => 'mine']);

    $rows = app(Catchup::class)->summary($this->user);

    expect($rows->map(fn ($r) => [$r['entry']->displayName(), $r['unread'], $r['latest']->body])->all())->toBe([
        ['Joe', 1, 'Text 1 from Joe'],
        ['Maya', 2, 'Text 2 from Maya'],
    ]);
});

it('says welcome back in plain words', function (array $counts, ?string $expected) {
    foreach ($counts as $name => $count) {
        textsFrom(contactNamed($name), $count);
    }

    expect(app(Catchup::class)->welcomeMessage($this->user))->toBe($expected);
})->with([
    'nothing waiting' => [[], null],
    'one text' => [['Maya Brooks' => 1], 'Welcome back! Maya texted you.'],
    'several from one' => [['Maya Brooks' => 3], 'Welcome back! Maya texted you 3 times.'],
    'two people' => [['Maya Brooks' => 1, 'Joe Tucker' => 1], 'Welcome back! Joe and Maya texted you.'],
    'three people' => [['Maya Brooks' => 1, 'Joe Tucker' => 1, 'Ann Lee' => 1], 'Welcome back! Ann, Joe and 1 other texted you.'],
    'four people' => [['Maya Brooks' => 1, 'Joe Tucker' => 1, 'Ann Lee' => 1, 'Bo Diaz' => 1], 'Welcome back! Bo, Ann and 2 others texted you.'],
]);

it('reports how long someone was away when they come back', function () {
    $this->user->forceFill(['last_seen_at' => now()->subHours(3)])->save();

    expect(app(Presence::class)->touch($this->user))->toBe(3 * 3600)
        ->and(app(Presence::class)->touch($this->user))->toBeNull(); // now online
});

it('reports nothing on a very first visit, or while already online', function () {
    expect(app(Presence::class)->touch($this->user))->toBeNull();

    $this->travel(Presence::WRITE_EVERY_SECONDS + 5)->seconds();
    expect(app(Presence::class)->touch($this->user))->toBeNull();
});

it('welcomes them back on check-in after a long time away, when texts are waiting', function () {
    textsFrom(contactNamed('Maya Brooks'), 2);
    $this->user->forceFill(['last_seen_at' => now()->subHours(2)])->save();

    $this->actingAs($this->user)->post(route('app.presence'))->assertOk()->assertJson(['welcome' => 'Welcome back! Maya texted you 2 times.']);
});

it('says nothing after a short break, or when nothing is waiting', function () {
    textsFrom(contactNamed('Maya Brooks'), 1);
    $this->user->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    $this->actingAs($this->user)->post(route('app.presence'))->assertNoContent();

    $other = User::factory()->create(['last_seen_at' => now()->subDay()]);
    $this->actingAs($other)->post(route('app.presence'))->assertNoContent();
});

it('shows "While you were away" on the home screen', function () {
    textsFrom(contactNamed('Maya Brooks'), 2);

    $this->actingAs($this->user)->get('/app')->assertSee('While you were away')->assertSee('Text 2 from Maya');

    $quiet = User::factory()->create();
    app(Phonebook::class)->add($quiet, Contact::factory()->published()->create());
    $this->actingAs($quiet)->get('/app')->assertDontSee('While you were away');
});

<?php

use App\Enums\Metric;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use Livewire\Volt\Volt;

// /admin/users: find a user, see their usage, and set their plan.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
});

it('keeps regular users out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.show', $user))->assertForbidden();
});

it('lists users with their plan and last visit', function () {
    User::factory()->create(['name' => 'Jamie Rivera', 'last_seen_at' => now()->subMinutes(5)]);

    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Jamie Rivera')
        ->assertSee('Free')
        ->assertSee('5 minutes ago')
        ->assertSee('never'); // the admin has never checked in
});

it('searches by name or email, ignoring case', function () {
    User::factory()->create(['name' => 'Jamie Rivera', 'email' => 'jamie@example.com']);
    User::factory()->create(['name' => 'Sam Lee', 'email' => 'sam@example.com']);

    Volt::actingAs($this->admin)->test('admin.users.index')
        ->set('search', 'JAMIE')
        ->assertSee('Jamie Rivera')
        ->assertDontSee('Sam Lee')
        ->set('search', 'sam@')
        ->assertSee('Sam Lee')
        ->assertDontSee('Jamie Rivera')
        ->set('search', 'nobody')
        ->assertSee('No users match.');
});

it('shows a user\'s plan, usage and history', function () {
    $user = User::factory()->create(['name' => 'Jamie Rivera']);
    app(Entitlements::class)->consume($user, Metric::Images, 3);

    $this->actingAs($this->admin)->get(route('admin.users.show', $user))
        ->assertOk()
        ->assertSee('Jamie Rivera')
        ->assertSee('Now on Free.')
        ->assertSee('Pictures: 3 / 5')
        ->assertSee('No changes yet.');
});

it('puts a user on Paid until a date, with a reason, and records it', function () {
    $user = User::factory()->create(['timezone' => 'America/Chicago']);
    $paid = Plan::where('slug', 'paid')->firstOrFail();

    Volt::actingAs($this->admin)->test('admin.users.show', ['user' => $user])
        ->set('planId', $paid->id)
        ->set('expiresOn', now()->addMonth()->toDateString())
        ->set('reason', 'Beta tester')
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertDispatched('plan-saved')
        ->assertSee('Now on Paid.')
        ->assertSee('Free → Paid')
        ->assertSee('Ada Admin')
        ->assertSee('“Beta tester”', false);

    $user->refresh();
    expect($user->plan_id)->toBe($paid->id)
        ->and($user->plan_expires_at->timezone('America/Chicago')->format('Y-m-d H:i'))->toBe(now()->addMonth()->format('Y-m-d').' 23:59');
});

it('keeps Paid with no end date when the date is left empty', function () {
    $user = User::factory()->create();

    Volt::actingAs($this->admin)->test('admin.users.show', ['user' => $user])
        ->set('planId', Plan::where('slug', 'paid')->value('id'))
        ->call('savePlan')
        ->assertHasNoErrors();

    expect($user->refresh()->plan_expires_at)->toBeNull();
});

it('loads the current plan and end date into the form', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $paid = Plan::where('slug', 'paid')->firstOrFail();
    app(Entitlements::class)->assignPlan($user, $paid, now()->setDate(2030, 1, 15)->endOfDay());

    Volt::actingAs($this->admin)->test('admin.users.show', ['user' => $user])
        ->assertSet('planId', $paid->id)
        ->assertSet('expiresOn', '2030-01-15');
});

it('rejects a past end date or a plan that does not exist', function () {
    Volt::actingAs($this->admin)->test('admin.users.show', ['user' => User::factory()->create()])
        ->set('planId', 999999)
        ->set('expiresOn', now()->subDay()->toDateString())
        ->call('savePlan')
        ->assertHasErrors(['planId' => 'exists', 'expiresOn' => 'after_or_equal']);
});

it('is linked from the admin sidebar', function () {
    $this->actingAs($this->admin)->get('/admin')->assertSee(route('admin.users.index'));
});

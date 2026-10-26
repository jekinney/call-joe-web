<?php

use App\Enums\Metric;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;

// /app/plan: your plan, what you've used, and what each plan includes.

it('shows the plan, usage and limits', function () {
    $user = User::factory()->create();
    app(Entitlements::class)->consume($user, Metric::TextMessages, 12);
    app(Entitlements::class)->consume($user, Metric::VoiceSeconds, 180);

    $this->actingAs($user)->get(route('app.plan'))
        ->assertOk()
        ->assertSee('You&#039;re on Free.', false)
        ->assertSeeInOrder(['Texts', '12', '/ 50'])
        ->assertSeeInOrder(['Call time', '3 min', '/ 10 min'])
        ->assertSee('Contacts at once: 2')
        ->assertSee('$14.99/month')
        ->assertSee('Your plan');
});

it('shows when a plan runs out', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    app(Entitlements::class)->assignPlan($user, Plan::where('slug', 'paid')->firstOrFail(), now()->setDate(2030, 1, 15));

    $this->actingAs($user)->get(route('app.plan'))->assertSee('You&#039;re on Paid.', false)->assertSee('It runs until Jan 15, 2030.');
});

it('explains a feature someone tried to use', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['upgrade' => 'voicemail'])
        ->get(route('app.plan'))
        ->assertSee('Voicemail is part of a paid plan.');
});

it('promises only what the web app delivers', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['upgrade' => 'rated_r'])
        ->get(route('app.plan'))
        ->assertOk()
        ->assertSee('Voicemail')
        ->assertDontSee('Rated R')
        ->assertDontSee('Short videos')
        ->assertDontSee('is part of a paid plan');
});

it('shows "no limit" for unlimited metrics', function () {
    $plan = Plan::factory()->create(['limits' => ['metered' => ['images' => null], 'contacts' => null]]);
    $user = User::factory()->create();
    app(Entitlements::class)->assignPlan($user, $plan, null);

    $this->actingAs($user)->get(route('app.plan'))->assertSee('no limit')->assertSee('Contacts at once: no limit');
});

it('is linked from the sidebar', function () {
    $this->actingAs(User::factory()->create())->get('/app')->assertSee(route('app.plan'));
});

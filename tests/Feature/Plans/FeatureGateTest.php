<?php

use App\Enums\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// Plan-only routes (->middleware('feature:...')) and the `feature` gate.

beforeEach(function () {
    Route::middleware(['web', 'feature:voicemail'])->get('/_test/voicemail', fn () => 'voicemail ok');
});

it('lets a plan with the feature through', function () {
    $user = User::factory()->create();
    app(Entitlements::class)->assignPlan($user, Plan::where('slug', 'paid')->firstOrFail(), null);

    $this->actingAs($user)->get('/_test/voicemail')->assertOk()->assertSee('voicemail ok');
});

it('sends a plan without it to the plan page, saying what needs upgrading', function () {
    $this->actingAs(User::factory()->create())
        ->get('/_test/voicemail')
        ->assertRedirect(route('app.plan'))
        ->assertSessionHas('upgrade', 'voicemail');
});

it('sends guests to the plan page too (which then asks them to log in)', function () {
    $this->get('/_test/voicemail')->assertRedirect(route('app.plan'));
});

it('answers the feature gate by plan', function () {
    $free = User::factory()->create();
    $paid = User::factory()->create();
    $limits = Plan::where('slug', 'paid')->firstOrFail()->limits;
    $limits['features']['rated_r'] = true; // no shipped plan has it; the gate still works for one that does
    app(Entitlements::class)->assignPlan($paid, Plan::factory()->create(['limits' => $limits]), null);

    expect(Gate::forUser($free)->allows('feature', Feature::RatedR))->toBeFalse()
        ->and(Gate::forUser($paid)->allows('feature', Feature::RatedR))->toBeTrue();
});

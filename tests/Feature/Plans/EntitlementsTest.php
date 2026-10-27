<?php

use App\Enums\Feature;
use App\Enums\Metric;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// What each plan allows, and usage counting.

function entitlements(): Entitlements
{
    return app(Entitlements::class);
}

function paidPlan(): Plan
{
    return Plan::where('slug', 'paid')->firstOrFail();
}

it('puts everyone on Free unless they have another plan', function () {
    expect(entitlements()->planFor(User::factory()->create())->slug)->toBe('free');
});

it('uses their plan until it expires, then Free', function () {
    $user = User::factory()->create();
    entitlements()->assignPlan($user, paidPlan(), now()->addDays(3));

    expect(entitlements()->planFor($user)->slug)->toBe('paid');

    $this->travel(4)->days();
    expect(entitlements()->planFor($user->refresh())->slug)->toBe('free');
});

it('keeps a plan with no expiry until it is changed', function () {
    $user = User::factory()->create();
    entitlements()->assignPlan($user, paidPlan(), null);

    $this->travel(5)->years();
    expect(entitlements()->planFor($user->refresh())->slug)->toBe('paid');
});

it('falls back to Free if their plan is deleted', function () {
    $plan = Plan::factory()->create();
    $user = User::factory()->create();
    entitlements()->assignPlan($user, $plan, null);

    $plan->delete();

    expect(entitlements()->planFor($user->refresh())->slug)->toBe('free');
});

it('has the starting limits for each plan', function (string $slug, Metric $metric, int $limit) {
    $user = User::factory()->create();
    if ($slug === 'paid') {
        entitlements()->assignPlan($user, paidPlan(), null);
    }

    expect(entitlements()->limit($user, $metric))->toBe($limit);
})->with([
    ['free', Metric::TextMessages, 50],
    ['free', Metric::VoiceSeconds, 600],
    ['free', Metric::Images, 5],
    ['paid', Metric::TextMessages, 1000],
    ['paid', Metric::VoiceSeconds, 18000],
    ['paid', Metric::Images, 100],
]);

it('turns features and settings on by plan', function () {
    $user = User::factory()->create();

    expect(entitlements()->can($user, Feature::RatedR))->toBeFalse()
        ->and(entitlements()->can($user, Feature::Voicemail))->toBeFalse()
        ->and(entitlements()->setting($user, 'contacts'))->toBe(2)
        ->and(entitlements()->setting($user, 'llm_tier'))->toBe('small');

    entitlements()->assignPlan($user, paidPlan(), null);

    expect(entitlements()->can($user, Feature::Voicemail))->toBeTrue()
        ->and(entitlements()->can($user, Feature::RatedR))->toBeFalse() // not promised: the web app is never explicit
        ->and(entitlements()->can($user, Feature::Videos))->toBeFalse() // not promised: held back for cost
        ->and(entitlements()->setting($user, 'contacts'))->toBe(25)
        ->and(entitlements()->setting($user, 'llm_tier'))->toBe('large')
        ->and(entitlements()->setting($user, 'missing', 'fallback'))->toBe('fallback');
});

it('counts usage up to the limit and no further', function () {
    $user = User::factory()->create(); // Free: 50 texts a day

    foreach (range(1, 60) as $i) {
        entitlements()->consume($user, Metric::TextMessages);
    }

    expect(entitlements()->used($user, Metric::TextMessages))->toBe(50)
        ->and(entitlements()->remaining($user, Metric::TextMessages))->toBe(0);
});

it('refuses an amount that would go over, and changes nothing', function () {
    $user = User::factory()->create(); // Free: 600 voice seconds a month

    expect(entitlements()->consume($user, Metric::VoiceSeconds, 590))->toBeTrue()
        ->and(entitlements()->consume($user, Metric::VoiceSeconds, 11))->toBeFalse()
        ->and(entitlements()->used($user, Metric::VoiceSeconds))->toBe(590)
        ->and(entitlements()->consume($user, Metric::VoiceSeconds, 10))->toBeTrue()
        ->and(entitlements()->remaining($user, Metric::VoiceSeconds))->toBe(0);
});

it('never goes past the limit even when another request got there first', function () {
    $user = User::factory()->create();
    entitlements()->consume($user, Metric::Images, 4);

    // Another request uses the last picture between our check and our write.
    DB::table('usage_counters')->where('user_id', $user->id)->update(['count' => 5]);

    expect(entitlements()->consume($user, Metric::Images))->toBeFalse()
        ->and(entitlements()->used($user, Metric::Images))->toBe(5);
});

it('counts without limit on an unlimited plan', function () {
    $plan = Plan::factory()->create(['limits' => ['metered' => ['images' => null]]]);
    $user = User::factory()->create();
    entitlements()->assignPlan($user, $plan, null);

    expect(entitlements()->consume($user, Metric::Images, 500))->toBeTrue()
        ->and(entitlements()->used($user, Metric::Images))->toBe(500)
        ->and(entitlements()->limit($user, Metric::Images))->toBeNull()
        ->and(entitlements()->remaining($user, Metric::Images))->toBeNull();
});

it('treats a metric the plan does not mention as not allowed', function () {
    $plan = Plan::factory()->create(['limits' => ['metered' => []]]);
    $user = User::factory()->create();
    entitlements()->assignPlan($user, $plan, null);

    expect(entitlements()->limit($user, Metric::Images))->toBe(0)
        ->and(entitlements()->consume($user, Metric::Images))->toBeFalse();
});

it('rejects using zero or less', function () {
    entitlements()->consume(User::factory()->create(), Metric::Images, 0);
})->throws(InvalidArgumentException::class);

it('starts a new day of texts at midnight in the user\'s own timezone', function () {
    $user = User::factory()->create(['timezone' => 'America/Chicago']);
    $this->travelTo(Carbon::parse('2026-03-10 23:30', 'America/Chicago'));
    entitlements()->consume($user, Metric::TextMessages, 50);
    expect(entitlements()->remaining($user, Metric::TextMessages))->toBe(0)
        ->and(entitlements()->resetsAt($user, Metric::TextMessages)->toDateTimeString())->toBe('2026-03-11 00:00:00');

    // 00:05 UTC is still the 10th in Chicago: no reset yet.
    $this->travelTo(Carbon::parse('2026-03-11 00:05', 'UTC'));
    expect(entitlements()->remaining($user, Metric::TextMessages))->toBe(0);

    $this->travelTo(Carbon::parse('2026-03-11 00:01', 'America/Chicago'));
    expect(entitlements()->remaining($user, Metric::TextMessages))->toBe(50);
});

it('starts a new month of call time on the 1st', function () {
    $user = User::factory()->create();
    $this->travelTo(Carbon::parse('2026-01-31 12:00', 'UTC'));
    entitlements()->consume($user, Metric::VoiceSeconds, 600);
    expect(entitlements()->resetsAt($user, Metric::VoiceSeconds)->toDateString())->toBe('2026-02-01');

    $this->travelTo(Carbon::parse('2026-02-01 00:00', 'UTC'));
    expect(entitlements()->used($user, Metric::VoiceSeconds))->toBe(0);
});

it('records every plan change, and keeps no row for the default plan', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $until = now()->addMonth();

    entitlements()->assignPlan($user, paidPlan(), $until, $admin, 'Beta tester');
    $user->refresh();
    expect($user->plan_id)->toBe(paidPlan()->id)
        ->and($user->plan_expires_at->toDateTimeString())->toBe($until->toDateTimeString());

    entitlements()->assignPlan($user, Plan::default(), now()->addYear(), $admin);
    $user->refresh();
    expect($user->plan_id)->toBeNull()->and($user->plan_expires_at)->toBeNull();

    $history = $user->planChanges;
    expect($history)->toHaveCount(2)
        ->and($history->last()->only(['from_plan_id', 'to_plan_id', 'changed_by', 'reason']))
        ->toBe(['from_plan_id' => Plan::default()->id, 'to_plan_id' => paidPlan()->id, 'changed_by' => $admin->id, 'reason' => 'Beta tester'])
        ->and($history->first()->fromPlan->slug)->toBe('paid')
        ->and($history->first()->toPlan->slug)->toBe('free')
        ->and($history->first()->changedBy->is($admin))->toBeTrue()
        ->and(PlanChange::count())->toBe(2);
});

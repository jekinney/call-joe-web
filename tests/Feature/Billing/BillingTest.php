<?php

use App\Enums\Metric;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing;
use App\Services\Entitlements;
use Carbon\CarbonImmutable;

// Paid plans: subscribing, canceling, and what Braintree's webhooks do.

beforeEach(function () {
    config(['services.braintree.plans' => ['paid' => 'paid-monthly']]);
    $this->user = User::factory()->create();
    $this->paid = Plan::where('slug', 'paid')->firstOrFail();
    $this->end = CarbonImmutable::parse('2026-11-26 23:59:59', 'UTC');
    $this->payments->periodEnd = $this->end;
});

function billing(): Billing
{
    return app(Billing::class);
}

function planOf(User $user): Plan
{
    return app(Entitlements::class)->planFor($user->fresh());
}

function webhook(array $payload, string $signature = 'valid'): bool
{
    return billing()->handleWebhook($signature, json_encode($payload));
}

it('knows which plans can be bought online', function () {
    expect(billing()->isPurchasable($this->paid))->toBeTrue()
        ->and(billing()->gatewayPlanId($this->paid))->toBe('paid-monthly')
        ->and(billing()->isPurchasable(Plan::default()))->toBeFalse();

    config(['services.braintree.plans' => ['paid' => '']]);
    expect(billing()->isPurchasable($this->paid))->toBeFalse();

    $this->paid->update(['braintree_plan_id' => 'own-plan']);
    expect(billing()->gatewayPlanId($this->paid))->toBe('own-plan')->and(billing()->isPurchasable($this->paid))->toBeTrue();
});

it('gives the card form a token, making the Braintree customer once', function () {
    expect(billing()->checkoutToken($this->user))->toBe('token_for_cust_fake_1');
    billing()->checkoutToken($this->user);

    expect($this->user->fresh()->braintree_customer_id)->toBe('cust_fake_1')->and($this->payments->customers)->toHaveCount(1);
});

it('subscribes: charges, puts the user on the plan until the paid period ends plus a grace, and records the payment', function () {
    $subscription = billing()->subscribe($this->user, $this->paid, 'fake-nonce');

    expect($subscription->status)->toBe(Subscription::ACTIVE)
        ->and($subscription->paid_through->equalTo($this->end))->toBeTrue()
        ->and($this->payments->subscriptions['sub_fake_1'])->toBe(['customer' => 'cust_fake_1', 'nonce' => 'fake-nonce', 'plan' => 'paid-monthly'])
        ->and(planOf($this->user)->is($this->paid))->toBeTrue()
        ->and($this->user->fresh()->plan_expires_at->equalTo($this->end->addDays(Billing::GRACE_DAYS)))->toBeTrue()
        ->and(app(Entitlements::class)->limit($this->user->fresh(), Metric::TextMessages))->toBe(1000)
        ->and(Payment::sole()->only(['amount_cents', 'status', 'user_id']))->toBe(['amount_cents' => 1499, 'status' => 'paid', 'user_id' => $this->user->id])
        ->and(PlanChange::where('user_id', $this->user->id)->sole()->reason)->toBe('Subscribed');
});

it('leaves the user on Free when the card is declined', function () {
    $this->payments->decline();

    expect(fn () => billing()->subscribe($this->user, $this->paid, 'nonce'))->toThrow(PaymentException::class, 'Your card was declined.');
    expect(Subscription::count())->toBe(0)->and(planOf($this->user)->is_default)->toBeTrue();
});

it('will not sell a plan that cannot be bought, or a second subscription', function () {
    expect(fn () => billing()->subscribe($this->user, Plan::default(), 'nonce'))->toThrow(DomainException::class, "Free can't be bought online.");

    billing()->subscribe($this->user, $this->paid, 'nonce');
    expect(fn () => billing()->subscribe($this->user, $this->paid, 'nonce'))->toThrow(DomainException::class, 'You already have a subscription.');
});

it('cancels: no more charges, and the plan runs out what was paid', function () {
    $subscription = billing()->subscribe($this->user, $this->paid, 'nonce');

    billing()->cancel($this->user);

    expect($this->payments->canceled)->toBe(['sub_fake_1'])
        ->and($subscription->refresh()->status)->toBe(Subscription::CANCELED)->and($subscription->canceled_at)->not->toBeNull()
        ->and(planOf($this->user)->is($this->paid))->toBeTrue()
        ->and(billing()->current($this->user)->is($subscription))->toBeTrue()
        ->and(billing()->current($this->user)->isRenewing())->toBeFalse();
});

it('says so when there is nothing to cancel, or Braintree will not cancel', function () {
    expect(fn () => billing()->cancel($this->user))->toThrow(DomainException::class, "You don't have a subscription to cancel.");

    billing()->subscribe($this->user, $this->paid, 'nonce');
    $this->payments->decline();
    expect(fn () => billing()->cancel($this->user))->toThrow(PaymentException::class);
    expect(Subscription::sole()->status)->toBe(Subscription::ACTIVE);
});

it('can subscribe again once a canceled subscription has run out', function () {
    $old = billing()->subscribe($this->user, $this->paid, 'nonce');
    billing()->cancel($this->user);
    $this->travelTo($this->end->addDays(5));

    expect(billing()->current($this->user))->toBeNull()
        ->and(planOf($this->user)->is_default)->toBeTrue();

    $this->payments->periodEnd = $this->end->addMonth();
    $new = billing()->subscribe($this->user, $this->paid, 'nonce');

    expect($new->isNot($old))->toBeTrue()->and(planOf($this->user)->is($this->paid))->toBeTrue();
});

it('renews on a successful charge: moves the paid-through date on and records the payment', function () {
    billing()->subscribe($this->user, $this->paid, 'nonce');
    $next = $this->end->addMonth();

    expect(webhook(['kind' => 'charged', 'id' => 'sub_fake_1', 'period_end' => $next->toIso8601String(), 'charge_id' => 'txn_2', 'charge_cents' => 1499]))->toBeTrue();

    $subscription = Subscription::sole();
    expect($subscription->paid_through->equalTo($next))->toBeTrue()
        ->and($subscription->status)->toBe(Subscription::ACTIVE)
        ->and($this->user->fresh()->plan_expires_at->equalTo($next->addDays(Billing::GRACE_DAYS)))->toBeTrue()
        ->and(Payment::count())->toBe(2);

    webhook(['kind' => 'charged', 'id' => 'sub_fake_1', 'period_end' => $next->toIso8601String(), 'charge_id' => 'txn_2', 'charge_cents' => 1499]);
    expect(Payment::count())->toBe(2); // the same charge twice counts once
});

it('does not bring a canceled subscription back to life when a late charge arrives', function () {
    billing()->subscribe($this->user, $this->paid, 'nonce');
    billing()->cancel($this->user);

    webhook(['kind' => 'charged', 'id' => 'sub_fake_1', 'period_end' => $this->end->toIso8601String(), 'charge_id' => 'txn_late', 'charge_cents' => 1499]);

    expect(Subscription::sole()->status)->toBe(Subscription::CANCELED);
});

it('marks a failed charge past due, keeps the plan until it runs out, and records the failure', function () {
    billing()->subscribe($this->user, $this->paid, 'nonce');

    webhook(['kind' => 'charge_failed', 'id' => 'sub_fake_1', 'charge_id' => 'txn_bad', 'charge_cents' => 1499]);

    expect(Subscription::sole()->status)->toBe(Subscription::PAST_DUE)
        ->and(Payment::where('status', 'failed')->count())->toBe(1)
        ->and(planOf($this->user)->is($this->paid))->toBeTrue();

    $this->travelTo($this->end->addDays(Billing::GRACE_DAYS + 1)); // never paid again: the plan ends by itself
    expect(planOf($this->user)->is_default)->toBeTrue();
});

it('follows past due, active and canceled notices', function () {
    billing()->subscribe($this->user, $this->paid, 'nonce');

    webhook(['kind' => 'past_due', 'id' => 'sub_fake_1']);
    expect(Subscription::sole()->status)->toBe(Subscription::PAST_DUE);

    webhook(['kind' => 'active', 'id' => 'sub_fake_1']);
    expect(Subscription::sole()->status)->toBe(Subscription::ACTIVE);

    webhook(['kind' => 'canceled', 'id' => 'sub_fake_1']);
    expect(Subscription::sole()->status)->toBe(Subscription::CANCELED)->and(Subscription::sole()->canceled_at)->not->toBeNull();

    webhook(['kind' => 'past_due', 'id' => 'sub_fake_1']);
    webhook(['kind' => 'active', 'id' => 'sub_fake_1']);
    webhook(['kind' => 'charge_failed', 'id' => 'sub_fake_1']);
    expect(Subscription::sole()->status)->toBe(Subscription::CANCELED); // a canceled one stays canceled
});

it('ends the plan when the subscription expires', function () {
    billing()->subscribe($this->user, $this->paid, 'nonce');

    webhook(['kind' => 'expired', 'id' => 'sub_fake_1']);

    expect(Subscription::sole()->status)->toBe(Subscription::EXPIRED)
        ->and(planOf($this->user)->is_default)->toBeTrue()
        ->and(PlanChange::latest('id')->first()->reason)->toBe('Subscription ended');
});

it('leaves a user who has already moved to another plan alone when an old subscription expires', function () {
    billing()->subscribe($this->user, $this->paid, 'nonce');
    app(Entitlements::class)->assignPlan($this->user, Plan::default(), null);

    webhook(['kind' => 'expired', 'id' => 'sub_fake_1']);

    expect(PlanChange::where('reason', 'Subscription ended')->count())->toBe(0);
});

it('ignores other notices and subscriptions that are not ours', function () {
    billing()->subscribe($this->user, $this->paid, 'nonce');

    expect(webhook(['kind' => 'other', 'id' => 'sub_fake_1']))->toBeTrue()
        ->and(webhook(['kind' => 'charged', 'id' => 'someone-elses']))->toBeFalse()
        ->and(webhook(['kind' => 'other']))->toBeFalse()
        ->and(Subscription::sole()->status)->toBe(Subscription::ACTIVE);
});

it('refuses a webhook with a bad signature', function () {
    webhook(['kind' => 'charged', 'id' => 'sub_fake_1'], 'forged');
})->throws(PaymentException::class, 'Invalid webhook signature.');

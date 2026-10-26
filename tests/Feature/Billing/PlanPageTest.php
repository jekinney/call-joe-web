<?php

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing;
use App\Services\Entitlements;
use App\Support\Billing\FakePaymentGateway;
use Livewire\Volt\Volt;

// The plan page: the card form, subscribing, canceling, and the Braintree webhook address.

beforeEach(function () {
    config(['services.braintree.plans' => ['paid' => 'paid-monthly']]);
    $this->user = User::factory()->create();
    $this->paid = Plan::where('slug', 'paid')->firstOrFail();
});

function planPage()
{
    return Volt::actingAs(test()->user)->test('app.plan');
}

it('offers Subscribe on a plan that can be bought online', function () {
    planPage()->assertSee('data-subscribe="paid"', false)->assertDontSee('Upgrading online is coming soon');

    config(['services.braintree.plans' => ['paid' => '']]);
    planPage()->assertDontSee('data-subscribe', false)->assertSee('Upgrading online is coming soon');
});

it('opens the card form with a client token, then subscribes with the nonce the browser sends', function () {
    $page = planPage()->call('startCheckout', $this->paid->id)
        ->assertSet('checkout', 'loading')->assertDispatched('billing-start', token: 'token_for_cust_fake_1')
        ->dispatch('billing-ready')->assertSet('checkout', 'ready')->assertSee('Braintree')
        ->call('pay')->assertSet('checkout', 'paying')->assertDispatched('billing-pay')
        ->dispatch('billing-nonce', nonce: 'fake-nonce')
        ->assertSet('checkout', 'idle')->assertSet('checkoutPlanId', null)->assertSee("You're on Paid")->assertDispatched('billing-stop');

    expect(Subscription::sole()->user_id)->toBe($this->user->id)
        ->and(app(Entitlements::class)->planFor($this->user->fresh())->is($this->paid))->toBeTrue();
    $page->assertSee('Your subscription')->assertDontSee('data-subscribe', false);
});

it('keeps the card form open when the card is declined', function () {
    $this->payments->decline('Your card was declined.');

    planPage()->call('startCheckout', $this->paid->id)->dispatch('billing-ready')->call('pay')
        ->dispatch('billing-nonce', nonce: 'n')
        ->assertSet('checkout', 'ready')->assertSee('Your card was declined.');

    expect(Subscription::count())->toBe(0);
});

it('does not open the card form for a plan that cannot be bought', function () {
    planPage()->call('startCheckout', Plan::default()->id)->assertNotFound();
});

it('says so when the card form cannot be set up', function () {
    $this->payments->decline('x');
    app()->instance(Billing::class, new class(app(PaymentGateway::class), app(Entitlements::class)) extends Billing
    {
        public function checkoutToken(User $user): string
        {
            throw new PaymentException('Payments are not set up yet.');
        }
    });

    planPage()->call('startCheckout', $this->paid->id)->assertSet('checkout', 'idle')->assertSee('Payments are not set up yet.');
});

it('explains when the card form fails to load or the card is rejected in the browser', function () {
    planPage()->call('startCheckout', $this->paid->id)->dispatch('billing-error', message: 'x')
        ->assertSet('checkout', 'idle')->assertSee("The card form couldn't load.");

    planPage()->call('startCheckout', $this->paid->id)->dispatch('billing-ready')->call('pay')->dispatch('billing-error', message: 'x')
        ->assertSet('checkout', 'ready')->assertSee("That card didn't work.");
});

it('ignores a nonce when no card form is open, and a pay press before it is ready', function () {
    planPage()->dispatch('billing-nonce', nonce: 'n')->call('pay')->assertSet('checkout', 'idle');

    expect(Subscription::count())->toBe(0);
});

it('closes the card form', function () {
    planPage()->call('startCheckout', $this->paid->id)->call('closeCheckout')->assertSet('checkout', 'idle')->assertSet('checkoutPlanId', null);
});

it('cancels a subscription and says until when the plan runs', function () {
    app(Billing::class)->subscribe($this->user, $this->paid, 'n');

    planPage()->assertSee('Cancel subscription')->call('cancelSubscription')->assertSee('Your subscription is canceled.')->assertSee('be charged again');

    expect(Subscription::sole()->status)->toBe(Subscription::CANCELED);
});

it('says so when there is nothing to cancel', function () {
    planPage()->call('cancelSubscription')->assertSee("You don't have a subscription to cancel.");
});

it('shows a past-due subscription', function () {
    app(Billing::class)->subscribe($this->user, $this->paid, 'n');
    Subscription::sole()->update(['status' => Subscription::PAST_DUE]);

    planPage()->assertSee("Your last payment didn't go through");
});

it('answers Braintree\'s check when the webhook address is set up', function () {
    $this->get(route('webhooks.braintree', ['bt_challenge' => 'abc']))->assertOk()->assertSee('verified_abc');
});

it('takes Braintree\'s signed webhooks, and refuses forged ones, without a CSRF token', function () {
    app(Billing::class)->subscribe($this->user, $this->paid, 'n');

    $this->post(route('webhooks.braintree'), ['bt_signature' => 'valid', 'bt_payload' => json_encode(['kind' => 'expired', 'id' => 'sub_fake_1'])])
        ->assertOk()->assertJson(['ok' => true, 'handled' => true]);
    expect(Subscription::sole()->status)->toBe(Subscription::EXPIRED);

    $this->post(route('webhooks.braintree'), ['bt_signature' => 'forged', 'bt_payload' => '{}'])->assertStatus(400);
});

it('refuses the webhook check when it cannot be verified', function () {
    $broken = new class extends FakePaymentGateway
    {
        public function verifyWebhook(string $challenge): string
        {
            throw new PaymentException('no');
        }
    };
    app()->instance(PaymentGateway::class, $broken);

    $this->get(route('webhooks.braintree', ['bt_challenge' => 'abc']))->assertStatus(400);
});

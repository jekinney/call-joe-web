<?php

use App\Exceptions\PaymentException;
use App\Models\User;
use App\Support\Billing\BraintreeClient;
use App\Support\Billing\GatewayEvent;
use Braintree\Exception\Authentication;
use Braintree\Gateway;
use Braintree\Subscription as BraintreeSubscription;
use Braintree\WebhookNotification;

// The real Braintree client, with the Braintree SDK's gateway replaced by a mock (nothing is sent anywhere).

const BT_KEYS = ['environment' => 'sandbox', 'merchantId' => 'merchant', 'publicKey' => 'pub', 'privateKey' => 'priv'];

function btClient(Gateway $gateway): BraintreeClient
{
    return new BraintreeClient(BT_KEYS, $gateway);
}

/** A Braintree result, as the SDK gives it: success or not, with the parts the client reads. */
function btResult(bool $success, array $parts = []): object
{
    return (object) ($success ? ['success' => true, ...$parts] : ['success' => false, 'message' => $parts['message'] ?? 'Declined']);
}

function btSubscription(array $extra = []): BraintreeSubscription
{
    return BraintreeSubscription::factory([
        'id' => 'sub_1', 'billingPeriodEndDate' => new DateTime('2026-11-26'), 'transactions' => [['id' => 'txn_1', 'amount' => '14.99']], ...$extra,
    ]);
}

it('will not call Braintree without keys', function () {
    $client = new BraintreeClient(['environment' => 'sandbox', 'merchantId' => '', 'publicKey' => '', 'privateKey' => '']);

    expect(fn () => $client->clientToken('cust_1'))->toThrow(PaymentException::class, 'not set up yet');
});

it('creates a customer and a client token', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('customer->create')->once()->with(['email' => 'jamie@example.com', 'firstName' => 'Jamie'])
        ->andReturn(btResult(true, ['customer' => (object) ['id' => 'cust_1']]));
    $gateway->shouldReceive('clientToken->generate')->once()->with(['customerId' => 'cust_1'])->andReturn('client-token');

    $user = User::factory()->make(['email' => 'jamie@example.com', 'name' => 'Jamie Rivera']);

    expect(btClient($gateway)->createCustomer($user))->toBe('cust_1')
        ->and(btClient($gateway)->clientToken('cust_1'))->toBe('client-token');
});

it('says so when Braintree will not make the customer', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('customer->create')->andReturn(btResult(false, ['message' => 'Nope']));

    btClient($gateway)->createCustomer(User::factory()->make());
})->throws(PaymentException::class, "couldn't set up your payment account: Nope");

it('saves the card and starts the subscription', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('paymentMethod->create')->once()
        ->with(['customerId' => 'cust_1', 'paymentMethodNonce' => 'nonce', 'options' => ['verifyCard' => true]])
        ->andReturn(btResult(true, ['paymentMethod' => (object) ['token' => 'tok_1']]));
    $gateway->shouldReceive('subscription->create')->once()->with(['paymentMethodToken' => 'tok_1', 'planId' => 'paid-monthly'])
        ->andReturn(btResult(true, ['subscription' => btSubscription()]));

    $subscription = btClient($gateway)->subscribe('cust_1', 'nonce', 'paid-monthly');

    expect($subscription->id)->toBe('sub_1')
        ->and($subscription->periodEnd->toDateString())->toBe('2026-11-26')
        ->and($subscription->chargeId)->toBe('txn_1')
        ->and($subscription->chargeCents)->toBe(1499);
});

it('uses the next billing date when there is no period end, and copes with no charge yet', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('paymentMethod->create')->andReturn(btResult(true, ['paymentMethod' => (object) ['token' => 'tok_1']]));
    $gateway->shouldReceive('subscription->create')->andReturn(btResult(true, ['subscription' => BraintreeSubscription::factory(['id' => 'sub_2', 'nextBillingDate' => new DateTime('2026-12-01')])]));

    $subscription = btClient($gateway)->subscribe('cust_1', 'nonce', 'paid-monthly');

    expect($subscription->periodEnd->toDateString())->toBe('2026-12-01')->and($subscription->chargeId)->toBeNull()->and($subscription->chargeCents)->toBeNull();
});

it('says so when the card or the payment is refused', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('paymentMethod->create')->once()->andReturn(btResult(false, ['message' => 'Card number is invalid']));
    expect(fn () => btClient($gateway)->subscribe('cust_1', 'nonce', 'p'))->toThrow(PaymentException::class, "That card didn't work: Card number is invalid");

    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('paymentMethod->create')->andReturn(btResult(true, ['paymentMethod' => (object) ['token' => 'tok_1']]));
    $gateway->shouldReceive('subscription->create')->andReturn(btResult(false, ['message' => 'Insufficient funds']));
    expect(fn () => btClient($gateway)->subscribe('cust_1', 'nonce', 'p'))->toThrow(PaymentException::class, "The payment didn't go through: Insufficient funds");
});

it('cancels a subscription', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('subscription->cancel')->once()->with('sub_1')->andReturn(btResult(true));
    btClient($gateway)->cancel('sub_1');

    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('subscription->cancel')->andReturn(btResult(false, ['message' => 'Already canceled']));
    expect(fn () => btClient($gateway)->cancel('sub_1'))->toThrow(PaymentException::class, "couldn't cancel: Already canceled");
});

it('reads each kind of webhook', function (string $braintreeKind, string $ours) {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('webhookNotification->parse')->once()->with('sig', 'payload')
        ->andReturn((object) ['kind' => $braintreeKind, 'subscription' => btSubscription()]);

    $event = btClient($gateway)->parseWebhook('sig', 'payload');

    expect($event->kind)->toBe($ours)->and($event->subscription->id)->toBe('sub_1');
})->with([
    [WebhookNotification::SUBSCRIPTION_CHARGED_SUCCESSFULLY, GatewayEvent::CHARGED],
    [WebhookNotification::SUBSCRIPTION_CHARGED_UNSUCCESSFULLY, GatewayEvent::CHARGE_FAILED],
    [WebhookNotification::SUBSCRIPTION_WENT_PAST_DUE, GatewayEvent::PAST_DUE],
    [WebhookNotification::SUBSCRIPTION_WENT_ACTIVE, GatewayEvent::ACTIVE],
    [WebhookNotification::SUBSCRIPTION_CANCELED, GatewayEvent::CANCELED],
    [WebhookNotification::SUBSCRIPTION_EXPIRED, GatewayEvent::EXPIRED],
    ['check', GatewayEvent::OTHER],
]);

it('reads a webhook with no subscription in it', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('webhookNotification->parse')->andReturn((object) ['kind' => 'check', 'subscription' => null]);

    expect(btClient($gateway)->parseWebhook('sig', 'payload')->subscription)->toBeNull();
});

it('answers the webhook check', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('webhookNotification->verify')->once()->with('abc')->andReturn('pub|hash');

    expect(btClient($gateway)->verifyWebhook('abc'))->toBe('pub|hash');
});

it('turns Braintree\'s failures into ours', function () {
    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('clientToken->generate')->andThrow(new Authentication);
    expect(fn () => btClient($gateway)->clientToken('cust_1'))->toThrow(PaymentException::class, 'Braintree: Authentication');

    $gateway = Mockery::mock(Gateway::class);
    $gateway->shouldReceive('clientToken->generate')->andThrow(new RuntimeException('network down'));
    expect(fn () => btClient($gateway)->clientToken('cust_1'))->toThrow(PaymentException::class, 'unavailable right now: network down');
});

it('builds its own Braintree gateway from the keys', function () {
    $client = new BraintreeClient(BT_KEYS);

    // No request is made: Braintree's SDK only builds the gateway until a call is made, and the check below fails earlier.
    expect(fn () => (new BraintreeClient([...BT_KEYS, 'merchantId' => '']))->clientToken('x'))->toThrow(PaymentException::class)
        ->and($client)->toBeInstanceOf(BraintreeClient::class);
});

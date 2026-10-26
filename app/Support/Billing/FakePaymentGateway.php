<?php

namespace App\Support\Billing;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Stands in for Braintree in tests (bound in tests/TestCase.php): remembers what was done, and can be told to refuse.
 * A webhook's payload here is JSON like {"kind": "charged", "id": "sub_fake_1", "period_end": "...", "charge_id": "t1", "charge_cents": 1499}
 * and its signature must be "valid".
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var list<string> */
    public array $customers = [];

    /** @var array<string, array{customer: string, nonce: string, plan: string}> */
    public array $subscriptions = [];

    /** @var list<string> */
    public array $canceled = [];

    public ?string $declined = null;

    /** When the next subscription's first period ends. */
    public ?CarbonImmutable $periodEnd = null;

    public function decline(string $reason = 'Your card was declined.'): static
    {
        $this->declined = $reason;

        return $this;
    }

    public function createCustomer(User $user): string
    {
        return $this->customers[] = 'cust_fake_'.(count($this->customers) + 1);
    }

    public function clientToken(string $customerId): string
    {
        return "token_for_{$customerId}";
    }

    public function subscribe(string $customerId, string $nonce, string $planId): GatewaySubscription
    {
        if ($this->declined !== null) {
            throw new PaymentException($this->declined);
        }

        $id = 'sub_fake_'.(count($this->subscriptions) + 1);
        $this->subscriptions[$id] = ['customer' => $customerId, 'nonce' => $nonce, 'plan' => $planId];

        return new GatewaySubscription($id, $this->periodEnd ?? CarbonImmutable::now()->addMonth(), 'txn_'.$id, 1499);
    }

    public function cancel(string $subscriptionId): void
    {
        if ($this->declined !== null) {
            throw new PaymentException('Braintree is down.');
        }

        $this->canceled[] = $subscriptionId;
    }

    public function parseWebhook(string $signature, string $payload): GatewayEvent
    {
        if ($signature !== 'valid') {
            throw new PaymentException('Invalid webhook signature.');
        }

        $data = json_decode($payload, true) ?: [];
        $subscription = isset($data['id'])
            ? new GatewaySubscription($data['id'], isset($data['period_end']) ? CarbonImmutable::parse($data['period_end']) : null, $data['charge_id'] ?? null, $data['charge_cents'] ?? null)
            : null;

        return new GatewayEvent($data['kind'] ?? GatewayEvent::OTHER, $subscription);
    }

    public function verifyWebhook(string $challenge): string
    {
        return "verified_{$challenge}";
    }
}

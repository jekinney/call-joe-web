<?php

namespace App\Support\Billing;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\User;
use Braintree\Exception as BraintreeException;
use Braintree\Gateway;
use Braintree\Subscription as BraintreeSubscription;
use Braintree\WebhookNotification;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Braintree's API, with the merchant's keys (never sent to the browser; the browser gets only a client token).
 */
class BraintreeClient implements PaymentGateway
{
    /** @param array{environment: string, merchantId: string, publicKey: string, privateKey: string} $keys */
    public function __construct(private array $keys, private ?Gateway $gateway = null) {}

    public function createCustomer(User $user): string
    {
        $result = $this->call(fn () => $this->gateway()->customer()->create(['email' => $user->email, 'firstName' => $user->preferredName()]));
        if (! $result->success) {
            throw new PaymentException("We couldn't set up your payment account: ".$result->message);
        }

        return (string) $result->customer->id;
    }

    public function clientToken(string $customerId): string
    {
        return (string) $this->call(fn () => $this->gateway()->clientToken()->generate(['customerId' => $customerId]));
    }

    public function subscribe(string $customerId, string $nonce, string $planId): GatewaySubscription
    {
        $method = $this->call(fn () => $this->gateway()->paymentMethod()->create([
            'customerId' => $customerId, 'paymentMethodNonce' => $nonce, 'options' => ['verifyCard' => true],
        ]));
        if (! $method->success) {
            throw new PaymentException("That card didn't work: ".$method->message);
        }

        $result = $this->call(fn () => $this->gateway()->subscription()->create(['paymentMethodToken' => $method->paymentMethod->token, 'planId' => $planId]));
        if (! $result->success) {
            throw new PaymentException("The payment didn't go through: ".$result->message);
        }

        return $this->subscription($result->subscription);
    }

    public function cancel(string $subscriptionId): void
    {
        $result = $this->call(fn () => $this->gateway()->subscription()->cancel($subscriptionId));
        if (! $result->success) {
            throw new PaymentException("We couldn't cancel: ".$result->message);
        }
    }

    public function parseWebhook(string $signature, string $payload): GatewayEvent
    {
        $notification = $this->call(fn () => $this->gateway()->webhookNotification()->parse($signature, $payload));

        $kind = match ($notification->kind) {
            WebhookNotification::SUBSCRIPTION_CHARGED_SUCCESSFULLY => GatewayEvent::CHARGED,
            WebhookNotification::SUBSCRIPTION_CHARGED_UNSUCCESSFULLY => GatewayEvent::CHARGE_FAILED,
            WebhookNotification::SUBSCRIPTION_WENT_PAST_DUE => GatewayEvent::PAST_DUE,
            WebhookNotification::SUBSCRIPTION_WENT_ACTIVE => GatewayEvent::ACTIVE,
            WebhookNotification::SUBSCRIPTION_CANCELED => GatewayEvent::CANCELED,
            WebhookNotification::SUBSCRIPTION_EXPIRED => GatewayEvent::EXPIRED,
            default => GatewayEvent::OTHER,
        };

        return new GatewayEvent($kind, $notification->subscription ? $this->subscription($notification->subscription) : null);
    }

    public function verifyWebhook(string $challenge): string
    {
        return (string) $this->call(fn () => $this->gateway()->webhookNotification()->verify($challenge));
    }

    private function subscription(BraintreeSubscription $subscription): GatewaySubscription
    {
        $end = $subscription->billingPeriodEndDate ?? $subscription->nextBillingDate ?? null;
        $charge = $subscription->transactions[0] ?? null;

        return new GatewaySubscription(
            (string) $subscription->id,
            $end instanceof DateTimeInterface ? CarbonImmutable::instance($end)->endOfDay() : null,
            $charge ? (string) $charge->id : null,
            $charge ? (int) round(((float) $charge->amount) * 100) : null,
        );
    }

    /** Runs a Braintree call, turning its failures into ours. */
    private function call(callable $call): mixed
    {
        if ($this->keys['merchantId'] === '' || $this->keys['privateKey'] === '') {
            throw new PaymentException('Payments are not set up yet (the Braintree keys are missing).');
        }

        try {
            return $call();
        } catch (BraintreeException $e) {
            throw new PaymentException('Braintree: '.($e->getMessage() ?: class_basename($e)), previous: $e);
        } catch (Throwable $e) {
            throw new PaymentException('Payments are unavailable right now: '.$e->getMessage(), previous: $e);
        }
    }

    private function gateway(): Gateway
    {
        return $this->gateway ??= new Gateway($this->keys);
    }
}

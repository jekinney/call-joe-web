<?php

namespace App\Contracts;

use App\Exceptions\PaymentException;
use App\Models\User;
use App\Support\Billing\GatewayEvent;
use App\Support\Billing\GatewaySubscription;

/**
 * The payment provider (Braintree). App\Services\Billing is what the app uses; this is just the wire. Tests use
 * App\Support\Billing\FakePaymentGateway and never reach Braintree. Card details never touch our server: the
 * browser sends them straight to Braintree and we only see a one-time nonce.
 */
interface PaymentGateway
{
    /**
     * Makes the user's customer record and returns its id.
     *
     * @throws PaymentException
     */
    public function createCustomer(User $user): string;

    /**
     * A token the browser uses to show the card form.
     *
     * @throws PaymentException
     */
    public function clientToken(string $customerId): string;

    /**
     * Saves the card (from the browser's nonce) and starts the subscription, charging it now.
     *
     * @throws PaymentException with a reason the user can read.
     */
    public function subscribe(string $customerId, string $nonce, string $planId): GatewaySubscription;

    /** @throws PaymentException */
    public function cancel(string $subscriptionId): void;

    /**
     * Checks a webhook really came from Braintree, and reads it.
     *
     * @throws PaymentException when the signature is wrong.
     */
    public function parseWebhook(string $signature, string $payload): GatewayEvent;

    /**
     * The answer to Braintree's check when the webhook address is first set up.
     *
     * @throws PaymentException
     */
    public function verifyWebhook(string $challenge): string;
}

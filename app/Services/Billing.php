<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\GatewayEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Paid plans through Braintree. Subscribing charges the card now and puts the user on the plan until the end of the
 * period they paid for (plus a short grace). Braintree's webhooks (App\Http\Controllers\BraintreeWebhookController) then
 * move that date on at each renewal, record charges, and end the plan if the subscription expires. If a webhook is ever
 * missed or a card fails, the date simply isn't moved and the user ends up on Free: it can't leave anyone on a paid plan
 * for free.
 */
class Billing
{
    /** Days the plan runs past the paid period, so a renewal charged a little late doesn't cut anyone off. */
    public const GRACE_DAYS = 2;

    public function __construct(private PaymentGateway $gateway, private Entitlements $entitlements) {}

    /** The id of the plan at Braintree: set on the plan itself, or in config (services.braintree.plans.<slug>). */
    public function gatewayPlanId(Plan $plan): ?string
    {
        return $plan->braintree_plan_id ?: (config('services.braintree.plans.'.$plan->slug) ?: null);
    }

    /** Whether a user can buy this plan online. */
    public function isPurchasable(Plan $plan): bool
    {
        return ! $plan->is_default && $plan->price_cents > 0 && $this->gatewayPlanId($plan) !== null;
    }

    /** The subscription still paying for their plan: being charged, or canceled with time left. */
    public function current(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE, Subscription::CANCELED])
            ->where(fn ($query) => $query->whereNull('paid_through')->orWhere('paid_through', '>', now()))
            ->latest('id')->first();
    }

    /**
     * A token for the card form in their browser.
     *
     * @throws PaymentException
     */
    public function checkoutToken(User $user): string
    {
        return $this->gateway->clientToken($this->customerId($user));
    }

    /**
     * Starts a subscription with the card the browser sent (a one-time nonce) and moves the user onto the plan.
     *
     * @throws DomainException with a reason the user can read.
     * @throws PaymentException when the card is refused or Braintree is down.
     */
    public function subscribe(User $user, Plan $plan, string $nonce): Subscription
    {
        if (! $this->isPurchasable($plan)) {
            throw new DomainException("{$plan->name} can't be bought online.");
        }
        if ($this->current($user)?->isRenewing()) {
            throw new DomainException('You already have a subscription.');
        }

        $remote = $this->gateway->subscribe($this->customerId($user), $nonce, (string) $this->gatewayPlanId($plan));

        return DB::transaction(function () use ($user, $plan, $remote) {
            $subscription = Subscription::create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'braintree_subscription_id' => $remote->id,
                'status' => Subscription::ACTIVE, 'paid_through' => $remote->periodEnd,
            ]);
            $this->recordCharge($subscription, $remote->chargeId, $remote->chargeCents, 'paid');
            $this->extendPlan($subscription, 'Subscribed');

            return $subscription;
        });
    }

    /**
     * Stops future charges. The plan keeps running until the end of what they've paid for.
     *
     * @throws DomainException when there's nothing to cancel.
     * @throws PaymentException
     */
    public function cancel(User $user): Subscription
    {
        $subscription = $this->current($user);
        if (! $subscription?->isRenewing()) {
            throw new DomainException('You don\'t have a subscription to cancel.');
        }

        $this->gateway->cancel($subscription->braintree_subscription_id);
        $subscription->update(['status' => Subscription::CANCELED, 'canceled_at' => now()]);

        return $subscription;
    }

    /**
     * Braintree's word on what happened to a subscription. Returns false when it isn't one of ours. Safe to receive
     * twice.
     *
     * @throws PaymentException when the signature is wrong.
     */
    public function handleWebhook(string $signature, string $payload): bool
    {
        $event = $this->gateway->parseWebhook($signature, $payload);
        $subscription = $event->subscription
            ? Subscription::with(['user', 'plan'])->where('braintree_subscription_id', $event->subscription->id)->first()
            : null;
        if (! $subscription) {
            return false;
        }

        $remote = $event->subscription;
        $canceled = $subscription->status === Subscription::CANCELED;

        match ($event->kind) {
            GatewayEvent::CHARGED => DB::transaction(function () use ($subscription, $remote, $canceled) {
                $subscription->update(['status' => $canceled ? Subscription::CANCELED : Subscription::ACTIVE, 'paid_through' => $remote->periodEnd ?? $subscription->paid_through]);
                $this->recordCharge($subscription, $remote->chargeId, $remote->chargeCents, 'paid');
                $this->extendPlan($subscription, 'Renewed');
            }),
            GatewayEvent::CHARGE_FAILED => $this->failed($subscription, $remote->chargeId, $remote->chargeCents),
            GatewayEvent::PAST_DUE => $subscription->update(['status' => $canceled ? Subscription::CANCELED : Subscription::PAST_DUE]),
            GatewayEvent::ACTIVE => $subscription->update(['status' => $canceled ? Subscription::CANCELED : Subscription::ACTIVE]),
            GatewayEvent::CANCELED => $subscription->update(['status' => Subscription::CANCELED, 'canceled_at' => $subscription->canceled_at ?? now()]),
            GatewayEvent::EXPIRED => $this->expired($subscription),
            default => null,
        };

        return true;
    }

    private function customerId(User $user): string
    {
        if (! $user->braintree_customer_id) {
            $user->forceFill(['braintree_customer_id' => $this->gateway->createCustomer($user)])->save();
        }

        return (string) $user->braintree_customer_id;
    }

    private function failed(Subscription $subscription, ?string $chargeId, ?int $cents): void
    {
        $subscription->update(['status' => $subscription->status === Subscription::CANCELED ? Subscription::CANCELED : Subscription::PAST_DUE]);
        $this->recordCharge($subscription, $chargeId, $cents, 'failed');
    }

    /** The subscription is over: the plan ends now, unless the user has already moved to another one. */
    private function expired(Subscription $subscription): void
    {
        $subscription->update(['status' => Subscription::EXPIRED]);
        if ($this->entitlements->planFor($subscription->user)->is($subscription->plan)) {
            $this->entitlements->assignPlan($subscription->user, Plan::default(), null, null, 'Subscription ended');
        }
    }

    /** Puts the user on the plan until the paid period ends, plus the grace. */
    private function extendPlan(Subscription $subscription, string $reason): void
    {
        $until = $subscription->paid_through
            ? CarbonImmutable::instance($subscription->paid_through)->addDays(self::GRACE_DAYS)
            : null;

        $this->entitlements->assignPlan($subscription->user, $subscription->plan, $until, null, $reason);
    }

    private function recordCharge(Subscription $subscription, ?string $transactionId, ?int $cents, string $status): void
    {
        if ($transactionId === null || $cents === null) {
            return;
        }

        Payment::firstOrCreate(['braintree_transaction_id' => $transactionId], [
            'user_id' => $subscription->user_id, 'subscription_id' => $subscription->id, 'amount_cents' => $cents,
            'status' => $status, 'occurred_at' => now(),
        ]);
    }
}

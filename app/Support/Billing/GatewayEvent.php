<?php

namespace App\Support\Billing;

/** One webhook from the payment provider: what happened, and to which subscription. */
final readonly class GatewayEvent
{
    public const CHARGED = 'charged';

    public const CHARGE_FAILED = 'charge_failed';

    public const PAST_DUE = 'past_due';

    public const ACTIVE = 'active';

    public const CANCELED = 'canceled';

    public const EXPIRED = 'expired';

    public const OTHER = 'other';

    public function __construct(public string $kind, public ?GatewaySubscription $subscription = null) {}
}

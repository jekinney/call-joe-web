<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/**
 * A subscription as the payment provider reports it. `periodEnd` is the end of the period last charged for;
 * `chargeId` and `chargeCents` are the latest charge, when there is one.
 */
final readonly class GatewaySubscription
{
    public function __construct(
        public string $id,
        public ?CarbonImmutable $periodEnd,
        public ?string $chargeId = null,
        public ?int $chargeCents = null,
    ) {}
}

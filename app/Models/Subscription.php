<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's Braintree subscription to a paid plan (App\Services\Billing keeps it in step with Braintree).
 *
 * @property int $id
 * @property int $user_id
 * @property int $plan_id
 * @property string $braintree_subscription_id
 * @property string $status active | past_due | canceled | expired
 * @property Carbon|null $paid_through
 * @property Carbon|null $canceled_at
 * @property-read Plan $plan
 * @property-read User $user
 */
class Subscription extends Model
{
    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const CANCELED = 'canceled';

    public const EXPIRED = 'expired';

    protected $fillable = ['user_id', 'plan_id', 'braintree_subscription_id', 'status', 'paid_through', 'canceled_at'];

    protected function casts(): array
    {
        return ['paid_through' => 'datetime', 'canceled_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Still being charged (a canceled one is only running out what was paid). */
    public function isRenewing(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::PAST_DUE], true);
    }
}

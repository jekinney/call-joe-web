<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One charge Braintree reported for a subscription.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $subscription_id
 * @property string $braintree_transaction_id
 * @property int $amount_cents
 * @property string $status paid | failed
 * @property Carbon $occurred_at
 */
class Payment extends Model
{
    protected $fillable = ['user_id', 'subscription_id', 'braintree_transaction_id', 'amount_cents', 'status', 'occurred_at'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'occurred_at' => 'datetime'];
    }
}

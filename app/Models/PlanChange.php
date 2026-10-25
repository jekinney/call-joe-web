<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One plan change, kept for history (admins now, billing later).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $from_plan_id
 * @property int|null $to_plan_id
 * @property Carbon|null $expires_at
 * @property int|null $changed_by
 * @property string|null $reason
 * @property Carbon $created_at
 */
class PlanChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'from_plan_id', 'to_plan_id', 'expires_at', 'changed_by', 'reason'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

<?php

namespace App\Services;

use App\Enums\Feature;
use App\Enums\Metric;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What a user's plan allows, and how much of it they've used. Every feature asks here before doing something
 * that costs money or is plan-only. Periods (a day of texts, a month of call time) follow the user's own timezone.
 */
class Entitlements
{
    /** The plan in effect: theirs if it hasn't expired, otherwise the default (Free). */
    public function planFor(User $user): Plan
    {
        if ($user->plan_id && (! $user->plan_expires_at || $user->plan_expires_at->isFuture()) && $user->plan) {
            return $user->plan;
        }

        return Plan::default();
    }

    public function can(User $user, Feature $feature): bool
    {
        return $this->planFor($user)->allows($feature);
    }

    /** A plain plan setting for this user (contacts, llm_tier, worry_max_stage, away_outreach_days). */
    public function setting(User $user, string $key, mixed $default = null): mixed
    {
        return $this->planFor($user)->setting($key, $default);
    }

    /** Allowed per period; null means no limit. */
    public function limit(User $user, Metric $metric): ?int
    {
        return $this->planFor($user)->limit($metric);
    }

    public function periodStart(User $user, Metric $metric): CarbonImmutable
    {
        return $metric->period()->start(CarbonImmutable::now($user->timezone));
    }

    /** When the count goes back to zero (in the user's timezone). */
    public function resetsAt(User $user, Metric $metric): CarbonImmutable
    {
        return $metric->period()->end(CarbonImmutable::now($user->timezone));
    }

    public function used(User $user, Metric $metric): int
    {
        return (int) DB::table('usage_counters')
            ->where('user_id', $user->id)
            ->where('metric', $metric->value)
            ->where('period_start', $this->periodStart($user, $metric)->toDateString())
            ->value('count');
    }

    /** What's left this period; null means no limit. */
    public function remaining(User $user, Metric $metric): ?int
    {
        $limit = $this->limit($user, $metric);

        return $limit === null ? null : max(0, $limit - $this->used($user, $metric));
    }

    /**
     * Uses up $amount if it fits within the limit, and says whether it did. Atomic: two requests at once can never
     * take the count past the limit, because the database only adds when the result still fits.
     */
    public function consume(User $user, Metric $metric, int $amount = 1): bool
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('Amount must be at least 1.');
        }

        $key = [
            'user_id' => $user->id,
            'metric' => $metric->value,
            'period_start' => $this->periodStart($user, $metric)->toDateString(),
        ];
        DB::table('usage_counters')->insertOrIgnore([...$key, 'count' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $row = DB::table('usage_counters')->where($key);
        $limit = $this->limit($user, $metric);
        if ($limit !== null) {
            $row->where('count', '<=', $limit - $amount);
        }

        return $row->increment('count', $amount, ['updated_at' => now()]) > 0;
    }

    /**
     * Puts a user on a plan (null expiry = until changed), and records who did it and why.
     */
    public function assignPlan(User $user, Plan $plan, ?DateTimeInterface $expiresAt, ?User $by = null, ?string $reason = null): void
    {
        // Saved in UTC: Eloquent stores a date's clock time as-is, so 23:59 in Chicago must become 05:59 UTC first.
        $expiresAt = $expiresAt ? CarbonImmutable::instance($expiresAt)->utc() : null;

        DB::transaction(function () use ($user, $plan, $expiresAt, $by, $reason) {
            PlanChange::create([
                'user_id' => $user->id,
                'from_plan_id' => $this->planFor($user)->id,
                'to_plan_id' => $plan->id,
                'expires_at' => $expiresAt,
                'changed_by' => $by?->id,
                'reason' => $reason,
            ]);

            // The default plan needs no row of its own: no plan means the default.
            $user->forceFill([
                'plan_id' => $plan->is_default ? null : $plan->id,
                'plan_expires_at' => $plan->is_default ? null : $expiresAt,
            ])->save();
            $user->unsetRelation('plan');
        });
    }
}

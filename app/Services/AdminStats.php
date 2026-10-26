<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Image;
use App\Models\LlmCall;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The numbers on the admin overview: who is using the service, what it earns and what the AI costs, over the last
 * `$days` days (money in cents, AI cost in micro-dollars, as stored).
 */
class AdminStats
{
    /**
     * @return array{
     *     users: array{total: int, new: int, active: int, suspended: int, paying: int},
     *     revenue: array{mrr_cents: int, paid_cents: int, failed: int, past_due: int, canceled: int},
     *     usage: array{calls: int, call_minutes: int, messages: int, pictures: int},
     *     ai: array{cost_micros: int, calls: int, errors: int, by_purpose: array<string, int>},
     *     margin_cents: int,
     * }
     */
    public function overview(int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);

        $paid = (int) Payment::where('status', 'paid')->where('occurred_at', '>=', $since)->sum('amount_cents');
        $aiMicros = (int) LlmCall::where('created_at', '>=', $since)->sum('cost_micros');

        $renewing = Subscription::with('plan')->whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])->get();

        return [
            'users' => [
                'total' => User::count(),
                'new' => User::where('created_at', '>=', $since)->count(),
                'active' => User::where('last_seen_at', '>=', $since)->count(),
                'suspended' => User::whereNotNull('suspended_at')->count(),
                'paying' => $renewing->pluck('user_id')->unique()->count(),
            ],
            'revenue' => [
                'mrr_cents' => (int) $renewing->sum(fn (Subscription $s) => $s->plan->price_cents),
                'paid_cents' => $paid,
                'failed' => Payment::where('status', 'failed')->where('occurred_at', '>=', $since)->count(),
                'past_due' => Subscription::where('status', Subscription::PAST_DUE)->count(),
                'canceled' => Subscription::where('status', Subscription::CANCELED)->where('canceled_at', '>=', $since)->count(),
            ],
            'usage' => [
                'calls' => Call::where('created_at', '>=', $since)->whereNotNull('started_at')->count(),
                'call_minutes' => intdiv((int) Call::where('created_at', '>=', $since)->sum('duration_seconds'), 60),
                'messages' => Message::where('sender', 'user')->where('created_at', '>=', $since)->count(),
                'pictures' => Image::where('created_at', '>=', $since)->count(),
            ],
            'ai' => [
                'cost_micros' => $aiMicros,
                'calls' => LlmCall::where('created_at', '>=', $since)->count(),
                'errors' => LlmCall::where('created_at', '>=', $since)->where('status', 'error')->count(),
                'by_purpose' => LlmCall::where('created_at', '>=', $since)
                    ->selectRaw('purpose, sum(cost_micros) as micros')->groupBy('purpose')->orderByDesc('micros')
                    ->pluck('micros', 'purpose')->map(fn ($micros) => (int) $micros)->all(),
            ],
            // What came in, less what the AI cost (micro-dollars are 1/10,000 of a cent).
            'margin_cents' => $paid - intdiv($aiMicros, 10_000),
        ];
    }

    /**
     * The latest charges, newest first.
     *
     * @return Collection<int, Payment>
     */
    public function recentPayments(int $limit = 8)
    {
        return Payment::latest('occurred_at')->limit($limit)->get();
    }
}

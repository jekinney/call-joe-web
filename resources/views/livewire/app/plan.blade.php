<?php

use App\Enums\Feature;
use App\Enums\Metric;
use App\Models\Plan;
use App\Services\Entitlements;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Plan & usage')] class extends Component {
    /**
     * @return array<string, mixed>
     */
    public function with(Entitlements $entitlements): array
    {
        $user = auth()->user();

        return [
            'plan' => $entitlements->planFor($user),
            'expires' => $user->plan_id ? $user->plan_expires_at : null,
            'contacts' => $entitlements->setting($user, 'contacts'),
            'usage' => collect(Metric::cases())->map(fn (Metric $metric) => [
                'metric' => $metric,
                'used' => $entitlements->used($user, $metric),
                'limit' => $entitlements->limit($user, $metric),
                'resets' => $entitlements->resetsAt($user, $metric),
            ]),
            'plans' => Plan::orderBy('sort')->get(),
            'features' => Feature::cases(),
            'upgrade' => Feature::tryFrom((string) session('upgrade')),
        ];
    }
}; ?>

<div class="flex w-full max-w-3xl flex-col gap-8">
    <div>
        <flux:heading size="xl">{{ __('Plan & usage') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('You\'re on :plan.', ['plan' => $plan->name]) }}
            @if ($expires)
                {{ __('It runs until :date.', ['date' => $expires->timezone(auth()->user()->timezone)->toFormattedDateString()]) }}
            @endif
        </flux:text>
    </div>

    @if ($upgrade)
        <flux:callout icon="sparkles" color="amber" :heading="__(':feature is part of a paid plan.', ['feature' => $upgrade->label()])" />
    @endif

    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('This period') }}</flux:heading>
        @foreach ($usage as $row)
            <div data-metric="{{ $row['metric']->value }}">
                <div class="flex justify-between text-sm">
                    <span>{{ __($row['metric']->label()) }}</span>
                    <span>
                        {{ $row['metric']->format($row['used']) }}
                        @if ($row['limit'] !== null)
                            / {{ $row['metric']->format($row['limit']) }}
                        @else
                            ({{ __('no limit') }})
                        @endif
                    </span>
                </div>
                @if ($row['limit'])
                    <div class="mt-1 h-2 w-full rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div class="h-2 rounded-full {{ $row['used'] >= $row['limit'] ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ min(100, intdiv($row['used'] * 100, $row['limit'])) }}%"></div>
                    </div>
                @endif
                <flux:text size="sm" class="mt-1">{{ __('Resets :when', ['when' => $row['resets']->diffForHumans()]) }}</flux:text>
            </div>
        @endforeach
        <flux:text size="sm">{{ __('Contacts at once: :n', ['n' => $contacts ?? __('no limit')]) }}</flux:text>
    </section>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Plans') }}</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($plans as $option)
                <div @class(['rounded-xl border p-4', 'border-emerald-500' => $option->is($plan), 'border-zinc-200 dark:border-zinc-700' => ! $option->is($plan)])>
                    <div class="flex items-center justify-between">
                        <flux:heading>{{ $option->name }}</flux:heading>
                        @if ($option->is($plan))
                            <flux:badge color="emerald" size="sm">{{ __('Your plan') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text class="mt-1">{{ $option->priceLabel() }}</flux:text>
                    <ul class="mt-3 space-y-1 text-sm">
                        @foreach (\App\Enums\Metric::cases() as $metric)
                            <li>{{ __($metric->label()) }}: {{ $option->limit($metric) === null ? __('no limit') : $metric->format($option->limit($metric)) }} / {{ __($metric->period()->value) }}</li>
                        @endforeach
                        @foreach ($features as $feature)
                            <li>{{ $option->allows($feature) ? '✓' : '—' }} {{ __($feature->label()) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
        <flux:text size="sm">{{ __('Upgrading online is coming soon.') }}</flux:text>
    </section>
</div>

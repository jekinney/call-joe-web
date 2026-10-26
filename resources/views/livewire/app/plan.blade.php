<?php

use App\Enums\Feature;
use App\Enums\Metric;
use App\Exceptions\PaymentException;
use App\Models\Plan;
use App\Services\Billing;
use App\Services\Entitlements;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Plan & usage')] class extends Component {
    /** The plan whose card form is open. */
    public ?int $checkoutPlanId = null;

    /** idle | loading | ready | paying */
    public string $checkout = 'idle';

    public string $problem = '';

    public string $notice = '';

    public function startCheckout(int $planId, Billing $billing): void
    {
        $this->reset('problem', 'notice');
        $plan = Plan::findOrFail($planId);
        abort_unless($billing->isPurchasable($plan), 404);

        try {
            $token = $billing->checkoutToken(auth()->user());
        } catch (PaymentException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->checkoutPlanId = $plan->id;
        $this->checkout = 'loading';
        // resources/js/billing.js shows the card form.
        $this->dispatch('billing-start', token: $token);
    }

    #[On('billing-ready')]
    public function ready(): void
    {
        $this->checkout = 'ready';
    }

    public function pay(): void
    {
        if ($this->checkout === 'ready') {
            $this->checkout = 'paying';
            $this->dispatch('billing-pay');
        }
    }

    #[On('billing-nonce')]
    public function subscribe(string $nonce, Billing $billing): void
    {
        $plan = $this->checkoutPlanId ? Plan::find($this->checkoutPlanId) : null;
        if (! $plan) {
            return;
        }

        try {
            $billing->subscribe(auth()->user(), $plan, $nonce);
        } catch (DomainException|PaymentException $e) {
            $this->problem = $e->getMessage();
            $this->checkout = 'ready'; // the card form is still open to try another card

            return;
        }

        $this->closeCheckout();
        $this->notice = __('You\'re on :plan. Thank you!', ['plan' => $plan->name]);
        auth()->user()->unsetRelation('plan');
    }

    #[On('billing-error')]
    public function failed(string $message = ''): void
    {
        $this->problem = $this->checkout === 'loading'
            ? __('The card form couldn\'t load. Check your connection and try again.')
            : __('That card didn\'t work. Check the details and try again.');
        $this->checkout = $this->checkout === 'loading' ? 'idle' : 'ready';
    }

    public function closeCheckout(): void
    {
        $this->checkoutPlanId = null;
        $this->checkout = 'idle';
        $this->dispatch('billing-stop');
    }

    public function cancelSubscription(Billing $billing): void
    {
        $this->reset('problem', 'notice');

        try {
            $subscription = $billing->cancel(auth()->user());
        } catch (DomainException|PaymentException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->notice = __('Your subscription is canceled. You keep :plan until :date.', [
            'plan' => $subscription->plan->name,
            'date' => $subscription->paid_through?->timezone(auth()->user()->timezone)->toFormattedDateString() ?? __('the end of this period'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Entitlements $entitlements, Billing $billing): array
    {
        $user = auth()->user();
        $plans = Plan::orderBy('sort')->get();

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
            'plans' => $plans,
            'purchasable' => $plans->filter(fn (Plan $plan) => $billing->isPurchasable($plan))->pluck('id')->all(),
            'subscription' => $billing->current($user),
            'features' => Feature::offered(),
            'upgrade' => collect(Feature::offered())->first(fn (Feature $feature) => $feature->value === session('upgrade')),
        ];
    }
}; ?>

<div class="flex w-full max-w-3xl flex-col gap-8">
    <div>
        <flux:heading size="xl">{{ __('Plan & usage') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('You\'re on :plan.', ['plan' => $plan->name]) }}
            @if ($expires && ! $subscription)
                {{ __('It runs until :date.', ['date' => $expires->timezone(auth()->user()->timezone)->toFormattedDateString()]) }}
            @endif
        </flux:text>
    </div>

    @if ($notice)
        <flux:callout icon="check-circle" color="emerald" :heading="$notice" data-notice />
    @endif
    @if ($problem)
        <flux:callout icon="exclamation-triangle" color="amber" :heading="$problem" data-problem />
    @endif

    @if ($upgrade)
        <flux:callout icon="sparkles" color="amber" :heading="__(':feature is part of a paid plan.', ['feature' => $upgrade->label()])" />
    @endif

    @if ($subscription)
        <section class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-subscription="{{ $subscription->status }}">
            <flux:heading>{{ __('Your subscription') }}</flux:heading>
            @if ($subscription->status === 'canceled')
                <flux:text>{{ __('Canceled. You keep :plan until :date, and you won\'t be charged again.', ['plan' => $subscription->plan->name, 'date' => $subscription->paid_through?->timezone(auth()->user()->timezone)->toFormattedDateString()]) }}</flux:text>
            @else
                <flux:text>
                    {{ __(':plan, :price. Renews around :date.', ['plan' => $subscription->plan->name, 'price' => $subscription->plan->priceLabel(), 'date' => $subscription->paid_through?->timezone(auth()->user()->timezone)->toFormattedDateString()]) }}
                </flux:text>
                @if ($subscription->status === 'past_due')
                    <flux:callout icon="exclamation-triangle" color="amber" :heading="__('Your last payment didn\'t go through. We\'ll try again, or you can update your card by subscribing again after canceling.')" />
                @endif
                <div>
                    <flux:button size="sm" variant="ghost" wire:click="cancelSubscription" wire:confirm="{{ __('Cancel your subscription? You keep your plan until the end of what you\'ve paid for.') }}">{{ __('Cancel subscription') }}</flux:button>
                </div>
            @endif
        </section>
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
                    @if (in_array($option->id, $purchasable, true) && ! $option->is($plan) && ! $subscription)
                        <flux:button class="mt-4" variant="primary" size="sm" wire:click="startCheckout({{ $option->id }})" data-subscribe="{{ $option->slug }}">{{ __('Subscribe') }}</flux:button>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($purchasable === [])
            <flux:text size="sm">{{ __('Upgrading online is coming soon.') }}</flux:text>
        @endif
    </section>

    @if ($checkoutPlanId)
        <section class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-checkout="{{ $checkout }}">
            <flux:heading>{{ __('Payment') }}</flux:heading>
            <flux:text size="sm">{{ __('Your card details go straight to our payment processor, Braintree. We never see or store them. You can cancel any time.') }}</flux:text>
            <div id="billing-dropin" wire:ignore></div>
            <div class="flex gap-2">
                <flux:button variant="primary" wire:click="pay" :disabled="$checkout !== 'ready'">{{ $checkout === 'paying' ? __('Paying…') : __('Subscribe') }}</flux:button>
                <flux:button variant="ghost" wire:click="closeCheckout">{{ __('Not now') }}</flux:button>
            </div>
        </section>
    @endif
</div>

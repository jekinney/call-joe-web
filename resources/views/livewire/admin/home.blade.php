<?php

use App\Services\AdminStats;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/*
 * The overview: users, money in, AI cost and use over the last 7, 30 or 90 days (App\Services\AdminStats).
 */
new #[Layout('components.layouts.admin'), Title('Admin')] class extends Component {
    #[Url]
    public int $days = 30;

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $days = in_array($this->days, [7, 30, 90], true) ? $this->days : 30;
        $stats = app(AdminStats::class);

        return ['days' => $days, 'stats' => $stats->overview($days), 'payments' => $stats->recentPayments()];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('Admin') }}</flux:heading>
        <flux:select wire:model.live="days" class="max-w-40">
            <flux:select.option value="7">{{ __('Last 7 days') }}</flux:select.option>
            <flux:select.option value="30">{{ __('Last 30 days') }}</flux:select.option>
            <flux:select.option value="90">{{ __('Last 90 days') }}</flux:select.option>
        </flux:select>
    </div>

    @php
        $money = fn (int $cents) => '$'.number_format($cents / 100, 2);
        $tiles = [
            __('People') => [
                [__('Users'), number_format($stats['users']['total'])],
                [__('New'), number_format($stats['users']['new'])],
                [__('Active'), number_format($stats['users']['active'])],
                [__('Suspended'), number_format($stats['users']['suspended'])],
            ],
            __('Money') => [
                [__('Paying users'), number_format($stats['users']['paying'])],
                [__('Monthly recurring'), $money($stats['revenue']['mrr_cents'])],
                [__('Charged'), $money($stats['revenue']['paid_cents'])],
                [__('Past due'), number_format($stats['revenue']['past_due'])],
                [__('Failed charges'), number_format($stats['revenue']['failed'])],
                [__('Canceled'), number_format($stats['revenue']['canceled'])],
            ],
            __('Use') => [
                [__('Calls'), number_format($stats['usage']['calls'])],
                [__('Call minutes'), number_format($stats['usage']['call_minutes'])],
                [__('Texts sent'), number_format($stats['usage']['messages'])],
                [__('Pictures'), number_format($stats['usage']['pictures'])],
            ],
            __('AI') => [
                [__('Cost'), '$'.number_format($stats['ai']['cost_micros'] / 1_000_000, 2)],
                [__('Requests'), number_format($stats['ai']['calls'])],
                [__('Errors'), number_format($stats['ai']['errors'])],
                [__('Charged less AI cost'), $money($stats['margin_cents'])],
            ],
        ];
    @endphp

    @foreach ($tiles as $heading => $group)
        <section class="flex flex-col gap-2">
            <flux:heading>{{ $heading }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                @foreach ($group as [$label, $value])
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text size="sm">{{ $label }}</flux:text>
                        <div class="text-xl font-semibold">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($stats['ai']['by_purpose'])
        <section class="flex flex-col gap-2">
            <flux:heading>{{ __('AI cost by job') }}</flux:heading>
            <flux:text>
                @foreach ($stats['ai']['by_purpose'] as $purpose => $micros)
                    <span class="mr-4">{{ ucfirst($purpose) }} ${{ number_format($micros / 1_000_000, 2) }}</span>
                @endforeach
            </flux:text>
        </section>
    @endif

    <section class="flex flex-col gap-2">
        <flux:heading>{{ __('Latest charges') }}</flux:heading>
        @forelse ($payments as $payment)
            <div class="flex items-center justify-between gap-4 border-b border-zinc-200 py-2 dark:border-zinc-700">
                <a href="{{ route('admin.users.show', $payment->user_id) }}" wire:navigate class="hover:underline">{{ $payment->user->name }}</a>
                <flux:text>{{ $payment->occurred_at->diffForHumans() }}</flux:text>
                <flux:badge :color="$payment->status === 'paid' ? 'green' : 'red'" size="sm">{{ $payment->status }}</flux:badge>
                <span>{{ $money($payment->amount_cents) }}</span>
            </div>
        @empty
            <flux:text>{{ __('No charges yet.') }}</flux:text>
        @endforelse
    </section>
</div>

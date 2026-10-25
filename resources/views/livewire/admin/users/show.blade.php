<?php

use App\Enums\Metric;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.admin'), Title('User')] class extends Component {
    public User $user;

    public ?int $planId = null;

    public string $expiresOn = '';

    public string $reason = '';

    public function mount(User $user, Entitlements $entitlements): void
    {
        $this->user = $user;
        $this->planId = $entitlements->planFor($user)->id;
        $this->expiresOn = $user->plan_id ? ($user->plan_expires_at?->timezone($user->timezone)->toDateString() ?? '') : '';
    }

    /**
     * Put the user on the chosen plan. An expiry date runs to the end of that day in the user's timezone.
     */
    public function savePlan(Entitlements $entitlements): void
    {
        $this->validate([
            'planId' => ['required', 'integer', 'exists:plans,id'],
            'expiresOn' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $plan = Plan::findOrFail($this->planId);
        $expires = $this->expiresOn !== '' ? Carbon::parse($this->expiresOn, $this->user->timezone)->endOfDay() : null;

        $entitlements->assignPlan($this->user, $plan, $expires, auth()->user(), $this->reason ?: null);

        $this->reason = '';
        $this->user->refresh();
        $this->dispatch('plan-saved');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Entitlements $entitlements): array
    {
        return [
            'plans' => Plan::orderBy('sort')->get(),
            'current' => $entitlements->planFor($this->user),
            'usage' => collect(Metric::cases())->map(fn (Metric $metric) => [
                'metric' => $metric,
                'used' => $entitlements->used($this->user, $metric),
                'limit' => $entitlements->limit($this->user, $metric),
            ]),
            'history' => $this->user->planChanges()->with(['fromPlan', 'toPlan', 'changedBy'])->limit(20)->get(),
        ];
    }
}; ?>

<div class="flex w-full max-w-3xl flex-col gap-8">
    <div>
        <a href="{{ route('admin.users.index') }}" class="text-sm underline-offset-2 hover:underline" wire:navigate>← {{ __('Users') }}</a>
        <flux:heading size="xl" class="mt-2">{{ $user->name }}</flux:heading>
        <flux:text>{{ $user->email }} · {{ __('joined :date', ['date' => $user->created_at?->toFormattedDateString()]) }} · {{ $user->timezone }}</flux:text>
    </div>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Plan') }}</flux:heading>
        <flux:text>{{ __('Now on :plan.', ['plan' => $current->name]) }}</flux:text>
        <form wire:submit="savePlan" class="flex flex-col gap-4">
            <flux:select wire:model="planId" :label="__('Plan')">
                @foreach ($plans as $plan)
                    <flux:select.option :value="$plan->id">{{ $plan->name }} ({{ $plan->priceLabel() }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="expiresOn" type="date" :label="__('Until (optional)')" :description="__('Leave empty to keep it until changed. Afterwards they go back to Free.')" />
            <flux:input wire:model="reason" :label="__('Reason (optional)')" :placeholder="__('Beta tester, refund, comp…')" />
            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">{{ __('Save plan') }}</flux:button>
                <x-action-message on="plan-saved">{{ __('Saved.') }}</x-action-message>
            </div>
        </form>
    </section>

    <section class="flex flex-col gap-2">
        <flux:heading size="lg">{{ __('Usage this period') }}</flux:heading>
        @foreach ($usage as $row)
            <flux:text>{{ __($row['metric']->label()) }}: {{ $row['metric']->format($row['used']) }} / {{ $row['limit'] === null ? __('no limit') : $row['metric']->format($row['limit']) }}</flux:text>
        @endforeach
    </section>

    <section class="flex flex-col gap-2">
        <flux:heading size="lg">{{ __('Plan history') }}</flux:heading>
        @forelse ($history as $change)
            <flux:text size="sm">
                {{ $change->created_at->toDayDateTimeString() }}:
                {{ $change->fromPlan?->name ?? '?' }} → {{ $change->toPlan?->name ?? '?' }}
                @if ($change->expires_at) ({{ __('until :date', ['date' => $change->expires_at->toFormattedDateString()]) }}) @endif
                · {{ $change->changedBy?->name ?? __('system') }}
                @if ($change->reason) · “{{ $change->reason }}” @endif
            </flux:text>
        @empty
            <flux:text size="sm">{{ __('No changes yet.') }}</flux:text>
        @endforelse
    </section>
</div>

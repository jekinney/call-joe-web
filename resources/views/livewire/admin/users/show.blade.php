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

    public string $suspendReason = '';

    /** Shut the account out of /app (an admin can't suspend themselves). */
    public function suspend(): void
    {
        $this->validate(['suspendReason' => ['required', 'string', 'max:200']]);
        abort_if($this->user->is(auth()->user()), 422, 'You can\'t suspend yourself.');

        $this->user->suspend($this->suspendReason, auth()->user());
        $this->suspendReason = '';
    }

    public function unsuspend(): void
    {
        $this->user->unsuspend();
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

    <section class="flex flex-col gap-4" data-safety>
        <flux:heading size="lg">{{ __('Safety') }}</flux:heading>
        <flux:text>
            {{ trans_choice(':count blocked text|:count blocked texts', $user->flags['strikes'] ?? 0, ['count' => $user->flags['strikes'] ?? 0]) }}
            · <a href="{{ route('admin.moderation', ['user' => $user->id, 'show' => 'all']) }}" class="underline" wire:navigate>{{ __('Safety events') }}</a>
        </flux:text>
        @if ($user->isSuspended())
            <flux:callout icon="no-symbol" color="red" :heading="__('Suspended :date', ['date' => $user->suspended_at->toFormattedDateString()])">
                @if ($user->flags['suspension_reason'] ?? null)
                    <flux:callout.text>“{{ $user->flags['suspension_reason'] }}”</flux:callout.text>
                @endif
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="unsuspend">{{ __('Unsuspend') }}</flux:button>
                </x-slot>
            </flux:callout>
        @else
            <form wire:submit="suspend" class="flex flex-col gap-3">
                <flux:input wire:model="suspendReason" :label="__('Reason for suspending')" :placeholder="__('What happened')" />
                <div><flux:button type="submit" variant="danger" wire:confirm="{{ __('Suspend :name? They lose access to the app right away.', ['name' => $user->name]) }}">{{ __('Suspend') }}</flux:button></div>
            </form>
        @endif
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

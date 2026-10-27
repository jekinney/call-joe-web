<?php

use Illuminate\Support\Facades\Gate;
use App\Enums\Metric;
use App\Enums\Permission;
use App\Models\Plan;
use App\Models\Role;
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
        $this->roleId = $user->role_id;
        $this->expiresOn = $user->plan_id ? ($user->plan_expires_at?->timezone($user->timezone)->toDateString() ?? '') : '';
    }

    /**
     * Put the user on the chosen plan. An expiry date runs to the end of that day in the user's timezone.
     */
    public function savePlan(Entitlements $entitlements): void
    {
        Gate::authorize('users.plan');
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

    public ?int $roleId = null;

    /**
     * Make them an admin with a role, or a regular user again (empty). The Owner role only comes from the command line,
     * nobody changes their own role or an owner's, and a role can't carry permissions the giver lacks.
     */
    public function saveRole(): void
    {
        Gate::authorize('roles.manage');
        $this->validate(['roleId' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
            if (! Role::whereKey($value)->where('is_owner', false)->exists()) {
                $fail(__('Pick one of the listed roles.'));
            }
        }]]);
        abort_if($this->user->is(auth()->user()), 422, 'You can\'t change your own role.');
        abort_if($this->user->isOwner(), 403, 'An owner\'s role is changed on the command line.');

        if ($this->roleId !== null) {
            $missing = collect(Role::findOrFail($this->roleId)->permissions)
                ->reject(fn (string $value) => ($permission = Permission::tryFrom($value)) === null || auth()->user()->hasPermission($permission));
            if ($missing->isNotEmpty()) {
                $this->addError('roleId', __('That role allows things you can\'t allow yourself.'));

                return;
            }
        }

        $this->user->forceFill(['role_id' => $this->roleId])->save();
        $this->dispatch('role-saved');
    }

    public string $suspendReason = '';

    /** Shut the account out of /app (an admin can't suspend themselves). */
    public function suspend(): void
    {
        Gate::authorize('users.suspend');
        $this->validate(['suspendReason' => ['required', 'string', 'max:200']]);
        abort_if($this->user->is(auth()->user()), 422, 'You can\'t suspend yourself.');

        $this->user->suspend($this->suspendReason, auth()->user());
        $this->suspendReason = '';
    }

    public function unsuspend(): void
    {
        Gate::authorize('users.suspend');
        $this->user->unsuspend();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Entitlements $entitlements): array
    {
        return [
            'plans' => Plan::orderBy('sort')->get(),
            'roles' => Role::where('is_owner', false)->orderBy('name')->get(),
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

    @can('users.plan')
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
    @endcan

    @can('roles.manage')
        <section class="flex flex-col gap-4" data-role-section>
            <flux:heading size="lg">{{ __('Admin role') }}</flux:heading>
            @if ($user->isOwner())
                <flux:text>{{ __('Owner. Changed on the command line only.') }}</flux:text>
            @elseif ($user->is(auth()->user()))
                <flux:text>{{ $user->role?->name ?? __('No role.') }} {{ __('You can\'t change your own role.') }}</flux:text>
            @else
                <form wire:submit="saveRole" class="flex flex-col gap-4">
                    <flux:select wire:model="roleId" :label="__('Role')" :description="__('No role means a regular user; their tier is their plan.')">
                        <flux:select.option value="">{{ __('No role (regular user)') }}</flux:select.option>
                        @foreach ($roles as $role)
                            <flux:select.option :value="$role->id">{{ $role->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="roleId" />
                    <div class="flex items-center gap-4">
                        <flux:button type="submit" variant="primary">{{ __('Save role') }}</flux:button>
                        <x-action-message on="role-saved">{{ __('Saved.') }}</x-action-message>
                    </div>
                </form>
            @endif
        </section>
    @endcan

    @can('users.suspend')
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
    @endcan

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

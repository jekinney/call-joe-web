<?php

use Illuminate\Support\Facades\Gate;
use App\Enums\ModerationCategory;
use App\Models\ModerationEvent;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * The safety queue: everything App\Services\Moderator blocked or flagged, newest first. An admin marks each one
 * reviewed, and can suspend the account from here.
 */
new #[Layout('components.layouts.admin'), Title('Safety')] class extends Component {
    use WithPagination;

    /** open (not reviewed yet) | all */
    #[Url]
    public string $show = 'open';

    #[Url]
    public string $category = '';

    /** Only this user's events. */
    #[Url]
    public ?int $user = null;

    public function updated(): void
    {
        $this->resetPage();
    }

    public function review(int $id): void
    {
        Gate::authorize('safety.review');
        ModerationEvent::whereKey($id)->whereNull('reviewed_at')->update(['reviewed_at' => now(), 'reviewed_by' => auth()->id()]);
    }

    /** Suspends the account behind an event, and marks it reviewed. */
    public function suspend(int $id): void
    {
        Gate::authorize('safety.review');
        Gate::authorize('users.suspend');
        $event = ModerationEvent::with('user')->findOrFail($id);
        abort_if($event->user->is(auth()->user()), 422, 'You can\'t suspend yourself.');
        $event->user->suspend("Safety: {$event->category->label()} (event #{$event->id})", auth()->user());
        $this->review($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $events = ModerationEvent::with('user')
            ->when($this->show !== 'all', fn ($q) => $q->whereNull('reviewed_at'))
            ->when(ModerationCategory::tryFrom($this->category), fn ($q, $category) => $q->where('category', $category))
            ->when($this->user, fn ($q, $user) => $q->where('user_id', $user))
            ->latest('id');

        return [
            'events' => $events->paginate(25),
            'open' => ModerationEvent::whereNull('reviewed_at')->count(),
            'categories' => ModerationCategory::cases(),
            'only' => $this->user ? User::find($this->user) : null,
        ];
    }
}; ?>

<div class="flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Safety') }}</flux:heading>
        <flux:text class="mt-1">{{ trans_choice(':count item to review|:count items to review', $open, ['count' => $open]) }}</flux:text>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <flux:radio.group wire:model.live="show" variant="segmented">
            <flux:radio value="open">{{ __('To review') }}</flux:radio>
            <flux:radio value="all">{{ __('All') }}</flux:radio>
        </flux:radio.group>
        <flux:select wire:model.live="category" class="max-w-48" :placeholder="__('Every category')">
            <flux:select.option value="">{{ __('Every category') }}</flux:select.option>
            @foreach ($categories as $option)
                <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($only)
            <flux:badge>{{ $only->email }} <button type="button" class="ml-1" wire:click="$set('user', null)" aria-label="{{ __('Everyone') }}">×</button></flux:badge>
        @endif
    </div>

    <flux:table :paginate="$events">
        <flux:table.columns>
            <flux:table.column>{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Who') }}</flux:table.column>
            <flux:table.column>{{ __('What') }}</flux:table.column>
            <flux:table.column>{{ __('Excerpt') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($events as $event)
                <flux:table.row :key="$event->id" data-event="{{ $event->id }}">
                    <flux:table.cell class="whitespace-nowrap">{{ $event->created_at->diffForHumans() }}</flux:table.cell>
                    <flux:table.cell>
                        <a href="{{ route('admin.users.show', $event->user) }}" class="underline" wire:navigate>{{ $event->user->email }}</a>
                        @if ($event->user->isSuspended()) <flux:badge size="sm" color="red">{{ __('suspended') }}</flux:badge> @endif
                        @if ($event->details['strikes'] ?? null) <flux:text size="sm">{{ trans_choice(':count strike|:count strikes', $event->details['strikes'], ['count' => $event->details['strikes']]) }}</flux:text> @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$event->action === 'blocked' ? 'red' : 'amber'">{{ __($event->action) }}</flux:badge>
                        {{ __($event->category->label()) }}
                        <flux:text size="sm">{{ str_replace('_', ' ', $event->source) }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-sm whitespace-normal text-sm">{{ $event->excerpt }}</flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">
                        @if ($event->reviewed_at)
                            <flux:text size="sm">{{ __('Reviewed') }}</flux:text>
                        @else
                            <flux:button size="sm" wire:click="review({{ $event->id }})">{{ __('Reviewed') }}</flux:button>
                            @unless ($event->user->isSuspended())
                                <flux:button size="sm" variant="danger" wire:click="suspend({{ $event->id }})" wire:confirm="{{ __('Suspend :email?', ['email' => $event->user->email]) }}">{{ __('Suspend') }}</flux:button>
                            @endunless
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">{{ __('Nothing here.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>

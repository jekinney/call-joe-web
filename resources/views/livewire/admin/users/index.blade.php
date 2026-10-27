<?php

use App\Models\User;
use App\Services\Entitlements;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin'), Title('Users')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Entitlements $entitlements): array
    {
        $term = trim($this->search);

        return [
            'users' => User::query()
                ->with(['plan', 'role'])
                ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->whereLike('name', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('email', "%{$term}%", caseSensitive: false)))
                ->orderByDesc('id')
                ->paginate(25),
            'entitlements' => $entitlements,
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <flux:heading size="xl">{{ __('Users') }}</flux:heading>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or email')" class="max-w-md" />

    <flux:table :paginate="$users">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Plan') }}</flux:table.column>
            <flux:table.column>{{ __('Last seen') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell>
                        <a href="{{ route('admin.users.show', $user) }}" class="font-medium underline-offset-2 hover:underline" wire:navigate>{{ $user->name }}</a>
                        @if ($user->role)
                            <flux:badge color="amber" size="sm">{{ $user->role->name }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>{{ $entitlements->planFor($user)->name }}</flux:table.cell>
                    <flux:table.cell>{{ $user->last_seen_at?->diffForHumans() ?? __('never') }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">{{ __('No users match.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>

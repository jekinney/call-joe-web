<?php

use App\Models\Contact;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.admin'), Title('Contacts')] class extends Component {
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
    public function with(): array
    {
        $term = trim($this->search);

        return [
            'contacts' => Contact::query()
                ->with('requiredPlan')
                ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->whereLike('name', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('tagline', "%{$term}%", caseSensitive: false)))
                ->orderBy('name')
                ->paginate(25),
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Contacts') }}</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('admin.contacts.create')" wire:navigate>{{ __('New contact') }}</flux:button>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or tagline')" class="max-w-md" />

    <flux:table :paginate="$contacts">
        <flux:table.columns>
            <flux:table.column>{{ __('Contact') }}</flux:table.column>
            <flux:table.column>{{ __('Plan needed') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Version') }}</flux:table.column>
            <flux:table.column>{{ __('Updated') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($contacts as $contact)
                <flux:table.row :key="$contact->id">
                    <flux:table.cell>
                        <div class="flex items-center gap-3">
                            <flux:avatar size="sm" :src="$contact->avatarUrl()" :name="$contact->name" />
                            <div>
                                <a href="{{ route('admin.contacts.edit', $contact) }}" class="font-medium underline-offset-2 hover:underline" wire:navigate>{{ $contact->name }}</a>
                                <div class="max-w-xs truncate text-xs text-zinc-500" title="{{ $contact->tagline }}">{{ $contact->tagline }}</div>
                            </div>
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $contact->requiredPlan?->name ?? __('Any') }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($contact->is_published)
                            <flux:badge color="emerald" size="sm">{{ __('Published') }}</flux:badge>
                        @else
                            <flux:badge size="sm">{{ __('Draft') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>v{{ $contact->version }}</flux:table.cell>
                    <flux:table.cell>{{ $contact->updated_at?->diffForHumans() }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">{{ __('No contacts yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>

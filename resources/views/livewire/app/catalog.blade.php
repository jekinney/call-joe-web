<?php

use App\Enums\AddCheck;
use App\Models\Contact;
use App\Services\Phonebook;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Catalog')] class extends Component {
    #[Url]
    public string $search = '';

    #[Url]
    public bool $availableOnly = false;

    public function add(int $contactId, Phonebook $phonebook): void
    {
        $contact = Contact::published()->findOrFail($contactId);

        try {
            $phonebook->add(auth()->user(), $contact);
            $this->dispatch('contact-added', name: $contact->firstName());
        } catch (DomainException $e) {
            $this->addError('add', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Phonebook $phonebook): array
    {
        $user = auth()->user();
        $term = trim($this->search);

        $cards = Contact::published()
            ->with('requiredPlan')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('name', "%{$term}%", caseSensitive: false)
                ->orWhereLike('tagline', "%{$term}%", caseSensitive: false)))
            ->orderBy('name')
            ->get()
            ->map(fn (Contact $contact) => ['contact' => $contact, 'check' => $phonebook->check($user, $contact)])
            ->when($this->availableOnly, fn ($cards) => $cards->filter(fn ($card) => $card['check'] !== AddCheck::Locked));

        return [
            'cards' => $cards,
            'count' => $phonebook->count($user),
            'limit' => $phonebook->limit($user),
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Catalog') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Find a friend to add to your phone.') }}</flux:text>
        </div>
        <flux:text size="sm" data-count>
            {{ $limit === null ? __(':count contacts on your phone', ['count' => $count]) : __(':count of :limit contacts on your phone', ['count' => $count, 'limit' => $limit]) }}
        </flux:text>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or what they\'re like')" class="max-w-md" />
        <flux:switch wire:model.live="availableOnly" :label="__('Only ones my plan includes')" />
    </div>

    <flux:error name="add" />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($cards as $card)
            @php($contact = $card['contact'])
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="card-{{ $contact->id }}" data-contact="{{ $contact->slug }}">
                <a href="{{ route('app.catalog.show', $contact) }}" class="flex items-center gap-3" wire:navigate>
                    <flux:avatar size="lg" :src="$contact->avatarUrl()" :name="$contact->name" />
                    <div class="min-w-0">
                        <flux:heading>{{ $contact->name }}</flux:heading>
                        <flux:text size="sm" class="line-clamp-2">{{ $contact->tagline }}</flux:text>
                    </div>
                </a>
                <div class="mt-auto flex items-center justify-between">
                    @switch($card['check'])
                        @case(\App\Enums\AddCheck::AlreadyAdded)
                            <flux:badge color="emerald" size="sm" icon="check">{{ __('On your phone') }}</flux:badge>
                            @break
                        @case(\App\Enums\AddCheck::Locked)
                            <flux:badge color="amber" size="sm" icon="lock-closed">{{ __(':plan plan', ['plan' => $contact->requiredPlan?->name]) }}</flux:badge>
                            <flux:button size="sm" :href="route('app.plan')" wire:navigate>{{ __('See plans') }}</flux:button>
                            @break
                        @case(\App\Enums\AddCheck::LimitReached)
                            <flux:text size="sm">{{ __('Your contacts are full') }}</flux:text>
                            <flux:button size="sm" :href="route('app.plan')" wire:navigate>{{ __('See plans') }}</flux:button>
                            @break
                        @default
                            <span></span>
                            <flux:button size="sm" variant="primary" icon="plus" wire:click="add({{ $contact->id }})">{{ __('Add') }}</flux:button>
                    @endswitch
                </div>
            </div>
        @empty
            <flux:text>{{ __('No one matches that yet.') }}</flux:text>
        @endforelse
    </div>
</div>

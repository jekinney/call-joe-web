<?php

use App\Models\UserContact;
use App\Services\Phonebook;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('My contacts')] class extends Component {
    public function remove(int $entryId, Phonebook $phonebook): void
    {
        $entry = UserContact::where('user_id', auth()->id())->findOrFail($entryId);
        $phonebook->remove(auth()->user(), $entry->contact);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Phonebook $phonebook): array
    {
        $user = auth()->user();

        return [
            'entries' => $user->userContacts()->with('contact')->get(),
            'count' => $phonebook->count($user),
            'limit' => $phonebook->limit($user),
        ];
    }
}; ?>

<div class="flex w-full max-w-2xl flex-col gap-6">
    <div class="flex items-end justify-between">
        <div>
            <flux:heading size="xl">{{ __('My contacts') }}</flux:heading>
            <flux:text class="mt-1">{{ $limit === null ? __(':count on your phone', ['count' => $count]) : __(':count of :limit on your phone', ['count' => $count, 'limit' => $limit]) }}</flux:text>
        </div>
        <flux:button icon="plus" :href="route('app.catalog')" wire:navigate>{{ __('Find more') }}</flux:button>
    </div>

    @forelse ($entries as $entry)
        <div class="flex items-center justify-between gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-700" wire:key="entry-{{ $entry->id }}">
            <a href="{{ route('app.catalog.show', $entry->contact) }}" class="flex items-center gap-3" wire:navigate>
                <flux:avatar :src="$entry->contact->avatarUrl()" :name="$entry->contact->name" />
                <div>
                    <flux:heading>{{ $entry->displayName() }}</flux:heading>
                    <flux:text size="sm">{{ $entry->contact->tagline }}</flux:text>
                </div>
            </a>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="remove({{ $entry->id }})"
                wire:confirm="{{ __('Remove :name from your phone? Everything you shared is kept, and comes back if you add them again.', ['name' => $entry->displayName()]) }}">
                {{ __('Remove') }}
            </flux:button>
        </div>
    @empty
        <flux:text>{{ __('No one yet.') }} <a href="{{ route('app.catalog') }}" class="underline" wire:navigate>{{ __('Browse the catalog') }}</a>.</flux:text>
    @endforelse
</div>

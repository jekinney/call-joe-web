<?php

use App\Models\Contact;
use App\Models\UserContact;
use App\Services\MemoryWriter;
use App\Services\Phonebook;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('What they remember')] class extends Component {
    public UserContact $entry;

    /** First of two steps before forgetting everything. */
    public bool $confirmingReset = false;

    public function mount(Contact $contact, Phonebook $phonebook): void
    {
        $this->entry = $phonebook->entry(auth()->user(), $contact) ?? abort(404);
    }

    public function forget(int $index, MemoryWriter $writer): void
    {
        $writer->forget($this->entry, $index);
        $this->dispatch('forgotten');
    }

    public function forgetEverything(MemoryWriter $writer): void
    {
        $writer->reset($this->entry);
        $this->confirmingReset = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $memory = $this->entry->memory()->first();

        return ['lines' => $memory?->lines() ?? [], 'updated' => $memory?->updated_at, 'name' => $this->entry->displayName()];
    }
}; ?>

<div class="flex w-full max-w-2xl flex-col gap-6">
    <a href="{{ route('app.chat', $entry->contact) }}" class="text-sm underline-offset-2 hover:underline" wire:navigate>← {{ __('Texts with :name', ['name' => $name]) }}</a>

    <div>
        <flux:heading size="xl">{{ __('What :name remembers', ['name' => $name]) }}</flux:heading>
        <flux:text class="mt-1">
            {{ __(':name keeps these notes about you, and only :name sees them. They update a little while after you talk.', ['name' => $name]) }}
            @if ($updated)
                {{ __('Last updated :when.', ['when' => $updated->diffForHumans()]) }}
            @endif
        </flux:text>
    </div>

    @forelse ($lines as $i => $line)
        <div class="flex items-start justify-between gap-4 border-b border-zinc-200 pb-3 dark:border-zinc-700" wire:key="line-{{ $i }}-{{ md5($line) }}">
            <flux:text class="text-zinc-900 dark:text-zinc-100">{{ $line }}</flux:text>
            <flux:button size="sm" variant="ghost" wire:click="forget({{ $i }})" wire:confirm="{{ __(':name will forget this and won\'t write it down again. Forget it?', ['name' => $name]) }}">
                {{ __('Forget') }}
            </flux:button>
        </div>
    @empty
        <flux:text>{{ __(':name doesn\'t remember anything about you yet. Text them and they\'ll start getting to know you.', ['name' => $name]) }}</flux:text>
    @endforelse

    @if ($lines)
        <div class="flex flex-col gap-3 pt-4">
            @if (! $confirmingReset)
                <div><flux:button variant="danger" wire:click="$set('confirmingReset', true)">{{ __('Forget everything') }}</flux:button></div>
            @else
                <flux:callout icon="exclamation-triangle" color="red" :heading="__(':name will forget everything about you, and start over. Your texts stay. This can\'t be undone here.', ['name' => $name])" />
                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="$set('confirmingReset', false)">{{ __('Keep the notes') }}</flux:button>
                    <flux:button variant="danger" wire:click="forgetEverything" wire:confirm="{{ __('Last check: forget everything?') }}">{{ __('Yes, forget everything') }}</flux:button>
                </div>
            @endif
        </div>
    @endif
</div>

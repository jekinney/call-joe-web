<?php

use App\Enums\AddCheck;
use App\Models\Contact;
use App\Services\Phonebook;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Contact')] class extends Component {
    public Contact $contact;

    public function mount(Contact $contact, Phonebook $phonebook): void
    {
        // Hidden or removed from the catalog: only people who already have them can still see them.
        abort_unless($contact->is_published || $phonebook->entry(auth()->user(), $contact), 404);
        $this->contact = $contact;
    }

    public function add(Phonebook $phonebook): void
    {
        try {
            $phonebook->add(auth()->user(), $this->contact);
            $this->dispatch('contact-added', name: $this->contact->firstName());
        } catch (DomainException $e) {
            $this->addError('add', $e->getMessage());
        }
    }

    public function remove(Phonebook $phonebook): void
    {
        $phonebook->remove(auth()->user(), $this->contact);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Phonebook $phonebook): array
    {
        return ['check' => $phonebook->check(auth()->user(), $this->contact)];
    }
}; ?>

<div class="flex w-full max-w-2xl flex-col gap-6">
    <a href="{{ route('app.catalog') }}" class="text-sm underline-offset-2 hover:underline" wire:navigate>← {{ __('Catalog') }}</a>

    <div class="flex items-center gap-5">
        <flux:avatar size="xl" :src="$contact->avatarUrl()" :name="$contact->name" />
        <div>
            <flux:heading size="xl">{{ $contact->name }}</flux:heading>
            <flux:text class="mt-1">{{ $contact->tagline }}</flux:text>
            @if ($contact->requiredPlan)
                <flux:badge color="amber" size="sm" class="mt-2">{{ __(':plan plan', ['plan' => $contact->requiredPlan->name]) }}</flux:badge>
            @endif
        </div>
    </div>

    <div class="flex flex-col gap-2">
        @if ($check === AddCheck::AlreadyAdded)
            <flux:text>{{ __(':name is on your phone.', ['name' => $contact->firstName()]) }}</flux:text>
            <div>
                <flux:button wire:click="remove" wire:confirm="{{ __('Remove :name from your phone? Everything you shared is kept, and comes back if you add them again.', ['name' => $contact->firstName()]) }}">
                    {{ __('Remove from my phone') }}
                </flux:button>
            </div>
        @elseif ($check === AddCheck::Ok)
            <div><flux:button variant="primary" icon="plus" wire:click="add">{{ __('Add :name to my phone', ['name' => $contact->firstName()]) }}</flux:button></div>
        @else
            <flux:callout icon="information-circle" :heading="__($check->message())" />
            @if (in_array($check, [AddCheck::Locked, AddCheck::LimitReached], true))
                <div><flux:button :href="route('app.plan')" wire:navigate>{{ __('See plans') }}</flux:button></div>
            @endif
        @endif
        <flux:error name="add" />
    </div>
</div>

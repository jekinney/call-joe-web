<?php

use App\Enums\AddCheck;
use App\Models\Contact;
use App\Models\Relationship;
use App\Services\Phonebook;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Contact')] class extends Component {
    public Contact $contact;

    /** Who they are (or will be) to the user. */
    public string $relationshipId = '';

    public function mount(Contact $contact, Phonebook $phonebook): void
    {
        $entry = $phonebook->entry(auth()->user(), $contact);

        // Hidden or removed from the catalog: only people who already have them can still see them.
        abort_unless($contact->is_published || $entry, 404);
        $this->contact = $contact;
        $this->relationshipId = (string) ($entry?->relationship_id ?? Relationship::bySlug('friend')->id);
    }

    public function add(Phonebook $phonebook): void
    {
        try {
            $phonebook->add(auth()->user(), $this->contact, Relationship::findOrFail((int) $this->relationshipId));
            $this->dispatch('contact-added', name: $this->contact->firstName());
        } catch (DomainException $e) {
            $this->addError('add', $e->getMessage());
        }
    }

    /** Changing who they are, once they're on the phone. */
    public function updatedRelationshipId(Phonebook $phonebook): void
    {
        $entry = $phonebook->entry(auth()->user(), $this->contact);
        if (! $entry) {
            return;
        }

        try {
            $phonebook->setRelationship($entry, Relationship::findOrFail((int) $this->relationshipId));
            $this->dispatch('relationship-saved');
        } catch (DomainException $e) {
            $this->addError('relationshipId', $e->getMessage());
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
        return [
            'check' => $phonebook->check(auth()->user(), $this->contact),
            'relationships' => Relationship::for($this->contact)->get(),
        ];
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

    <div class="flex max-w-sm flex-col gap-3">
        @if ($check === AddCheck::AlreadyAdded)
            <flux:text>{{ __(':name is on your phone.', ['name' => $contact->firstName()]) }}</flux:text>
            <div class="flex gap-2">
                <flux:button variant="primary" icon="chat-bubble-left-right" :href="route('app.chat', $contact)" wire:navigate>{{ __('Text :name', ['name' => $contact->firstName()]) }}</flux:button>
                <flux:button icon="phone" :href="route('app.call', $contact)" wire:navigate>{{ __('Call') }}</flux:button>
                <flux:button icon="light-bulb" :href="route('app.memory', $contact)" wire:navigate>{{ __('What they remember') }}</flux:button>
            </div>
            <flux:select wire:model.live="relationshipId" :label="__(':name is my…', ['name' => $contact->firstName()])">
                @foreach ($relationships as $relationship)
                    <flux:select.option :value="$relationship->id">{{ __($relationship->label) }}</flux:select.option>
                @endforeach
            </flux:select>
            <x-action-message on="relationship-saved">{{ __('Saved.') }}</x-action-message>
            <div>
                <flux:button wire:click="remove" wire:confirm="{{ __('Remove :name from your phone? Everything you shared is kept, and comes back if you add them again.', ['name' => $contact->firstName()]) }}">
                    {{ __('Remove from my phone') }}
                </flux:button>
            </div>
        @elseif ($check === AddCheck::Ok)
            <flux:select wire:model="relationshipId" :label="__(':name is my…', ['name' => $contact->firstName()])">
                @foreach ($relationships as $relationship)
                    <flux:select.option :value="$relationship->id">{{ __($relationship->label) }}</flux:select.option>
                @endforeach
            </flux:select>
            <div><flux:button variant="primary" icon="plus" wire:click="add">{{ __('Add :name to my phone', ['name' => $contact->firstName()]) }}</flux:button></div>
        @else
            <flux:callout icon="information-circle" :heading="__($check->message())" />
            @if (in_array($check, [AddCheck::Locked, AddCheck::LimitReached], true))
                <div><flux:button :href="route('app.plan')" wire:navigate>{{ __('See plans') }}</flux:button></div>
            @endif
        @endif
        <flux:error name="add" />
        <flux:error name="relationshipId" />
    </div>
</div>

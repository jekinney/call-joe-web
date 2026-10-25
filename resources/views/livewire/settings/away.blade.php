<?php

use Carbon\CarbonImmutable;
use Livewire\Volt\Component;

/*
 * Away mode: "I'm on vacation until Sunday". Contacts know, and don't worry or reach out until then
 * (App\Services\Outreach). It ends by itself at the end of the chosen day, or with "I'm back".
 */
new class extends Component {
    /** The last day away (Y-m-d), in the user's timezone. */
    public string $until = '';

    public string $note = '';

    public function mount(): void
    {
        $user = auth()->user();
        if ($user->isAway()) {
            $this->until = $user->away_until->setTimezone($user->timezone)->toDateString();
            $this->note = (string) $user->away_note;
        }
    }

    public function save(): void
    {
        $user = auth()->user();
        $today = CarbonImmutable::now($user->timezone)->toDateString();
        $this->validate([
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:'.CarbonImmutable::parse($today)->addDays(60)->toDateString()],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $user->forceFill([
            'away_until' => CarbonImmutable::parse($this->until, $user->timezone)->endOfDay()->utc(),
            'away_note' => trim($this->note) ?: null,
        ])->save();
        $this->dispatch('away-saved');
    }

    public function back(): void
    {
        auth()->user()->forceFill(['away_until' => null, 'away_note' => null])->save();
        $this->reset('until', 'note');
    }
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Away mode" subheading="Going somewhere? Your contacts won't worry or reach out until you're back.">
        @if (auth()->user()->isAway())
            <flux:callout icon="sun" color="emerald" class="mb-6" data-away
                :heading="__('You\'re away until :day.', ['day' => auth()->user()->away_until->setTimezone(auth()->user()->timezone)->format('l, M j')])">
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="back">{{ __("I'm back") }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="until" type="date" :label="__('Away through')" required />
            <flux:input wire:model="note" :label="__('Where are you going? (optional)')" :placeholder="__('Camping up north')" maxlength="200" />
            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-action-message on="away-saved">{{ __('Saved.') }}</x-action-message>
            </div>
        </form>
    </x-settings.layout>
</div>

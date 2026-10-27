<?php

use Livewire\Volt\Component;

/*
 * Your data: a copy of everything we keep about you (App\Services\DataRights), and deleting the account.
 */
new class extends Component {
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Your data" subheading="Take a copy of what we keep about you, or delete it all.">
        <div class="flex flex-col items-start gap-3">
            <flux:text>{{ __('Your copy has your account details, every contact with your texts, call transcripts, what each contact remembers about you, the pictures they described, safety notices and payments. It downloads as one file.') }}</flux:text>
            <flux:button :href="route('settings.data.export')" icon="arrow-down-tray" data-export>{{ __('Download my data') }}</flux:button>
        </div>

        <livewire:settings.delete-user-form />
    </x-settings.layout>
</div>

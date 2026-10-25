<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * The Terms, Privacy Policy or AI disclosure changed (config/legal.php): accept the new version to keep using the app.
 */
new #[Layout('components.layouts.auth'), Title('Updated terms')] class extends Component {
    public bool $terms = false;

    public function mount(): void
    {
        if (auth()->user()->hasAcceptedTerms()) {
            $this->redirectRoute('app.home', navigate: true);
        }
    }

    public function accept(): void
    {
        $this->validate(['terms' => ['accepted']], ['terms.accepted' => __('Please agree to continue.')]);
        auth()->user()->acceptTerms();
        $this->redirectIntended(route('app.home', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('We updated our terms')" :description="__('Please read and accept them to keep using Call Joe.')" />

    <form wire:submit="accept" class="flex flex-col gap-4">
        <x-legal-links class="text-center text-sm" />
        <flux:checkbox wire:model="terms" :label="__('I agree to the Terms and Privacy Policy, and I understand my contacts are AI, not real people.')" />
        <flux:error name="terms" />
        <flux:button type="submit" variant="primary" class="w-full">{{ __('Continue') }}</flux:button>
    </form>
</div>

<?php

use App\Models\User;
use App\Models\UserContact;
use App\Services\PromptBuilder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.admin'), Title('Prompt preview')] class extends Component {
    #[Url]
    public string $email = '';

    #[Url]
    public string $entryId = '';

    /**
     * @return array<string, mixed>
     */
    public function with(PromptBuilder $builder): array
    {
        $user = $this->email !== '' ? User::where('email', strtolower(trim($this->email)))->first() : null;
        $entries = $user ? $user->userContacts()->with(['contact', 'relationship'])->get() : collect();
        $entry = $entries->firstWhere('id', (int) $this->entryId) ?? $entries->first();

        return [
            'user' => $user,
            'entries' => $entries,
            'snapshot' => $entry instanceof UserContact ? $builder->build($entry) : null,
        ];
    }
}; ?>

<div class="flex w-full max-w-4xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Prompt preview') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The exact prompt a contact gets for one user (memory comes in a later phase).') }}</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:input wire:model.live.debounce.400ms="email" type="email" :label="__('User\'s email')" placeholder="jamie@example.com" />
        @if ($user && $entries->isNotEmpty())
            <flux:select wire:model.live="entryId" :label="__('Contact')">
                @foreach ($entries as $entry)
                    <flux:select.option :value="$entry->id">{{ $entry->contact->name }} ({{ $entry->relationship?->label ?? __('Friend') }})</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($email !== '' && ! $user)
        <flux:text>{{ __('No account with that email.') }}</flux:text>
    @elseif ($user && $entries->isEmpty())
        <flux:text>{{ __(':name has no contacts yet.', ['name' => $user->name]) }}</flux:text>
    @endif

    @if ($snapshot)
        <flux:text size="sm" class="font-mono">sha256 {{ $snapshot->hash }}</flux:text>
        @foreach ($snapshot->sections as $key => $text)
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="section-{{ $key }}">
                <div class="border-b border-zinc-200 px-3 py-1 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700">{{ $key }}</div>
                <pre class="whitespace-pre-wrap p-3 text-sm">{{ $text }}</pre>
            </div>
        @endforeach
    @endif
</div>

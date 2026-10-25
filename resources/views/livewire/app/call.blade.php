<?php

use App\Enums\Metric;
use App\Models\Call;
use App\Models\Contact;
use App\Models\UserContact;
use App\Services\Calling;
use App\Services\Entitlements;
use App\Services\Phonebook;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Call')] class extends Component {
    public UserContact $entry;

    /** idle | connecting | active | ended */
    public string $state = 'idle';

    public ?int $callId = null;

    public string $problem = '';

    public function mount(Contact $contact, Phonebook $phonebook): void
    {
        $this->entry = $phonebook->entry(auth()->user(), $contact) ?? abort(404);
    }

    public function start(Calling $calling): void
    {
        $this->problem = '';

        try {
            $call = $calling->start(auth()->user(), $this->entry);
        } catch (DomainException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->callId = $call->id;
        $this->state = 'connecting';
        // resources/js/voice.js starts the call in the browser.
        $this->dispatch('vapi-start', publicKey: $calling->publicKey(), assistantId: $call->vapi_assistant_id, callId: $call->id);
    }

    #[On('vapi-connected')]
    public function connected(Calling $calling, ?string $vapiCallId = null): void
    {
        if ($call = $this->call()) {
            $calling->connected($call, $vapiCallId);
            $this->state = 'active';
        }
    }

    #[On('vapi-ended')]
    public function ended(Calling $calling): void
    {
        if ($call = $this->call()) {
            $calling->hungUp($call);
        }
        $this->state = 'ended';
    }

    #[On('vapi-error')]
    public function failed(Calling $calling, string $message = ''): void
    {
        if ($call = $this->call()) {
            $calling->hungUp($call);
        }
        $this->state = 'idle';
        $this->problem = __('The call dropped. Check your microphone and connection, then try again.');
    }

    public function hangUp(): void
    {
        $this->dispatch('vapi-stop');
    }

    private function call(): ?Call
    {
        return $this->callId ? $this->entry->calls()->find($this->callId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Entitlements $entitlements): array
    {
        $left = $entitlements->remaining(auth()->user(), Metric::VoiceSeconds);

        return [
            'contact' => $this->entry->contact,
            'minutesLeft' => $left === null ? null : intdiv($left, 60),
            'recent' => $this->entry->calls()->whereNotNull('duration_seconds')->latest('id')->limit(5)->get(),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-md flex-col items-center gap-6 py-6 text-center" x-data="{ talking: false, muted: false }"
    x-on:vapi-speech.window="talking = $event.detail.talking" x-on:vapi-muted.window="muted = $event.detail.muted">
    <a href="{{ route('app.chat', $contact) }}" class="self-start text-sm" wire:navigate>← {{ __('Texts') }}</a>

    <div class="relative">
        <flux:avatar class="size-32!" :src="$contact->avatarUrl()" :name="$contact->name" />
        <span class="absolute inset-0 rounded-full ring-4 ring-emerald-500 transition-opacity" x-bind:class="talking ? 'opacity-100 animate-pulse' : 'opacity-0'"></span>
    </div>

    <div>
        <flux:heading size="xl">{{ $entry->displayName() }}</flux:heading>
        <flux:text class="mt-1" data-state="{{ $state }}">
            @switch($state)
                @case('connecting') {{ __('Calling…') }} @break
                @case('active') {{ __('On the call') }} @break
                @case('ended') {{ __('Call ended') }} @break
                @default {{ __(':name', ['name' => __($entry->relationship?->label ?? 'Friend')]) }}
            @endswitch
        </flux:text>
    </div>

    @if ($problem)
        <flux:callout icon="exclamation-triangle" color="amber" :heading="$problem" />
    @endif

    <div class="flex gap-4">
        @if (in_array($state, ['idle', 'ended'], true))
            <flux:button variant="primary" icon="phone" wire:click="start" class="rounded-full! px-6">{{ $state === 'ended' ? __('Call again') : __('Call') }}</flux:button>
        @else
            <flux:button icon="microphone" x-on:click="window.dispatchEvent(new CustomEvent('vapi-toggle-mute'))" x-text="muted ? '{{ __('Unmute') }}' : '{{ __('Mute') }}'">{{ __('Mute') }}</flux:button>
            <flux:button variant="danger" icon="phone-x-mark" wire:click="hangUp" class="rounded-full! px-6">{{ __('Hang up') }}</flux:button>
        @endif
    </div>

    @if ($minutesLeft !== null)
        <flux:text size="sm">{{ trans_choice(':count minute of calls left this month|:count minutes of calls left this month', $minutesLeft, ['count' => $minutesLeft]) }}</flux:text>
    @endif

    @if ($recent->isNotEmpty())
        <div class="w-full text-left">
            <flux:heading size="sm" class="mb-2">{{ __('Recent calls') }}</flux:heading>
            @foreach ($recent as $past)
                <flux:text size="sm">{{ $past->created_at->diffForHumans() }} · {{ $past->lengthLabel() }}</flux:text>
            @endforeach
        </div>
    @endif
</div>

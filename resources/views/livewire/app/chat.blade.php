<?php

use App\Enums\Metric;
use App\Models\Contact;
use App\Models\Message;
use App\Models\UserContact;
use App\Services\Entitlements;
use App\Services\Phonebook;
use App\Services\Texting;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Texts')] class extends Component {
    public UserContact $entry;

    public string $body = '';

    /** The contact is writing a reply. */
    public bool $typing = false;

    public function mount(Contact $contact, Phonebook $phonebook): void
    {
        $this->entry = $phonebook->entry(auth()->user(), $contact) ?? abort(404);
        $this->markRead();
    }

    /**
     * Live updates on the user's private channel (Reverb, through Echo).
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $channel = 'echo-private:App.Models.User.'.auth()->id();

        return [
            "{$channel},.message" => 'onMessage',
            "{$channel},.typing" => 'onTyping',
            "{$channel},.reply-failed" => 'onReplyFailed',
        ];
    }

    /** @param array{user_contact_id: int} $event */
    public function onMessage(array $event): void
    {
        if ($event['user_contact_id'] === $this->entry->id) {
            $this->typing = false;
            $this->markRead();
        }
    }

    /** @param array{user_contact_id: int} $event */
    public function onTyping(array $event): void
    {
        $this->typing = $event['user_contact_id'] === $this->entry->id;
    }

    /** @param array{user_contact_id: int} $event */
    public function onReplyFailed(array $event): void
    {
        if ($event['user_contact_id'] === $this->entry->id) {
            $this->typing = false;
        }
    }

    public function send(Texting $texting): void
    {
        try {
            $texting->send(auth()->user(), $this->entry, $this->body);
            $this->body = '';
            $this->typing = true;
            $this->dispatch('text-sent');
        } catch (DomainException $e) {
            $this->addError('body', $e->getMessage());
        }
    }

    public function retry(int $messageId, Texting $texting): void
    {
        $message = $this->entry->messages()->where('sender', Message::FROM_USER)->findOrFail($messageId);
        $texting->retry($message);
        $this->typing = true;
    }

    private function markRead(): void
    {
        $this->entry->messages()->where('sender', Message::FROM_CONTACT)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Entitlements $entitlements): array
    {
        $user = auth()->user();

        return [
            'contact' => $this->entry->contact,
            'messages' => $this->entry->messages()->orderByDesc('id')->limit(50)->get()->reverse(),
            'left' => $entitlements->remaining($user, Metric::TextMessages),
        ];
    }
}; ?>

<div class="mx-auto flex h-[calc(100dvh-4rem)] w-full max-w-2xl flex-col">
    <div class="flex items-center gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700">
        <a href="{{ route('app.contacts') }}" class="text-sm" wire:navigate aria-label="{{ __('Back') }}">←</a>
        <flux:avatar :src="$contact->avatarUrl()" :name="$contact->name" />
        <div>
            <flux:heading>{{ $entry->displayName() }}</flux:heading>
            <flux:text size="sm">{{ __($entry->relationship?->label ?? 'Friend') }}</flux:text>
        </div>
        <flux:button size="sm" variant="ghost" icon="light-bulb" class="ml-auto" :href="route('app.memory', $contact)" wire:navigate>{{ __('What they remember') }}</flux:button>
    </div>

    <div class="flex flex-1 flex-col gap-2 overflow-y-auto py-4" id="thread"
        x-data x-init="$el.scrollTop = $el.scrollHeight" x-on:text-sent.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
        wire:poll.visible.60s>
        @forelse ($messages as $message)
            <div @class(['flex flex-col', 'items-end' => $message->fromUser(), 'items-start' => ! $message->fromUser()]) wire:key="m-{{ $message->id }}" data-sender="{{ $message->sender }}">
                <div @class([
                    'max-w-[80%] whitespace-pre-line rounded-2xl px-3 py-2 text-sm',
                    'bg-emerald-600 text-white' => $message->fromUser(),
                    'bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100' => ! $message->fromUser(),
                ])>{{ $message->body }}</div>
                @if ($message->replyFailed())
                    <button type="button" class="mt-1 text-xs text-red-600 underline" wire:click="retry({{ $message->id }})">
                        {{ __(':name couldn\'t answer. Try again', ['name' => $entry->displayName()]) }}
                    </button>
                @endif
            </div>
        @empty
            <flux:text class="text-center">{{ __('Say hi to :name.', ['name' => $entry->displayName()]) }}</flux:text>
        @endforelse

        @if ($typing)
            <div class="items-start" data-typing>
                <div class="inline-block rounded-2xl bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-700">…</div>
            </div>
        @endif
    </div>

    <form wire:submit="send" class="flex flex-col gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
        <div class="flex gap-1">
            @foreach (['😂', '❤️', '👍', '😊', '😢', '🙏', '🎉', '😮'] as $emoji)
                <button type="button" class="rounded px-1 text-lg hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="$wire.set('body', $wire.body + '{{ $emoji }}', false)">{{ $emoji }}</button>
            @endforeach
        </div>
        <div class="flex gap-2">
            <flux:input wire:model="body" :placeholder="__('Text :name', ['name' => $entry->displayName()])" class="flex-1" autocomplete="off" maxlength="2000" />
            <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
        </div>
        <flux:error name="body" />
        @if ($left !== null)
            <flux:text size="sm">{{ trans_choice(':count text left today|:count texts left today', $left, ['count' => $left]) }}</flux:text>
        @endif
    </form>
</div>

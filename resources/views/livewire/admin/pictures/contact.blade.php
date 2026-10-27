<?php

use App\Models\Contact;
use App\Models\ImageCacheEntry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * One contact's picture library: every picture in their shared cache (App\Services\PictureCache), newest first.
 * Pictures made for a single user are private to that user and never show here.
 */
new #[Layout('components.layouts.admin'), Title('Contact pictures')] class extends Component {
    use WithPagination;

    public Contact $contact;

    public function mount(int $contactId): void
    {
        $this->contact = Contact::withTrashed()->findOrFail($contactId);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $entries = ImageCacheEntry::with('image')->where('contact_id', $this->contact->id);

        return [
            'total' => (clone $entries)->count(),
            'reuses' => (int) (clone $entries)->sum('hits'),
            'entries' => $entries->latest('id')->paginate(24),
        ];
    }
}; ?>

<div class="flex w-full max-w-6xl flex-col gap-6">
    <div>
        <a href="{{ route('admin.pictures') }}" class="text-sm underline-offset-2 hover:underline" wire:navigate>← {{ __('Pictures') }}</a>
        <flux:heading size="xl" class="mt-2">{{ $contact->name }}</flux:heading>
        <flux:text>{{ trans_choice(':count picture|:count pictures', $total, ['count' => $total]) }} · {{ __(':count reuses', ['count' => $reuses]) }}</flux:text>
    </div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4" data-gallery>
        @forelse ($entries as $entry)
            <figure class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" wire:key="p-{{ $entry->id }}">
                <a href="{{ route('admin.pictures.file', $entry->image_id) }}" target="_blank" rel="noopener">
                    <img src="{{ route('admin.pictures.file', $entry->image_id) }}" alt="{{ $entry->scene_signature }}" loading="lazy" class="aspect-square w-full rounded object-cover" />
                </a>
                <figcaption class="flex flex-col gap-1">
                    <flux:text size="xs" class="break-words font-mono">{{ $entry->scene_signature }}</flux:text>
                    <flux:text size="xs">{{ trans_choice(':count reuse|:count reuses', $entry->hits, ['count' => $entry->hits]) }} · {{ $entry->created_at->toFormattedDateString() }}</flux:text>
                </figcaption>
            </figure>
        @empty
            <flux:text class="col-span-full">{{ __('No cached pictures yet.') }}</flux:text>
        @endforelse
    </div>

    {{ $entries->links() }}
</div>

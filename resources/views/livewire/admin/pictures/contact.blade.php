<?php

use App\Models\Contact;
use App\Models\ImageCacheEntry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * One contact's picture library: every picture in their shared cache (App\Services\PictureCache), newest first, as
 * thumbnails that open in a viewer you can page through. Pictures made for a single user are private to that user and
 * never show here.
 */
new #[Layout('components.layouts.admin'), Title('Contact pictures')] class extends Component {
    /** The most pictures shown (a contact keeps a few variants per scene, so this is plenty). */
    private const LIMIT = 500;

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
        $entries = ImageCacheEntry::where('contact_id', $this->contact->id);

        return [
            'total' => (clone $entries)->count(),
            'reuses' => (int) (clone $entries)->sum('hits'),
            'entries' => $entries->latest('id')->limit(self::LIMIT)->get(),
        ];
    }
}; ?>

<div class="flex w-full max-w-6xl flex-col gap-6"
    x-data="{
        open: false,
        index: 0,
        items: @js($entries->map(fn ($entry) => [
            'url' => route('admin.pictures.file', $entry->image_id),
            'labels' => $entry->sceneLabels(),
            'reuses' => $entry->hits,
            'date' => $entry->created_at->toFormattedDateString(),
        ])->values()),
        show(i) { this.index = i; this.open = true },
        next() { this.index = (this.index + 1) % this.items.length },
        prev() { this.index = (this.index - 1 + this.items.length) % this.items.length },
    }"
    x-on:keydown.escape.window="open = false"
    x-on:keydown.arrow-right.window="open && next()"
    x-on:keydown.arrow-left.window="open && prev()">
    <div>
        <a href="{{ route('admin.pictures') }}" class="text-sm underline-offset-2 hover:underline" wire:navigate>← {{ __('Pictures') }}</a>
        <flux:heading size="xl" class="mt-2">{{ $contact->name }}</flux:heading>
        <flux:text>{{ trans_choice(':count picture|:count pictures', $total, ['count' => $total]) }} · {{ __(':count reuses', ['count' => $reuses]) }}</flux:text>
    </div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5" data-gallery>
        @forelse ($entries as $entry)
            <figure class="flex flex-col gap-2" wire:key="p-{{ $entry->id }}">
                <button type="button" x-on:click="show({{ $loop->index }})" class="overflow-hidden rounded-lg border border-zinc-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-zinc-700" aria-label="{{ __('View picture :n', ['n' => $loop->iteration]) }}">
                    <img src="{{ route('admin.pictures.file', $entry->image_id) }}" alt="{{ implode(', ', $entry->sceneLabels()) }}" loading="lazy" class="aspect-square w-full object-cover transition hover:scale-105" />
                </button>
                <figcaption>
                    <flux:text size="xs" class="truncate">{{ implode(' · ', $entry->sceneLabels()) }}</flux:text>
                </figcaption>
            </figure>
        @empty
            <flux:text class="col-span-full">{{ __('No cached pictures yet.') }}</flux:text>
        @endforelse
    </div>

    {{-- The viewer: click a thumbnail, then use the arrows (or the keyboard) to go through the library. --}}
    <div x-show="open" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-label="{{ __('Picture viewer') }}"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4" data-viewer
        x-on:click.self="open = false">
        <button type="button" x-on:click="open = false" class="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20" aria-label="{{ __('Close') }}">
            <flux:icon.x-mark class="size-6" />
        </button>

        <button type="button" x-show="items.length > 1" x-on:click="prev()" class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20 sm:left-6" aria-label="{{ __('Previous picture') }}">
            <flux:icon.chevron-left class="size-7" />
        </button>
        <button type="button" x-show="items.length > 1" x-on:click="next()" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20 sm:right-6" aria-label="{{ __('Next picture') }}">
            <flux:icon.chevron-right class="size-7" />
        </button>

        <div class="flex max-h-full max-w-3xl flex-col items-center gap-3">
            <img x-bind:src="open ? items[index].url : ''" alt="" class="max-h-[75vh] max-w-full rounded-lg object-contain" />
            <div class="flex flex-col items-center gap-2 text-center text-white">
                <div class="flex flex-wrap justify-center gap-2">
                    <template x-for="(value, label) in items[index]?.labels" x-bind:key="label">
                        <span class="rounded-full bg-white/15 px-3 py-1 text-sm"><span class="opacity-70" x-text="label + ':'"></span> <span x-text="value"></span></span>
                    </template>
                </div>
                <div class="text-sm opacity-70">
                    <span x-text="(index + 1) + ' / ' + items.length"></span> ·
                    <span x-text="items[index]?.reuses + ' {{ __('reuses') }}'"></span> ·
                    <span x-text="items[index]?.date"></span>
                </div>
            </div>
        </div>
    </div>
</div>

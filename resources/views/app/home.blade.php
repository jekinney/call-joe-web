<x-layouts.app :title="__('Home')">
    @php($entries = auth()->user()->userContacts()->with('contact')->get())

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <flux:heading size="xl">{{ __('Hi, :name', ['name' => auth()->user()->preferredName()]) }}</flux:heading>

        @if ($entries->isEmpty())
            <flux:text>{{ __('Your contacts will live here. Browse the catalog to add your first friend.') }}</flux:text>
            <div><flux:button variant="primary" icon="sparkles" :href="route('app.catalog')" wire:navigate>{{ __('Browse the catalog') }}</flux:button></div>
        @else
            <div class="grid grid-cols-3 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ($entries as $entry)
                    <a href="{{ route('app.catalog.show', $entry->contact) }}" class="flex flex-col items-center gap-2 text-center" wire:navigate>
                        <flux:avatar size="xl" :src="$entry->contact->avatarUrl()" :name="$entry->contact->name" />
                        <span class="text-sm">{{ $entry->displayName() }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>

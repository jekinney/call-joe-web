<x-layouts.app :title="__('Home')">
    @php($entries = auth()->user()->userContacts()->with('contact')->withUnreadCount()->get())

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <flux:heading size="xl">{{ __('Hi, :name', ['name' => auth()->user()->preferredName()]) }}</flux:heading>

        @if ($entries->isEmpty())
            <flux:text>{{ __('Your contacts will live here. Browse the catalog to add your first friend.') }}</flux:text>
            <div><flux:button variant="primary" icon="sparkles" :href="route('app.catalog')" wire:navigate>{{ __('Browse the catalog') }}</flux:button></div>
        @else
            <div class="grid grid-cols-3 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ($entries as $entry)
                    <a href="{{ route('app.chat', $entry->contact) }}" class="relative flex flex-col items-center gap-2 text-center" wire:navigate>
                        <flux:avatar size="xl" :src="$entry->contact->avatarUrl()" :name="$entry->contact->name" />
                        @if ($entry->unread_count)
                            <span class="absolute right-2 top-0 rounded-full bg-emerald-600 px-1.5 text-xs text-white" data-unread>{{ $entry->unread_count }}</span>
                        @endif
                        <span class="text-sm">{{ $entry->displayName() }}</span>
                    </a>
                @endforeach
            </div>

            @php($waiting = app(\App\Services\Catchup::class)->summary(auth()->user()))
            @if ($waiting->isNotEmpty())
                <section class="flex flex-col gap-2" data-catchup>
                    <flux:heading size="lg">{{ __('While you were away') }}</flux:heading>
                    @foreach ($waiting as $row)
                        <a href="{{ route('app.chat', $row['entry']->contact) }}" class="flex items-center gap-3 rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-700" wire:navigate>
                            <flux:avatar size="sm" :src="$row['entry']->contact->avatarUrl()" :name="$row['entry']->contact->name" />
                            <div class="min-w-0 flex-1">
                                <div class="flex justify-between gap-2">
                                    <span class="font-medium">{{ $row['entry']->displayName() }}</span>
                                    <span class="text-xs text-zinc-500">{{ $row['latest']->created_at->diffForHumans() }}</span>
                                </div>
                                <div class="truncate text-sm text-zinc-500">{{ $row['latest']->body }}</div>
                            </div>
                            <flux:badge color="emerald" size="sm">{{ $row['unread'] }}</flux:badge>
                        </a>
                    @endforeach
                </section>
            @endif
        @endif
    </div>
</x-layouts.app>

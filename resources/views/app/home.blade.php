<x-layouts.app :title="__('Home')">
    <div class="flex h-full w-full flex-1 flex-col gap-4">
        <flux:heading size="xl">{{ __('Hi, :name', ['name' => auth()->user()->preferredName()]) }}</flux:heading>
        <flux:text>{{ __('Your contacts will live here. Browse the catalog to add your first friend.') }}</flux:text>
    </div>
</x-layouts.app>

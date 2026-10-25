<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        {{-- The admin area: its own look (amber accent) so it's never mistaken for the user app. --}}
        <flux:sidebar sticky stashable class="border-r border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('admin.home') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
                <flux:badge color="amber" size="sm">{{ __('Admin') }}</flux:badge>
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group :heading="__('Admin')" class="grid">
                    <flux:navlist.item icon="home" :href="route('admin.home')" :current="request()->routeIs('admin.home')" wire:navigate>{{ __('Overview') }}</flux:navlist.item>
                    <flux:navlist.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                    <flux:navlist.item icon="user-group" :href="route('admin.contacts.index')" :current="request()->routeIs('admin.contacts.*')" wire:navigate>{{ __('Contacts') }}</flux:navlist.item>
                    <flux:navlist.item icon="shield-check" :href="route('admin.moderation')" :current="request()->routeIs('admin.moderation')" :badge="\App\Models\ModerationEvent::whereNull('reviewed_at')->count() ?: null" wire:navigate>{{ __('Safety') }}</flux:navlist.item>
                    <flux:navlist.item icon="cpu-chip" :href="route('admin.models')" :current="request()->routeIs('admin.models')" wire:navigate>{{ __('AI models') }}</flux:navlist.item>
                    <flux:navlist.item icon="document-text" :href="route('admin.prompt-preview')" :current="request()->routeIs('admin.prompt-preview')" wire:navigate>{{ __('Prompt preview') }}</flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <flux:navlist variant="outline">
                <flux:navlist.item icon="arrow-left" :href="route('app.home')" wire:navigate>{{ __('Back to the app') }}</flux:navlist.item>
            </flux:navlist>

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:button type="submit" variant="ghost" icon="arrow-right-start-on-rectangle" class="w-full justify-start">
                    {{ __('Log Out') }}
                </flux:button>
            </form>
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        </flux:header>

        <flux:main>
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>

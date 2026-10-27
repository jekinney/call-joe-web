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
                <flux:navlist.group :heading="__('Admin')" class="grid [&_a]:py-2.5 [&_a]:text-base">
                    @can('overview.view')
                        <flux:navlist.item icon="home" :href="route('admin.home')" :current="request()->routeIs('admin.home')" wire:navigate>{{ __('Overview') }}</flux:navlist.item>
                    @endcan
                    @can('users.view')
                        <flux:navlist.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                    @endcan
                    @can('contacts.manage')
                        <flux:navlist.item icon="user-group" :href="route('admin.contacts.index')" :current="request()->routeIs('admin.contacts.*')" wire:navigate>{{ __('Contacts') }}</flux:navlist.item>
                    @endcan
                    @can('safety.review')
                        <flux:navlist.item icon="shield-check" :href="route('admin.moderation')" :current="request()->routeIs('admin.moderation')" :badge="\App\Models\ModerationEvent::whereNull('reviewed_at')->count() ?: null" wire:navigate>{{ __('Safety') }}</flux:navlist.item>
                    @endcan
                    @can('pictures.manage')
                        <flux:navlist.item icon="photo" :href="route('admin.pictures')" :current="request()->routeIs('admin.pictures')" wire:navigate>{{ __('Pictures') }}</flux:navlist.item>
                    @endcan
                    @can('models.manage')
                        <flux:navlist.item icon="cpu-chip" :href="route('admin.models')" :current="request()->routeIs('admin.models')" wire:navigate>{{ __('AI models') }}</flux:navlist.item>
                    @endcan
                    @can('prompts.view')
                        <flux:navlist.item icon="document-text" :href="route('admin.prompt-preview')" :current="request()->routeIs('admin.prompt-preview')" wire:navigate>{{ __('Prompt preview') }}</flux:navlist.item>
                    @endcan
                    @can('roles.manage')
                        <flux:navlist.item icon="key" :href="route('admin.roles')" :current="request()->routeIs('admin.roles')" wire:navigate>{{ __('Roles') }}</flux:navlist.item>
                    @endcan
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <flux:navlist variant="outline" class="[&_a]:py-2.5 [&_a]:text-base">
                <flux:navlist.item icon="arrow-left" :href="route('app.home')" wire:navigate>{{ __('Back to the app') }}</flux:navlist.item>
            </flux:navlist>

            <flux:text size="sm" class="px-2" data-version>{{ __('Version :v', ['v' => config('app.version')]) }}</flux:text>

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

        {{-- Flux's small and extra-small text is too small for a working screen: everything in the admin area goes up one step. --}}
        <style>
            .admin-area .text-sm, .admin-area [data-flux-text] { font-size: 1rem; line-height: 1.5rem; }
            .admin-area .text-xs, .admin-area [data-flux-text].text-xs { font-size: 0.875rem; line-height: 1.25rem; }
        </style>

        <flux:main class="admin-area">
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>

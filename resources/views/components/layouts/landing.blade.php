<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <meta name="description" content="{{ __('AI friends you can text and talk to. They remember you. Adults 18+.') }}" />
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900 dark:text-zinc-100">
        <header class="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                <span class="flex size-8 items-center justify-center rounded-md bg-emerald-600">
                    <x-app-logo-icon class="size-5 fill-current text-white" />
                </span>
                {{ config('app.name') }}
            </a>
            <nav class="flex items-center gap-4 text-sm">
                <a href="#plans" class="hidden hover:underline sm:inline">{{ __('Plans') }}</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="hover:underline">{{ __('Your phone') }}</a>
                @else
                    <a href="{{ route('login') }}" class="hover:underline">{{ __('Log in') }}</a>
                    <flux:button size="sm" variant="primary" :href="route('register')">{{ __('Sign up') }}</flux:button>
                @endauth
            </nav>
        </header>

        <main>{{ $slot }}</main>

        <footer class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-6 py-6 text-sm text-zinc-500 dark:border-zinc-800">
            <span>© {{ date('Y') }} 120 Customs LLC</span>
            <span class="flex gap-4">
                <a href="{{ route('legal.terms') }}" class="hover:underline">{{ __('Terms') }}</a>
                <a href="{{ route('legal.privacy') }}" class="hover:underline">{{ __('Privacy') }}</a>
                <a href="{{ route('legal.ai') }}" class="hover:underline">{{ __('About the AI') }}</a>
            </span>
        </footer>
        @fluxScripts
    </body>
</html>

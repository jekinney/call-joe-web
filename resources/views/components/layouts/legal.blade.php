@props(['title'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-screen bg-white antialiased dark:bg-zinc-900">
    <main class="mx-auto max-w-2xl px-4 py-10 text-zinc-800 dark:text-zinc-200">
        <a href="{{ route('home') }}" class="text-sm text-zinc-500 underline">{{ config('app.name') }}</a>
        <h1 class="mt-4 text-3xl font-semibold">{{ $title }}</h1>
        <p class="mt-1 text-sm text-zinc-500">{{ __('Version :version', ['version' => config('legal.terms_version')]) }}</p>

        <div class="mt-8 space-y-4 leading-relaxed [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-6 [&_a]:underline">
            {{ $slot }}
        </div>

        <x-legal-links class="mt-12 border-t border-zinc-200 pt-4 dark:border-zinc-700" />
    </main>
</body>
</html>

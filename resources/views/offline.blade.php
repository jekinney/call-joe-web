<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#059669" />
    <title>{{ __('Offline') }} · {{ config('app.name') }}</title>
    {{-- Self-contained (no build files), so it works with no connection at all. --}}
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #18181b; color: #f4f4f5; font-family: system-ui, sans-serif; text-align: center; padding: 24px; }
        img { width: 88px; height: 88px; border-radius: 20px; }
        h1 { font-size: 1.4rem; margin: 20px 0 8px; }
        p { color: #a1a1aa; max-width: 22rem; line-height: 1.5; margin: 0 auto 20px; }
        button { background: #059669; color: white; border: 0; border-radius: 10px; padding: 10px 18px; font-size: 1rem; }
    </style>
</head>
<body>
    <main>
        <img src="/icons/icon-192.png" alt="" />
        <h1>{{ __('You\'re offline') }}</h1>
        <p>{{ __('Your contacts will keep their texts for you. Everything shows up as soon as you\'re back online.') }}</p>
        <button type="button" onclick="location.reload()">{{ __('Try again') }}</button>
    </main>
</body>
</html>

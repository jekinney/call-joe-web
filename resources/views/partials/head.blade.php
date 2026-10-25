<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
@auth
    {{-- For resources/js/presence.js: the user's private channel and their check-in URL. --}}
    <meta name="user-id" content="{{ auth()->id() }}" />
    @if (auth()->user()->hasVerifiedEmail() && auth()->user()->isAdult())
        <meta name="presence-url" content="{{ route('app.presence') }}" />
    @endif
@endauth

<title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

{{-- Installable app (public/manifest.webmanifest, public/sw.js). --}}
<link rel="manifest" href="/manifest.webmanifest" />
<meta name="theme-color" content="#059669" />
<link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png" />
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}" />

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

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

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

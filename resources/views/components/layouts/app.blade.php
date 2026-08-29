<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('keneya.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @livewireStyles
</head>
<body class="app-body">
    {{-- Barre de marque, identique sur les trois interfaces. Volontairement
         depourvue de tout lien de navigation vers un autre espace : un role,
         une interface. --}}
    <header class="app-header">
        <div class="app-header__brand">
            {{-- Variante claire : la barre est bleu nuit. Le nom du produit
                 n'est pas repris a cote, le logo le porte deja. --}}
            <x-brand-logo variant="light" class="app-header__logo" />
            <span class="app-header__hospital">{{ $hospitalName ?? hospital_name() }}</span>
        </div>

        <div class="app-header__context">
            @auth
                {{-- Le rôle, ou le service pour un medecin : les pages
                     surchargent `context` quand il y a plus precis a dire. --}}
                <span class="app-header__role">
                    {{ $context ?? auth()->user()->roleLabel() }}
                </span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="icon-btn" data-testid="logout"
                            aria-label="Se deconnecter" title="Se deconnecter">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M15 17l5-5-5-5" />
                            <path d="M20 12H9" />
                            <path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5" />
                        </svg>
                    </button>
                </form>
            @endauth
        </div>
    </header>

    @if (session('error'))
        <div class="alert alert--error" role="alert">{{ session('error') }}</div>
    @endif

    <main class="app-main">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>

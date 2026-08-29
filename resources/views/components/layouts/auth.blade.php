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
    {{-- La scene est dessinee ; le visuel institutionnel ne sert plus que de
         texture au fond, tres en retrait. Il reste remplacable sans toucher au
         code : il suffit de deposer une autre image sous
         public/images/login-background.jpg. --}}
    <style>
        .login-scene {
            background-image:
                linear-gradient(160deg, rgba(10, 63, 97, .94) 0%, rgba(7, 42, 65, .97) 100%),
                url('{{ asset('images/login-background.jpg') }}');
        }
    </style>
    @livewireStyles
</head>
<body class="login-page">
    <x-login-scene />

    <div class="login-panel">
        {{ $slot }}
    </div>
    @livewireScripts
</body>
</html>

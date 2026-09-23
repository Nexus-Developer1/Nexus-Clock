@props(['titulo' => null])

{{-- Layout das páginas abertas por link (relatórios partilhados): sem menu nem sessão obrigatória. --}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0A2A18">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('img/icon-192.png') }}">
    <title>{{ $titulo ? $titulo.' — Nexus Suporte' : 'Nexus Suporte' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    @livewireStyles
</head>
<body class="min-h-screen">
    <header class="bg-sidebar-grad flex items-center justify-between px-4 py-4 sm:px-10 print:hidden">
        <div>
            <img src="{{ asset('img/nexus-1.png') }}" alt="Nexus" class="h-7">
            <div class="mt-1 text-[10px] font-medium uppercase tracking-[0.2em] text-white/60">Suporte</div>
        </div>
    </header>

    {{ $slot }}

    @livewireScripts
</body>
</html>

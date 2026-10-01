{{-- Página pequena que vai dentro da janela por cima de tudo (notas §69): só o cronómetro. --}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cronómetro — Nexus Suporte</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    @livewireStyles
</head>
<body class="bg-white">
    <livewire:tempos.mini-cronometro :janela="true" />

    @include('partials.cronometro-js')
    @livewireScripts
</body>
</html>

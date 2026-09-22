@props(['rotulo', 'valor', 'detalhe' => null, 'tom' => 'normal', 'icone' => null])

@php
    // Cartão de indicador (como na página inicial). tom: normal | verde (cartão cheio da marca) |
    // escuro (verde-escuro da sidebar) | perigo (valor a vermelho) | aviso (valor a âmbar).
    [$fundo, $cRotulo, $cValor, $cDetalhe, $cIcone] = match ($tom) {
        'verde' => ['bg-verde-600 border-verde-600', 'text-verde-100', 'text-white', 'text-verde-100', 'bg-white/15 text-white'],
        'escuro' => ['bg-sidebar-grad border-verde-900', 'text-white/60', 'text-white', 'text-white/60', 'bg-white/10 text-verde-300'],
        'perigo' => ['bg-superficie border-borda', 'text-texto-medio', 'text-perigo-600', 'text-texto-fraco', 'bg-perigo-100 text-perigo-600'],
        'aviso' => ['bg-superficie border-borda', 'text-texto-medio', 'text-aviso-500', 'text-texto-fraco', 'bg-aviso-100 text-aviso-500'],
        default => ['bg-superficie border-borda', 'text-texto-medio', 'text-texto-forte', 'text-texto-fraco', 'bg-verde-50 text-verde-700'],
    };
@endphp

<div {{ $attributes->merge(['class' => "rounded-2xl border p-5 shadow-cartao $fundo"]) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="text-xs font-medium {{ $cRotulo }}">{{ $rotulo }}</div>
        @if ($icone)
            <span class="flex h-8 w-8 items-center justify-center rounded-xl {{ $cIcone }}"><x-icone :nome="$icone" /></span>
        @endif
    </div>
    <div class="mt-2 text-3xl font-medium tracking-tight tabular-nums {{ $cValor }}">{{ $valor }}</div>
    @if ($detalhe !== null || $slot->isNotEmpty())
        <div class="mt-1 text-xs {{ $cDetalhe }}">{{ $detalhe }}{{ $slot }}</div>
    @endif
</div>

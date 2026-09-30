{{-- Cabeçalho e separadores da página Equipa, com os botões da página ($acoes) à direita. --}}
@props(['atual'])

@php
    $separadores = [
        'equipa' => ['Membros', 'pessoas'],
        'equipa.limitados' => ['Limitados', 'pessoa'],
        'equipa.grupos' => ['Grupos', 'camadas'],
        'equipa.lembretes' => ['Lembretes', 'relogio'],
    ];
@endphp

<x-cabecalho-pagina titulo="Equipa" />

<div class="mt-6 flex flex-wrap items-center justify-between gap-3">
    <nav class="max-w-full overflow-x-auto" aria-label="Equipa">
        <div class="inline-flex gap-1 rounded-2xl border border-borda bg-white p-1 shadow-cartao">
            @foreach ($separadores as $rota => [$rotulo, $icone])
                @php $ativo = $atual === $rota; @endphp
                <a href="{{ route($rota) }}" wire:navigate @if ($ativo) aria-current="page" @endif
                   class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-medium transition {{ $ativo ? 'bg-verde-900 text-white' : 'text-texto-medio hover:bg-fundo hover:text-texto-forte' }}">
                    <x-icone :nome="$icone" class="h-4 w-4 {{ $ativo ? 'text-verde-300' : '' }}" />
                    {{ $rotulo }}
                </a>
            @endforeach
        </div>
    </nav>

    @isset($acoes)
        <div class="flex flex-wrap items-center gap-2">{{ $acoes }}</div>
    @endisset
</div>

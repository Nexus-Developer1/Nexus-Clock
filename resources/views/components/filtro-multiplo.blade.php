{{-- Filtro de escolha múltipla (menu com pesquisa e caixas). $modelo = propriedade Livewire (lista).
     Dois aspetos: botão compacto com o rótulo lá dentro (barras de filtros), ou, com :campo="true",
     campo à largura da coluna com o rótulo por cima — como os selects do cartão de filtros da Nexus
     Infra ("Todos", o nome escolhido ou "N selecionados"). --}}
@props(['rotulo', 'modelo', 'opcoes' => [], 'selecionados' => [], 'campo' => false])

@php
    $n = count($selecionados);
    $id = 'filtro-'.$modelo;
    $resumo = match (true) {
        $n === 0 => 'Todos',
        $n === 1 => $opcoes[$selecionados[0]] ?? $opcoes[(int) $selecionados[0]] ?? '1 selecionado',
        default => $n.' selecionados',
    };
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }} x-data="{ aberto: false, termo: '' }" @click.outside="aberto = false" @keydown.escape="aberto = false">
    @if ($campo)
        <label for="{{ $id }}" class="campo-label">{{ $rotulo }}</label>
        <button type="button" id="{{ $id }}" @click="aberto = ! aberto; $nextTick(() => aberto && $refs.termo?.focus())" :aria-expanded="aberto"
            class="campo-select block truncate text-left {{ $n ? '!border-verde-300 !bg-verde-50 text-verde-800' : '' }}">{{ $resumo }}</button>
    @else
        <button type="button" @click="aberto = ! aberto; $nextTick(() => aberto && $refs.termo?.focus())" :aria-expanded="aberto"
            class="inline-flex h-10 items-center gap-2 rounded-lg border px-3 text-sm transition {{ $n ? 'border-verde-300 bg-verde-50 text-verde-800' : 'border-borda bg-white text-texto-forte hover:bg-fundo' }}">
            {{ $rotulo }}
            @if ($n)<span class="rounded-full bg-verde-600 px-1.5 text-[11px] font-medium leading-4 text-white">{{ $n }}</span>@endif
            <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 rotate-90 text-texto-fraco" />
        </button>
    @endif
    <div x-show="aberto" x-cloak x-transition.opacity class="absolute left-0 z-30 mt-1 rounded-xl border border-borda bg-white shadow-lg {{ $campo ? 'w-full min-w-[16rem]' : 'w-64' }}">
        @if (count($opcoes) > 6)
            <div class="border-b border-borda p-2">
                <input type="search" x-ref="termo" x-model="termo" class="campo-input py-1.5 text-sm" placeholder="Pesquisar" aria-label="Pesquisar {{ mb_strtolower($rotulo, 'UTF-8') }}">
            </div>
        @endif
        <div class="max-h-64 overflow-y-auto py-1">
            @forelse ($opcoes as $valor => $nome)
                <label wire:key="{{ $modelo }}-{{ $valor }}" x-show="! termo || @js(mb_strtolower($nome, 'UTF-8')).includes(termo.toLowerCase())"
                    class="flex cursor-pointer items-center gap-3 px-3 py-1.5 text-sm text-texto-forte hover:bg-fundo">
                    <input type="checkbox" wire:model.live="{{ $modelo }}" value="{{ $valor }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
                    <span class="truncate">{{ $nome }}</span>
                </label>
            @empty
                <div class="px-3 py-2 text-sm text-texto-fraco">—</div>
            @endforelse
        </div>
        @if ($n)
            <div class="border-t border-borda px-3 py-2">
                <button type="button" wire:click="$set('{{ $modelo }}', [])" class="text-xs font-medium text-verde-700 hover:underline">Limpar</button>
            </div>
        @endif
    </div>
</div>

{{-- Filtro de escolha múltipla (menu com pesquisa e caixas). $modelo = propriedade Livewire (lista).
     Dois aspetos: botão compacto com o rótulo lá dentro (barras de filtros), ou, com :campo="true",
     campo à largura da coluna com o rótulo por cima — como os selects do cartão de filtros da Nexus
     Infra ("Todos", o nome escolhido ou "N selecionados").
     Com :inverso="true", o menu ganha «Incluir · Excluir» (método Livewire alternarExclusao, trait
     FiltrosInversos); :excluido diz se está em Excluir — o botão passa a «Exceto: …», a vermelho. --}}
@props(['rotulo', 'modelo', 'opcoes' => [], 'selecionados' => [], 'campo' => false, 'inverso' => false, 'excluido' => false])

@php
    $n = count($selecionados);
    $id = 'filtro-'.$modelo;
    $fora = $inverso && $excluido && $n > 0;
    $resumo = match (true) {
        $n === 0 => 'Todos',
        $n === 1 => $opcoes[$selecionados[0]] ?? $opcoes[(int) $selecionados[0]] ?? '1 selecionado',
        default => $n.' selecionados',
    };
    if ($fora) {
        $resumo = 'Exceto: '.($n === 1 ? $resumo : $n);
    }
    $marcado = $fora ? '!border-perigo-200 !bg-perigo-100 !text-perigo-600' : '!border-verde-300 !bg-verde-50 text-verde-800';
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }} x-data="{ aberto: false, termo: '' }" @click.outside="aberto = false" @keydown.escape="aberto = false">
    @if ($campo)
        <label for="{{ $id }}" class="campo-label">{{ $rotulo }}</label>
        <button type="button" id="{{ $id }}" @click="aberto = ! aberto; $nextTick(() => aberto && $refs.termo?.focus())" :aria-expanded="aberto"
            class="campo-select block truncate text-left {{ $n ? $marcado : '' }}">{{ $resumo }}</button>
    @else
        <button type="button" @click="aberto = ! aberto; $nextTick(() => aberto && $refs.termo?.focus())" :aria-expanded="aberto"
            class="inline-flex h-10 items-center gap-2 rounded-lg border px-3 text-sm transition {{ $n ? $marcado : 'border-borda bg-white text-texto-forte hover:bg-fundo' }}">
            {{ $fora ? 'Exceto '.mb_strtolower($rotulo, 'UTF-8') : $rotulo }}
            @if ($n)<span class="rounded-full {{ $fora ? 'bg-perigo-600' : 'bg-verde-600' }} px-1.5 text-[11px] font-medium leading-4 text-white">{{ $n }}</span>@endif
            <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 rotate-90 text-texto-fraco" />
        </button>
    @endif
    <div x-show="aberto" x-cloak x-transition.opacity class="absolute left-0 z-30 mt-1 rounded-xl border border-borda bg-white shadow-lg {{ $campo ? 'w-full min-w-[16rem]' : 'w-64' }}">
        @if ($inverso)
            <div class="border-b border-borda p-2">
                <div class="grid grid-cols-2 gap-1 rounded-lg bg-fundo p-1 text-xs font-medium" role="group" aria-label="Incluir ou excluir {{ mb_strtolower($rotulo, 'UTF-8') }}">
                    <button type="button" @if ($excluido) wire:click="alternarExclusao('{{ $modelo }}')" @endif aria-pressed="{{ $excluido ? 'false' : 'true' }}"
                        class="rounded-md px-2 py-1.5 transition {{ $excluido ? 'text-texto-medio hover:text-texto-forte' : 'bg-white text-verde-700 shadow-sm' }}">Incluir</button>
                    <button type="button" @unless ($excluido) wire:click="alternarExclusao('{{ $modelo }}')" @endunless aria-pressed="{{ $excluido ? 'true' : 'false' }}"
                        class="rounded-md px-2 py-1.5 transition {{ $excluido ? 'bg-white text-perigo-600 shadow-sm' : 'text-texto-medio hover:text-texto-forte' }}">Excluir</button>
                </div>
            </div>
        @endif
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

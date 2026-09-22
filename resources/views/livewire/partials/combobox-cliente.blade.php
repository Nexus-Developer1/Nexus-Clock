{{-- Combobox de cliente com pesquisa server-side (nome/NIF/nº ERP), igual ao da Nexus Infra.
     Espera: $clientesFiltrados, a propriedade `clienteBusca` e o método `selecionarCliente(id)`.
     Opcional: $idCampo (id do input), $placeholder, $compacto (altura dos filtros) e, para uma
     segunda pesquisa na mesma página, $prop (propriedade), $metodo e $lista. --}}
@php
    $idCampo = $idCampo ?? 'cliente-combo';
    $prop = $prop ?? 'clienteBusca';
    $metodo = $metodo ?? 'selecionarCliente';
    $lista = $lista ?? $clientesFiltrados;
    $escrito = data_get($this, $prop) ?? '';
@endphp
<div x-data="{ aberto: false, destaque: 0 }" @click.outside="aberto = false" @keydown.escape.stop="aberto = false" class="relative">
    <input
        id="{{ $idCampo }}"
        type="text"
        wire:model.live.debounce.300ms="{{ $prop }}"
        @focus="aberto = true"
        @click="aberto = true"
        @input="aberto = true; destaque = 0"
        @keydown.arrow-down.prevent="aberto = true; if ($refs['opt' + (destaque + 1)]) destaque++"
        @keydown.arrow-up.prevent="if (destaque > 0) destaque--"
        @keydown.enter.prevent="$refs['opt' + destaque]?.click()"
        class="campo-input pr-10 {{ ($compacto ?? false) ? 'py-2' : '' }} {{ $classe ?? '' }}"
        placeholder="{{ $placeholder ?? 'Pesquisar por nome, NIF ou nº de cliente...' }}"
        autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="aberto">
    <svg :class="aberto && 'rotate-180'" class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>

    <ul x-show="aberto" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-60 w-full min-w-[16rem] overflow-auto rounded-lg border border-borda bg-white py-1 shadow-lg" role="listbox">
        @forelse ($lista as $idx => $cl)
            <li x-ref="opt{{ $idx }}" wire:key="{{ $idCampo }}-{{ $cl->id }}"
                wire:click="{{ $metodo }}({{ $cl->id }})"
                @click="aberto = false"
                @mouseenter="destaque = {{ $idx }}"
                :class="destaque === {{ $idx }} ? 'bg-verde-50 text-verde-700' : 'text-texto-forte'"
                class="cursor-pointer px-4 py-2 text-sm" role="option">
                <span class="font-medium">{{ $cl->nome }}</span>
                <span class="text-xs text-texto-fraco"> · NIF {{ $cl->nif ?? '—' }} · Nº {{ $cl->id_erp ?? '—' }}</span>
            </li>
        @empty
            <li class="px-4 py-2 text-sm text-texto-medio">
                {{ $escrito === '' ? 'Escreva para pesquisar…' : 'Nenhum cliente encontrado.' }}
            </li>
        @endforelse
    </ul>
</div>

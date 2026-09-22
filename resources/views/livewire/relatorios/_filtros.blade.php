{{-- Filtros comuns dos relatórios de tempo; $extra = HTML opcional no fim da linha. --}}
<section class="cartao relative z-20 mt-6 flex flex-wrap items-center gap-2 p-4 sm:px-5 print:hidden">
    <span class="mr-1 inline-flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-texto-medio"><x-icone nome="filtro" class="h-3.5 w-3.5" /> Filtros</span>
    @if ($podeVerEquipa)
        <x-filtro-multiplo rotulo="Equipa" modelo="membros" :opcoes="$opcoes['membros']" :selecionados="$membros" />
    @endif
    <x-filtro-multiplo rotulo="Cliente" modelo="clientes" :opcoes="$opcoes['clientes']" :selecionados="$clientes" />
    <x-filtro-multiplo rotulo="Projeto" modelo="projetos" :opcoes="$opcoes['projetos']" :selecionados="$projetos" />
    <x-filtro-multiplo rotulo="Etiqueta" modelo="etiquetas" :opcoes="$opcoes['etiquetas']" :selecionados="$etiquetas" />
    <select wire:model.live="estado" class="campo-select campo-barra w-auto {{ $estado ? '!border-verde-300 !bg-verde-50 text-verde-800' : '' }}" aria-label="Estado">
        <option value="">Estado</option>
        @foreach ($estados as $valor => $rotulo)
            <option value="{{ $valor }}">{{ $rotulo }}</option>
        @endforeach
    </select>
    <div class="relative min-w-[12rem] flex-1">
        <x-icone nome="pesquisa" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" />
        <input type="search" wire:model.live.debounce.400ms="descricao" class="campo-input campo-barra pl-9" placeholder="Descrição" aria-label="Descrição contém">
    </div>
    {{ $extra ?? '' }}
    @if ($filtrosAtivos > 0)
        <button type="button" wire:click="limparFiltros" class="px-2 text-sm font-medium text-verde-700 hover:underline">Limpar filtros</button>
    @endif
</section>

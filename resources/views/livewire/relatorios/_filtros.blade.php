{{-- Filtros comuns dos relatórios de tempo (Resumo, Detalhado, Semanal), num cartão como o da
     Nexus Infra (pedido de 2026-09-23): a pesquisa na descrição em cima, a toda a largura; por
     baixo, em colunas iguais e cada um com o seu rótulo, Equipa (se vê a equipa), Cliente,
     Projeto, Etiqueta e Estado. --}}
<section class="cartao relative z-20 mt-6 p-4 sm:p-5 print:hidden">
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative min-w-[14rem] flex-1">
            <x-icone nome="pesquisa" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" />
            <input type="search" wire:model.live.debounce.400ms="descricao" class="campo-input pl-10" placeholder="Pesquisar na descrição..." aria-label="Descrição contém">
        </div>
        @if ($filtrosAtivos > 0)
            <button type="button" wire:click="limparFiltros" class="px-2 text-sm font-medium text-verde-700 hover:underline">Limpar filtros</button>
        @endif
    </div>

    <div class="mt-4 flex flex-wrap gap-3">
        @if ($podeVerEquipa)
            <x-filtro-multiplo campo rotulo="Equipa" modelo="membros" :opcoes="$opcoes['membros']" :selecionados="$membros" class="min-w-[11rem] flex-1" />
        @endif
        <x-filtro-multiplo campo rotulo="Cliente" modelo="clientes" :opcoes="$opcoes['clientes']" :selecionados="$clientes" class="min-w-[11rem] flex-1" />
        <x-filtro-multiplo campo rotulo="Projeto" modelo="projetos" :opcoes="$opcoes['projetos']" :selecionados="$projetos" class="min-w-[11rem] flex-1" />
        <x-filtro-multiplo campo rotulo="Etiqueta" modelo="etiquetas" :opcoes="$opcoes['etiquetas']" :selecionados="$etiquetas" class="min-w-[11rem] flex-1" />
        <div class="min-w-[11rem] flex-1">
            <label for="filtro-estado" class="campo-label">Estado</label>
            <select id="filtro-estado" wire:model.live="estado" class="campo-select {{ $estado ? '!border-verde-300 !bg-verde-50 text-verde-800' : '' }}">
                <option value="">Todos</option>
                @foreach ($estados as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
    </div>
</section>

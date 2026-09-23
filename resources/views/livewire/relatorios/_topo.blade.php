@php
    // $partilhado (relatório aberto por link): período fixo se as datas estiverem bloqueadas.
    // $podePartilhar: mostra o botão Partilhar.
    $partilhado ??= null;
    $podePartilhar ??= false;
    // $recibos: acrescenta "Recibos (ZIP)" ao menu Exportar (relatório Despesas).
    $recibos ??= false;
    $periodoFixo = $partilhado?->bloquear_datas;
@endphp

{{-- Período, partilhar e exportar — nas ações do cabeçalho da página (x-cabecalho-pagina). --}}
<div class="flex flex-wrap items-center gap-2">
    @if ($periodoFixo)
        <span class="inline-flex h-10 min-w-[12rem] items-center gap-2 rounded-lg border border-borda bg-white px-3 text-sm text-texto-forte">
            <x-icone nome="cadeado" class="text-texto-fraco" /> {{ $rotuloPeriodo }}
        </span>
    @else
    <div class="flex items-center rounded-lg border border-borda bg-white">
        <div class="relative" x-data="{ aberto: false }" @click.outside="aberto = false" @keydown.escape="aberto = false" @datas-aplicadas.window="aberto = false">
            <button type="button" @click="aberto = ! aberto" class="inline-flex h-[38px] min-w-[12rem] items-center gap-2 rounded-l-lg px-3 text-sm text-texto-forte hover:bg-fundo">
                <x-icone nome="calendario" class="text-texto-fraco" /> {{ $rotuloPeriodo }}
            </button>
            <div x-show="aberto" x-cloak class="absolute right-0 z-30 mt-1 w-72 rounded-xl border border-borda bg-white text-sm shadow-lg">
                <div class="grid grid-cols-2 gap-1 p-2">
                    @foreach (['semana' => 'Esta semana', 'semana-passada' => 'Semana passada', 'mes' => 'Este mês', 'mes-passado' => 'Mês passado', 'ano' => 'Este ano', 'ano-passado' => 'Ano passado'] as $valor => $rotulo)
                        <button type="button" wire:click="escolherPeriodo('{{ $valor }}')" @click="aberto = false" class="rounded-lg px-3 py-2 text-left hover:bg-fundo">{{ $rotulo }}</button>
                    @endforeach
                </div>
                <form wire:submit="aplicarDatas" class="space-y-2 border-t border-borda p-3">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" wire:model="escolhaDe" class="campo-input py-1.5 text-sm" aria-label="De">
                        <input type="date" wire:model="escolhaAte" class="campo-input py-1.5 text-sm" aria-label="Até">
                    </div>
                    @error('datas') <p class="text-xs text-perigo-500">{{ $message }}</p> @enderror
                    <button type="submit" class="botao-secundario w-full justify-center py-1.5">Aplicar datas</button>
                </form>
            </div>
        </div>
        <button type="button" wire:click="anterior" class="h-[38px] border-l border-borda px-2.5 text-texto-medio hover:bg-fundo print:hidden" aria-label="Período anterior"><x-icone nome="seta-esq" traco="2" /></button>
        <button type="button" wire:click="seguinte" class="h-[38px] rounded-r-lg border-l border-borda px-2.5 text-texto-medio hover:bg-fundo print:hidden" aria-label="Período seguinte"><x-icone nome="seta-dir" traco="2" /></button>
    </div>
    @endif

    @if ($podePartilhar)
        <button type="button" wire:click="abrirPartilha" class="botao-quadrado print:hidden" title="Partilhar" aria-label="Partilhar"><x-icone nome="partilhar" /></button>
    @endif

    <div class="relative print:hidden" x-data="{ aberto: false }" @click.outside="aberto = false" @keydown.escape="aberto = false">
        <button type="button" @click="aberto = ! aberto" class="botao-secundario"><x-icone nome="descarregar" /> Exportar <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 rotate-90" /></button>
        <div x-show="aberto" x-cloak class="absolute right-0 z-30 mt-1 w-44 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
            <button type="button" wire:click="exportar('csv')" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="descarregar" /> CSV</button>
            <button type="button" wire:click="exportar('pdf')" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="faturacao" /> PDF</button>
            @if ($recibos)
                <button type="button" wire:click="descarregarRecibos" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="arquivo" /> Recibos (ZIP)</button>
            @endif
            <button type="button" onclick="window.print()" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="faturacao" /> Imprimir</button>
        </div>
    </div>
</div>

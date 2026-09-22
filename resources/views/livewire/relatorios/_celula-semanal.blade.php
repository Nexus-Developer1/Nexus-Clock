{{-- Célula do relatório Semanal: $cel (segundos, valor), $col, $url, $estilo (intensidade), $forte (linha de totais). --}}
@use('App\Support\Dinheiro')
@use('App\Support\Horas')

<td class="border-l border-borda/60 p-0 text-center tabular-nums {{ $col['fimDeSemana'] ? 'bg-slate-50' : '' }}">
    @if ($cel['segundos'] > 0)
        <a href="{{ $url }}" wire:navigate @click.stop style="{{ $estilo }}"
           class="block px-2 {{ $forte ? 'py-3' : 'py-2.5' }} outline-none transition hover:ring-2 hover:ring-inset hover:ring-verde-500 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-verde-500"
           title="{{ $col['rotulo'] }} {{ $col['detalhe'] }}: {{ Horas::hm($cel['segundos']) }}">
            @if ($comTempo)<div class="{{ $forte ? 'font-semibold text-texto-forte' : 'text-texto-forte' }}">{{ Horas::hm($cel['segundos']) }}</div>@endif
            @if ($comValor)<div class="{{ $comTempo ? 'text-[11px] text-texto-medio' : ($forte ? 'font-semibold text-texto-forte' : 'text-texto-forte') }}">{{ Dinheiro::formatar($cel['valor']) }}</div>@endif
        </a>
    @else
        <span class="block px-2 {{ $forte ? 'py-3' : 'py-2.5' }} text-slate-300">–</span>
    @endif
</td>

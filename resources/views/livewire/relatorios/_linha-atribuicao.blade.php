@use('Illuminate\Support\Js')

{{-- Linha do relatório Atribuições: $l (grupo ou subgrupo), $filho, $chavePai. --}}
@php
    $percentagem = $l['agendado'] > 0 ? (int) round($l['registado'] * 100 / $l['agendado']) : null;
@endphp

<tr @if ($filho)
        wire:key="atr-filho-{{ $agrupar1 }}-{{ $chavePai }}-{{ $agrupar2 }}-{{ $l['chave'] }}" x-show="abertos.includes({{ Js::from($chavePai) }})" x-cloak class="bg-fundo/40 print:!table-row"
    @else
        wire:key="atr-{{ $agrupar1 }}-{{ $l['chave'] }}" class="{{ $comFilhos && $l['filhos'] !== [] ? 'cursor-pointer' : '' }}"
        @if ($comFilhos && $l['filhos'] !== []) @click="abertos.includes(@js($l['chave'])) ? abertos = abertos.filter(c => c !== @js($l['chave'])) : abertos.push(@js($l['chave']))" @endif
    @endif>
    <td class="max-w-0">
        <div class="flex min-w-0 items-center gap-2 {{ $filho ? 'pl-8' : '' }}">
            @if (! $filho && $comFilhos)
                <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 shrink-0 text-texto-fraco transition print:hidden {{ $l['filhos'] === [] ? 'invisible' : '' }}" x-bind:class="abertos.includes({{ Js::from($l['chave']) }}) ? 'rotate-90' : ''" />
            @endif
            @if ($l['cor'])
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $l['cor'] }}"></span>
            @elseif (! $filho && ($agrupar1 === 'membro'))
                <x-avatar :nome="$l['nome']" tom="claro" class="!h-6 !w-6 text-[9px]" />
            @endif
            <span class="truncate {{ $filho ? 'text-texto-medio' : 'font-medium text-texto-forte' }}" title="{{ $l['nome'] }}">{{ $l['nome'] }}</span>
            @if (! $filho && $comFilhos && $l['filhos'] !== [])
                <span class="shrink-0 rounded-full bg-fundo px-1.5 text-[11px] text-texto-medio">{{ count($l['filhos']) }}</span>
            @endif
        </div>
    </td>
    <td class="text-right tabular-nums {{ $l['agendado'] ? 'text-texto-forte' : 'text-texto-fraco' }}">{{ $hms($l['agendado']) }}</td>
    <td>
        <div class="flex items-center justify-end gap-3">
            @if ($percentagem !== null)
                <span class="h-1.5 w-16 overflow-hidden rounded-full bg-fundo" aria-hidden="true" title="{{ $percentagem }}% do agendado">
                    <span class="block h-full rounded-full {{ $percentagem > 100 ? 'bg-aviso-500' : 'bg-verde-500' }}" style="width: {{ min(100, $percentagem) }}%"></span>
                </span>
            @endif
            <span class="tabular-nums {{ $l['registado'] ? 'text-texto-forte' : 'text-texto-fraco' }}">{{ $hms($l['registado']) }}</span>
        </div>
    </td>
    <td class="text-right tabular-nums {{ $classeDiferenca($l['diferenca']) }}">{{ $this->diferenca($l['diferenca']) }}</td>
    <td><span class="etiqueta {{ $classesEstado[$l['estado']] }}">{{ $estados[$l['estado']] }}</span></td>
</tr>

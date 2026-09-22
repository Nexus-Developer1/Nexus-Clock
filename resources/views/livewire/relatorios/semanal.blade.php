@use('App\Services\Tempos\PainelTempos')
@use('App\Support\Dinheiro')
@use('App\Support\Horas')
@use('Illuminate\Support\Js')

@php
    $colunas = $grelha['colunas'];
    $comFilhos = $agrupar2 !== '';
    $seta = fn (string $campo) => ltrim($ordem, '-') === $campo ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '';
    // Intensidade da célula (um só tom, do claro ao escuro), pelo tempo.
    $fundo = fn (int $segundos) => $segundos > 0 ? 'background: rgba(22, 163, 74, '.round(0.06 + 0.30 * $segundos / $maximo, 3).')' : '';
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Relatórios', 'Semanal']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-cabecalho-pagina titulo="Relatórios" />

            @include('livewire.relatorios._topo', ['atual' => 'relatorios.semanal'])

            @include('livewire.relatorios._filtros')

            <section class="cartao mt-6" x-data="{ abertos: [] }">
                {{-- Totais e opções --}}
                <header class="flex flex-wrap items-center justify-between gap-3 rounded-t-2xl border-b border-borda bg-fundo/80 px-5 py-3">
                    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                        <div><span class="text-xs text-texto-medio">Total</span> <span class="ml-1 text-xl font-semibold tabular-nums text-texto-forte">{{ PainelTempos::hms($grelha['total']['segundos']) }}</span></div>
                        @if ($comValor)
                            <div><span class="text-xs text-texto-medio">Valor</span> <span class="ml-1 text-sm font-medium tabular-nums text-texto-forte">{{ Dinheiro::formatar($grelha['total']['valor']) }}</span></div>
                        @endif
                        @if ($grelha['escala'] !== 'dia')
                            <div class="text-xs text-texto-fraco">por {{ $grelha['escala'] === 'semana' ? 'semana' : 'mês' }}</div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2 print:hidden">
                        <span class="text-xs font-medium text-texto-medio">Agrupar por</span>
                        <select wire:model.live="agrupar1" class="campo-select campo-mini w-auto" aria-label="Agrupar por">
                            @foreach ($agrupamentos as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="agrupar2" class="campo-select campo-mini w-auto" aria-label="Depois por">
                            <option value="">—</option>
                            @foreach ($agrupamentos as $valor => $rotulo)
                                @continue($valor === $agrupar1)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @if ($podeVerEquipa)
                            <select wire:model.live="mostrar" class="campo-select campo-mini w-auto" aria-label="Mostrar">
                                <option value="tempo">Mostrar tempo</option>
                                <option value="valor">Mostrar valor</option>
                                <option value="ambos">Tempo e valor</option>
                            </select>
                        @endif
                    </div>
                </header>

                @if ($linhas === [])
                    <x-estado-vazio icone="calendario" :titulo="$filtrosAtivos > 0 ? 'Nenhum registo com estes filtros' : 'Sem dados'" class="py-16" />
                @else
                    <div class="relative overflow-x-auto rounded-b-2xl">
                        <table class="w-full border-collapse text-sm" style="min-width: {{ 260 + count($colunas) * 84 + 100 }}px">
                            <thead>
                                <tr class="border-b border-borda bg-fundo/60 text-xs font-medium text-texto-medio">
                                    <th class="sticky left-0 z-10 bg-[#f8fafc] px-5 py-2.5 text-left">
                                        <span class="flex items-center gap-2">
                                            @if ($comFilhos)
                                                <button type="button" class="botao-icone h-6 w-6 print:hidden" aria-label="Abrir ou fechar todos"
                                                    @click="abertos = abertos.length ? [] : @js(collect($linhas)->pluck('chave')->all())">
                                                    <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 transition" x-bind:class="abertos.length ? 'rotate-90' : ''" />
                                                </button>
                                            @endif
                                            <button type="button" wire:click="ordenarPor('nome')" class="hover:text-texto-forte">{{ $agrupamentos[$agrupar1] }}{{ $comFilhos ? ' / '.$agrupamentos[$agrupar2] : '' }} {{ $seta('nome') }}</button>
                                        </span>
                                    </th>
                                    @foreach ($colunas as $c)
                                        <th class="w-[84px] px-2 py-2.5 text-center font-medium {{ $c['fimDeSemana'] ? 'bg-slate-100/70' : '' }}">
                                            <div class="text-texto-forte">{{ $c['rotulo'] }}</div>
                                            <div class="whitespace-nowrap text-[11px] font-normal text-texto-fraco">{{ $c['detalhe'] }}</div>
                                        </th>
                                    @endforeach
                                    <th class="w-[100px] px-4 py-2.5 text-right">
                                        <button type="button" wire:click="ordenarPor('total')" class="hover:text-texto-forte">Total {{ $seta('total') }}</button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($linhas as $l)
                                    <tr wire:key="linha-{{ $agrupar1 }}-{{ $l['chave'] }}" class="group border-b border-borda {{ $comFilhos ? 'cursor-pointer' : '' }}"
                                        @if ($comFilhos) @click="abertos.includes(@js($l['chave'])) ? abertos = abertos.filter(c => c !== @js($l['chave'])) : abertos.push(@js($l['chave']))" @endif>
                                        <td class="sticky left-0 z-10 max-w-[260px] bg-white px-5 py-2.5 group-hover:bg-[#fafbfc]">
                                            <div class="flex min-w-0 items-center gap-2">
                                                @if ($comFilhos)
                                                    <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 shrink-0 text-texto-fraco transition print:hidden" x-bind:class="abertos.includes({{ Js::from($l['chave']) }}) ? 'rotate-90' : ''" />
                                                @endif
                                                <span class="truncate font-medium {{ $l['chave'] === '' ? 'text-texto-medio' : 'text-texto-forte' }}" title="{{ $l['nome'] }}">{{ $l['nome'] }}</span>
                                                @if ($comFilhos)<span class="shrink-0 rounded-full bg-fundo px-1.5 text-[11px] text-texto-medio">{{ count($l['filhos']) }}</span>@endif
                                            </div>
                                        </td>
                                        @foreach ($colunas as $c)
                                            @include('livewire.relatorios._celula-semanal', ['cel' => $l['celulas'][$c['chave']], 'col' => $c, 'url' => $this->ligacao($c['de'], $c['ate'], $l['chave']), 'estilo' => $fundo($l['celulas'][$c['chave']]['segundos']), 'forte' => false])
                                        @endforeach
                                        <td class="px-4 py-2.5 text-right tabular-nums">
                                            @if ($comTempo)<div class="font-semibold text-texto-forte">{{ Horas::hm($l['segundos']) }}</div>@endif
                                            @if ($comValor)<div class="{{ $comTempo ? 'text-xs text-texto-medio' : 'font-semibold text-texto-forte' }}">{{ Dinheiro::formatar($l['valor']) }}</div>@endif
                                        </td>
                                    </tr>
                                    @foreach ($this->ordenarFilhos($l['filhos']) as $f)
                                        <tr wire:key="filho-{{ $agrupar1 }}-{{ $l['chave'] }}-{{ $agrupar2 }}-{{ $f['chave'] }}" x-show="abertos.includes({{ Js::from($l['chave']) }})" x-cloak class="border-b border-borda bg-fundo/40 print:!table-row">
                                            <td class="sticky left-0 z-10 max-w-[260px] bg-[#fbfcfd] py-2 pl-12 pr-5">
                                                <span class="block truncate {{ $f['chave'] === '' ? 'text-texto-fraco' : 'text-texto-medio' }}" title="{{ $f['nome'] }}">{{ $f['nome'] }}</span>
                                            </td>
                                            @foreach ($colunas as $c)
                                                @include('livewire.relatorios._celula-semanal', ['cel' => $f['celulas'][$c['chave']], 'col' => $c, 'url' => $this->ligacao($c['de'], $c['ate'], $l['chave'], $f['chave']), 'estilo' => '', 'forte' => false])
                                            @endforeach
                                            <td class="px-4 py-2 text-right tabular-nums text-texto-medio">
                                                @if ($comTempo)<div>{{ Horas::hm($f['segundos']) }}</div>@endif
                                                @if ($comValor)<div class="{{ $comTempo ? 'text-xs text-texto-fraco' : '' }}">{{ Dinheiro::formatar($f['valor']) }}</div>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bg-fundo/80">
                                    <td class="sticky left-0 z-10 bg-[#f8fafc] px-5 py-3 text-xs font-semibold uppercase tracking-wide text-texto-medio">Total</td>
                                    @foreach ($colunas as $c)
                                        @include('livewire.relatorios._celula-semanal', ['cel' => $grelha['totais'][$c['chave']], 'col' => $c, 'url' => $this->ligacao($c['de'], $c['ate']), 'estilo' => '', 'forte' => true])
                                    @endforeach
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        @if ($comTempo)<div class="font-semibold text-texto-forte">{{ PainelTempos::hms($grelha['total']['segundos']) }}</div>@endif
                                        @if ($comValor)<div class="{{ $comTempo ? 'text-xs text-texto-medio' : 'font-semibold text-texto-forte' }}">{{ Dinheiro::formatar($grelha['total']['valor']) }}</div>@endif
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @if ($comTempo)
                        <div class="flex items-center justify-end gap-2 border-t border-borda px-5 py-2 text-[11px] text-texto-fraco print:hidden" aria-hidden="true">
                            menos
                            @foreach ([0.06, 0.14, 0.22, 0.30, 0.36] as $a)
                                <span class="h-3 w-5 rounded-sm" style="background: rgba(22, 163, 74, {{ $a }})"></span>
                            @endforeach
                            mais
                        </div>
                    @endif
                @endif
            </section>
        </div>
    </main>
</div>

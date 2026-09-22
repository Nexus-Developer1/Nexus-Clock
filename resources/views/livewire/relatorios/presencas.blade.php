@use('App\Services\Tempos\PainelTempos')
@use('App\Services\Tempos\Presencas')

@php
    $hms = fn (int $s) => PainelTempos::hms($s);
    $seta = fn (string $campo) => ltrim($ordem, '-') === $campo ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '';
    $classeSaldo = fn (int $s) => $s < 0 ? 'text-perigo-600' : ($s > 0 ? 'text-verde-700' : 'text-texto-medio');
    $resumo = [
        ['Capacidade', $hms($total['capacidade']), 'text-texto-forte'],
        ['Trabalho', $hms($total['trabalho']), $total['trabalho'] < $total['capacidade'] ? 'text-perigo-600' : 'text-texto-forte'],
        ['Horas extra', $hms($total['extra']), 'text-texto-forte'],
        ['Em falta', $hms($total['em_falta']), 'text-texto-forte'],
        ['Saldo', Presencas::saldo($total['saldo']), $classeSaldo($total['saldo'])],
        ['Pausas', $hms($total['pausa']), 'text-texto-forte'],
    ];
    $colunas = [
        'dia' => ['Dia', 'text-left'],
        'inicio' => ['Entrada', 'text-left'],
        'fim' => ['Saída', 'text-left'],
        'capacidade' => ['Capacidade', 'text-right'],
        'trabalho' => ['Trabalho', 'text-right'],
        'extra' => ['Horas extra', 'text-right'],
        'em_falta' => ['Em falta', 'text-right'],
        'saldo' => ['Saldo', 'text-right'],
        'pausa' => ['Pausas', 'text-right'],
    ];
    $grupoAnterior = null;
@endphp

<div>
    <x-topbar :breadcrumb="['Tempos', 'Relatórios', 'Presenças']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-cabecalho-pagina titulo="Relatórios" />

            @include('livewire.relatorios._topo', ['atual' => 'relatorios.presencas'])

            {{-- Filtros --}}
            <section class="cartao relative z-20 mt-6 flex flex-wrap items-center gap-2 p-4 sm:px-5 print:hidden">
                <span class="mr-1 inline-flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-texto-medio"><x-icone nome="filtro" class="h-3.5 w-3.5" /> Filtros</span>
                @if ($podeVerEquipa)
                    <x-filtro-multiplo rotulo="Equipa" modelo="membros" :opcoes="$opcoes['membros']" :selecionados="$membros" />
                @endif
                <select wire:model.live="situacao" class="campo-select campo-barra w-auto {{ $situacao ? '!border-verde-300 !bg-verde-50 text-verde-800' : '' }}" aria-label="Situação">
                    <option value="">Situação</option>
                    @foreach ($situacoes as $valor => $rotulo)
                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
                @if ($filtrosAtivos > 0)
                    <button type="button" wire:click="limparFiltros" class="px-2 text-sm font-medium text-verde-700 hover:underline">Limpar filtros</button>
                @endif
            </section>

            <section class="cartao mt-6">
                {{-- Totais --}}
                <div class="grid grid-cols-2 divide-borda rounded-t-2xl border-b border-borda bg-fundo/80 sm:grid-cols-3 lg:grid-cols-6 lg:divide-x">
                    @foreach ($resumo as [$rotulo, $valor, $classe])
                        <div class="px-5 py-3">
                            <div class="text-xs text-texto-medio">{{ $rotulo }}</div>
                            <div class="mt-0.5 text-lg font-semibold tabular-nums {{ $classe }}">{{ $valor }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-borda px-5 py-2.5 print:hidden">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium text-texto-medio">Agrupar por</span>
                        <select wire:model.live="agrupar" class="campo-select campo-mini w-auto" aria-label="Agrupar por">
                            <option value="">Nenhum</option>
                            <option value="membro">Membro</option>
                            <option value="dia">Dia</option>
                        </select>
                    </div>
                    <span class="text-xs text-texto-fraco">{{ $nLinhas }} {{ $nLinhas === 1 ? 'linha' : 'linhas' }} · capacidade por omissão {{ str_replace('.', ',', (string) config('tempos.capacidade_diaria_horas')) }} h</span>
                </div>

                @if ($linhas === [])
                    <x-estado-vazio icone="relogio" titulo="Nada para mostrar" class="py-16" />
                @else
                    <div class="relative overflow-x-auto">
                        <table class="tabela min-w-[960px] [&_td]:px-3 [&_tbody_td]:py-2 [&_th]:px-3">
                            <thead>
                                <tr>
                                    <th><button type="button" wire:click="ordenarPor('nome')" class="hover:text-texto-forte">Membro {{ $seta('nome') }}</button></th>
                                    @foreach ($colunas as $campo => [$rotulo, $alinhar])
                                        <th class="w-[9%]"><button type="button" wire:click="ordenarPor('{{ $campo }}')" class="block w-full {{ $alinhar }} hover:text-texto-forte">{{ $rotulo }} {{ $seta($campo) }}</button></th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($linhas as $l)
                                    @php
                                        $grupo = $this->chaveGrupo($l);
                                        $folga = $l['capacidade'] === 0;
                                    @endphp
                                    @if ($agrupar !== '' && $grupo !== $grupoAnterior)
                                        @php $s = $subtotais[$grupo]; $grupoAnterior = $grupo; @endphp
                                        <tr wire:key="grupo-{{ $agrupar }}-{{ $grupo }}" class="!bg-fundo/80">
                                            <td colspan="4" class="font-semibold text-texto-forte">
                                                {{ $agrupar === 'membro' ? $l['nome'] : ucfirst($l['dia']->translatedFormat('l, d/m/Y')) }}
                                                <span class="ml-1 text-xs font-normal text-texto-fraco">{{ $s['n'] }} {{ $s['n'] === 1 ? 'linha' : 'linhas' }}</span>
                                            </td>
                                            <td class="text-right font-semibold tabular-nums text-texto-forte">{{ $hms($s['capacidade']) }}</td>
                                            <td class="text-right font-semibold tabular-nums text-texto-forte">{{ $hms($s['trabalho']) }}</td>
                                            <td class="text-right font-semibold tabular-nums text-texto-forte">{{ $hms($s['extra']) }}</td>
                                            <td class="text-right font-semibold tabular-nums text-texto-forte">{{ $hms($s['em_falta']) }}</td>
                                            <td class="text-right font-semibold tabular-nums {{ $classeSaldo($s['saldo']) }}">{{ Presencas::saldo($s['saldo']) }}</td>
                                            <td class="text-right font-semibold tabular-nums text-texto-forte">{{ $hms($s['pausa']) }}</td>
                                        </tr>
                                    @endif
                                    <tr wire:key="linha-{{ $l['tecnico_id'] }}-{{ $l['dia']->toDateString() }}" class="{{ $folga && $l['trabalho'] === 0 ? 'text-texto-fraco' : '' }}">
                                        <td class="max-w-[14rem]">
                                            @if ($agrupar !== 'membro')
                                                <div class="flex min-w-0 items-center gap-2.5 {{ $agrupar !== '' ? 'pl-4' : '' }}">
                                                    <x-avatar :nome="$l['nome']" tom="claro" class="h-6 w-6 text-[9px]" />
                                                    <span class="truncate {{ $folga && $l['trabalho'] === 0 ? '' : 'text-texto-forte' }}">{{ $l['nome'] }}</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap tabular-nums">
                                            {{ $l['dia']->format('d/m/Y') }}
                                            <span class="ml-1 text-xs text-texto-fraco">{{ ucfirst($l['dia']->translatedFormat('D')) }}</span>
                                        </td>
                                        <td class="tabular-nums">{{ $l['inicio']?->format('H:i') ?? '—' }}</td>
                                        <td class="tabular-nums">{{ $l['fim']?->format('H:i') ?? '—' }}</td>
                                        <td class="text-right tabular-nums">{{ $hms($l['capacidade']) }}</td>
                                        <td class="text-right tabular-nums {{ $l['trabalho'] < $l['capacidade'] ? 'font-medium text-perigo-600' : ($l['trabalho'] ? 'font-medium text-texto-forte' : '') }}">
                                            @if ($l['trabalho'])
                                                <a href="{{ route('relatorios.detalhado', ['periodo' => 'datas', 'de' => $l['dia']->toDateString(), 'ate' => $l['dia']->toDateString(), 'membros' => [$l['tecnico_id']]]) }}" wire:navigate class="hover:underline">{{ $hms($l['trabalho']) }}</a>
                                            @else
                                                {{ $l['capacidade'] ? $hms(0) : '—' }}
                                            @endif
                                        </td>
                                        <td class="text-right tabular-nums">{{ $l['extra'] ? $hms($l['extra']) : '—' }}</td>
                                        <td class="text-right tabular-nums">{{ $l['em_falta'] ? $hms($l['em_falta']) : '—' }}</td>
                                        <td class="text-right tabular-nums {{ $l['saldo'] ? $classeSaldo($l['saldo']) : '' }}">{{ $l['saldo'] ? Presencas::saldo($l['saldo']) : '—' }}</td>
                                        <td class="text-right tabular-nums">{{ $l['pausa'] ? $hms($l['pausa']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($paginas > 1)
                        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-borda px-5 py-3 text-sm print:hidden">
                            <span class="tabular-nums text-texto-medio">{{ ($pagina - 1) * 50 + 1 }}–{{ min($pagina * 50, $nLinhas) }} de {{ $nLinhas }}</span>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="irPara({{ $pagina - 1 }})" @disabled($pagina <= 1) class="botao-icone h-8 w-8 disabled:opacity-40" aria-label="Página anterior"><x-icone nome="seta-esq" traco="2" /></button>
                                <span class="tabular-nums text-texto-medio">Página {{ $pagina }} de {{ $paginas }}</span>
                                <button type="button" wire:click="irPara({{ $pagina + 1 }})" @disabled($pagina >= $paginas) class="botao-icone h-8 w-8 disabled:opacity-40" aria-label="Página seguinte"><x-icone nome="seta-dir" traco="2" /></button>
                            </div>
                        </footer>
                    @endif
                @endif
            </section>
        </div>
    </main>
</div>

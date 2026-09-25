@use('App\Services\Tempos\PainelTempos')
@use('App\Support\Horas')

@php
    // Cores das séries (validadas para daltonismo e separação); "Outros" em cinzento neutro.
    $cores = ['#16a34a', '#2a78d6', '#eb6834', '#7c3aed', '#eda100'];
    $corDe = fn (string $chave) => $chave === 'outros' || ! in_array($chave, $dados['series'], true) ? '#cbd5e1' : $cores[array_search($chave, $dados['series'], true)];
    $pct = fn (float $p) => str_replace('.', ',', (string) $p).'%';

    // Eixo vertical: horas redondas.
    $topoHoras = max(1, (int) ceil($dados['maximoDia'] / 3600));
    $topoSeg = $topoHoras * 3600;
    $mes = count($dados['dias']) > 7;

    // Anel e lista: grupos com cor + o resto junto em "Outros".
    $fatias = collect($dados['grupos'])->filter(fn ($g) => in_array($g['chave'], $dados['series'], true))->values();
    $resto = collect($dados['grupos'])->reject(fn ($g) => in_array($g['chave'], $dados['series'], true));
    if ($resto->sum('segundos') > 0) {
        $fatias->push(['chave' => 'outros', 'nome' => $resto->count() === 1 ? $resto->first()['nome'] : 'Outros', 'segundos' => (int) $resto->sum('segundos'), 'percentagem' => round($resto->sum('percentagem'), 1)]);
    }
    $raio = 70;
    $perimetro = 2 * M_PI * $raio;
    $somaFatias = max(1, (int) $fatias->sum('segundos'));

    // Ligação de um grupo ao Detalhado.
    $ligacaoGrupo = fn (string $chave) => match (true) {
        $chave === 'outros' => null,
        $agrupar === 'projeto' => $ligacao(['projetos' => [(int) $chave]]),
        $agrupar === 'cliente' => $ligacao(['clientes' => [(int) $chave]]),
        $chave === '' => null,
        $agrupar === 'membro' => $ligacao(['membros' => [(int) $chave]]),
        default => $ligacao(['etiquetas' => [$chave]]),
    };

    $atividades = $dados['atividades'];
    $maximoAtividade = max(1, (int) collect($atividades)->max('segundos'));
    $comEquipa = $equipa !== [];
    $linhasAtividades = max(1, $comEquipa ? count($atividades) : (int) ceil(count($atividades) / 2));

    // Comparação com o período anterior.
    $delta = $dados['total'] - $anterior;
    $notaTotal = match (true) {
        $anterior === 0 && $dados['total'] === 0 => ['texto' => $de->format('d/m').' – '.$ate->format('d/m/Y'), 'classe' => 'text-texto-fraco'],
        $delta > 0 => ['texto' => '▲ '.Horas::hm($delta).' vs. período anterior', 'classe' => 'text-verde-700'],
        $delta < 0 => ['texto' => '▼ '.Horas::hm(-$delta).' vs. período anterior', 'classe' => 'text-perigo-500'],
        default => ['texto' => '= período anterior', 'classe' => 'text-texto-fraco'],
    };
    $notaTopo = fn (?array $topo) => $topo ? Horas::hm($topo['segundos']).' · '.$pct(round($topo['segundos'] * 100 / max(1, $dados['total']), 1)) : '';
    // Cada cartão abre a página correspondente: os registos do período no Detalhado (os faturáveis, no
    // segundo) e a página do projeto e do cliente principais.
    $resumo = [
        ['rotulo' => 'Tempo total', 'valor' => PainelTempos::hms($dados['total']), 'nota' => $notaTotal['texto'], 'classe' => $notaTotal['classe'], 'numero' => true, 'url' => $ligacao()],
        ['rotulo' => 'Faturável', 'valor' => PainelTempos::hms($dados['faturavel']), 'nota' => $dados['total'] ? $pct(round($dados['faturavel'] * 100 / $dados['total'], 1)).' do total' : '', 'classe' => 'text-texto-fraco', 'numero' => true, 'url' => $ligacao(['estado' => 'faturavel'])],
        ['rotulo' => 'Projeto principal', 'valor' => $dados['topProjeto']['nome'] ?? '—', 'nota' => $notaTopo($dados['topProjeto']), 'classe' => 'text-texto-fraco', 'numero' => false, 'url' => $urlProjeto],
        ['rotulo' => 'Cliente principal', 'valor' => $dados['topCliente']['nome'] ?? '—', 'nota' => $notaTopo($dados['topCliente']), 'classe' => 'text-texto-fraco', 'numero' => false, 'url' => $urlCliente],
    ];
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Painel']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-cabecalho-pagina titulo="Painel">
                <x-slot:acoes>
                    <select wire:model.live="agrupar" class="campo-select campo-barra w-auto" aria-label="Agrupar por">
                        @foreach ($agrupamentos as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @if ($podeVerEquipa)
                        <select wire:model.live="quem" class="campo-select campo-barra w-auto" aria-label="De quem">
                            <option value="eu">Só eu</option>
                            <option value="equipa">Equipa</option>
                        </select>
                    @endif
                    <div class="flex items-center rounded-lg border border-borda bg-white">
                        <div class="relative" x-data="{ aberto: false }" @click.outside="aberto = false" @keydown.escape="aberto = false">
                            <button type="button" @click="aberto = ! aberto" class="inline-flex h-[38px] min-w-[11rem] items-center gap-2 rounded-l-lg px-3 text-sm text-texto-forte hover:bg-fundo">
                                <x-icone nome="calendario" class="text-texto-fraco" /> {{ $rotuloPeriodo }}
                            </button>
                            <div x-show="aberto" x-cloak class="absolute right-0 z-20 mt-1 w-48 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg">
                                @foreach (['semana' => 'Esta semana', 'semana-passada' => 'Semana passada', 'mes' => 'Este mês', 'mes-passado' => 'Mês passado'] as $valor => $rotulo)
                                    <button type="button" wire:click="escolherPeriodo('{{ $valor }}')" @click="aberto = false" class="block w-full px-4 py-2 text-left hover:bg-fundo">{{ $rotulo }}</button>
                                @endforeach
                            </div>
                        </div>
                        <button type="button" wire:click="anterior" class="h-[38px] border-l border-borda px-2.5 text-texto-medio hover:bg-fundo" aria-label="Período anterior"><x-icone nome="seta-esq" traco="2" /></button>
                        <button type="button" wire:click="seguinte" class="h-[38px] rounded-r-lg border-l border-borda px-2.5 text-texto-medio hover:bg-fundo" aria-label="Período seguinte"><x-icone nome="seta-dir" traco="2" /></button>
                    </div>
                </x-slot:acoes>
            </x-cabecalho-pagina>

            {{-- Resumo --}}
            <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-6 lg:grid-cols-4">
                @foreach ($resumo as $r)
                    @php $etiqueta = $r['url'] ? 'a' : 'div'; @endphp
                    <{{ $etiqueta }} @if ($r['url']) href="{{ $r['url'] }}" wire:navigate @endif
                        class="cartao block min-w-0 px-4 py-4 sm:px-6 sm:py-5 {{ $r['url'] ? 'group transition hover:border-verde-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-verde-500' : '' }}">
                        <div class="flex items-center justify-between gap-2 text-xs font-medium text-texto-medio">
                            {{ $r['rotulo'] }}
                            @if ($r['url'])<x-icone nome="seta-dir" traco="2" class="h-3.5 w-3.5 text-texto-fraco opacity-0 transition group-hover:opacity-100" />@endif
                        </div>
                        <div class="mt-2 truncate text-lg font-semibold sm:text-2xl text-texto-forte {{ $r['numero'] ? 'tabular-nums' : '' }} {{ $r['url'] ? 'group-hover:text-verde-700' : '' }}" title="{{ $r['valor'] }}">{{ $r['valor'] }}</div>
                        <div class="mt-1 h-4 truncate text-xs tabular-nums {{ $r['classe'] }}">{{ $r['nota'] }}</div>
                    </{{ $etiqueta }}>
                @endforeach
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:mt-6 sm:gap-6 lg:grid-cols-3">
                {{-- Horas por dia --}}
                <section class="cartao flex min-w-0 flex-col overflow-hidden lg:col-span-2" x-data="{ dica: null }">
                    <header class="flex h-12 items-center justify-between gap-3 border-b border-borda bg-fundo/80 px-5">
                        <h2 class="text-xs font-medium text-texto-medio">Horas por dia</h2>
                        <div class="text-xs" x-show="dica" x-cloak aria-live="polite">
                            <span class="font-medium text-texto-forte" x-text="dica?.dia"></span>
                            <span class="ml-1 tabular-nums text-texto-medio" x-text="dica?.total"></span>
                        </div>
                    </header>

                    @if ($dados['total'] === 0)
                        <div class="flex flex-1 items-center justify-center">
                            <x-estado-vazio icone="grafico" titulo="Sem dados" class="py-12" />
                        </div>
                    @else
                        <div class="flex flex-1 flex-col justify-center p-5">
                            <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-texto-medio">
                                @foreach ($dados['series'] as $chave)
                                    <span class="inline-flex min-w-0 items-center gap-1.5"><span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $corDe($chave) }}"></span><span class="max-w-[10rem] truncate">{{ $dados['nomes'][$chave] ?? '—' }}</span></span>
                                @endforeach
                                @if (collect($dados['dias'])->sum(fn ($d) => $d['partes']['outros']) > 0)
                                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background: #cbd5e1"></span>Outros</span>
                                @endif
                            </div>

                            <div class="flex gap-3">
                                <div class="flex h-60 flex-col justify-between text-right text-[11px] tabular-nums text-texto-fraco" aria-hidden="true">
                                    <span class="-mt-1.5">{{ $topoHoras }}h</span>
                                    <span>{{ rtrim(rtrim(number_format($topoHoras / 2, 1, ',', ''), '0'), ',') }}h</span>
                                    <span class="-mb-1.5">0h</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="relative flex h-60 items-end gap-1 border-b border-borda sm:gap-2" role="img" aria-label="Horas por dia">
                                        <div class="pointer-events-none absolute inset-x-0 top-0 border-t border-dashed border-borda"></div>
                                        <div class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-borda"></div>
                                        @foreach ($dados['dias'] as $d)
                                            @php
                                                $diaTexto = $d['dia']->toDateString();
                                                $dica = ['dia' => ucfirst($d['dia']->translatedFormat('D, d/m')), 'total' => Horas::hm($d['total'])];
                                            @endphp
                                            <a href="{{ $ligacao(['periodo' => 'datas', 'de' => $diaTexto, 'ate' => $diaTexto]) }}" wire:navigate
                                               class="group relative flex h-full min-w-0 flex-1 flex-col-reverse rounded-t outline-none hover:bg-fundo/60 focus-visible:ring-2 focus-visible:ring-verde-500"
                                               aria-label="{{ $dica['dia'] }}: {{ $dica['total'] }}"
                                               @mouseenter="dica = @js($dica)" @focus="dica = @js($dica)" @mouseleave="dica = null" @blur="dica = null">
                                                @foreach ($d['partes'] as $chave => $segundos)
                                                    @continue($segundos === 0)
                                                    <div class="w-full last:rounded-t-[4px] group-hover:opacity-90 [&:not(:first-child)]:mb-[2px]"
                                                         style="height: {{ $segundos * 100 / $topoSeg }}%; background: {{ $corDe($chave) }}"
                                                         title="{{ $dados['nomes'][$chave] ?? '—' }}: {{ Horas::hm($segundos) }}"></div>
                                                @endforeach
                                            </a>
                                        @endforeach
                                    </div>
                                    <div class="mt-2 flex gap-1 text-center text-[11px] sm:gap-2" aria-hidden="true">
                                        @foreach ($dados['dias'] as $i => $d)
                                            <div class="min-w-0 flex-1 whitespace-nowrap">
                                                @if (! $mes)
                                                    <div class="tabular-nums {{ $d['total'] ? 'text-texto-forte' : 'text-texto-fraco' }}">{{ Horas::hm($d['total']) }}</div>
                                                    <div class="text-texto-medio"><span class="hidden sm:inline">{{ ucfirst($d['dia']->translatedFormat('D, d/m')) }}</span><span class="sm:hidden">{{ $d['dia']->format('d') }}</span></div>
                                                @elseif ($i % 3 === 0)
                                                    <div class="text-texto-medio">{{ $d['dia']->format('d') }}</div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </section>

                {{-- Distribuição --}}
                <section class="cartao flex min-w-0 flex-col overflow-hidden">
                    <header class="flex h-12 items-center border-b border-borda bg-fundo/80 px-5">
                        <h2 class="text-xs font-medium text-texto-medio">Por {{ mb_strtolower($agrupamentos[$agrupar], 'UTF-8') }}</h2>
                    </header>

                    @if ($fatias->isEmpty())
                        <div class="flex flex-1 items-center justify-center">
                            <x-estado-vazio icone="camadas" titulo="Nada para mostrar" class="py-12" />
                        </div>
                    @else
                        <div class="flex flex-1 flex-col justify-center gap-5 p-5">
                            <svg viewBox="0 0 180 180" class="mx-auto h-36 w-36 shrink-0 -rotate-90" role="img" aria-label="Distribuição por {{ mb_strtolower($agrupamentos[$agrupar], 'UTF-8') }}">
                                <circle cx="90" cy="90" r="{{ $raio }}" fill="none" stroke="#f1f5f9" stroke-width="22" />
                                @php $desvio = 0; @endphp
                                @foreach ($fatias as $f)
                                    @php
                                        $comprimento = $f['segundos'] * $perimetro / $somaFatias;
                                        $visivel = $fatias->count() > 1 ? max(0, $comprimento - 2) : $comprimento;
                                    @endphp
                                    <circle cx="90" cy="90" r="{{ $raio }}" fill="none" stroke="{{ $corDe($f['chave']) }}" stroke-width="22"
                                        stroke-dasharray="{{ $visivel }} {{ $perimetro - $visivel }}" stroke-dashoffset="{{ -$desvio }}">
                                        <title>{{ $f['nome'] }}: {{ Horas::hm($f['segundos']) }} ({{ $pct($f['percentagem']) }})</title>
                                    </circle>
                                    @php $desvio += $comprimento; @endphp
                                @endforeach
                            </svg>
                            <ul class="divide-y divide-borda text-sm">
                                @foreach ($fatias as $f)
                                    @php $url = $ligacaoGrupo($f['chave']); @endphp
                                    <li class="flex items-center gap-2.5 py-1.5">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $corDe($f['chave']) }}"></span>
                                        @if ($url)
                                            <a href="{{ $url }}" wire:navigate class="min-w-0 flex-1 truncate text-texto-forte hover:text-verde-700 hover:underline" title="{{ $f['nome'] }}">{{ $f['nome'] }}</a>
                                        @else
                                            <span class="min-w-0 flex-1 truncate text-texto-forte" title="{{ $f['nome'] }}">{{ $f['nome'] }}</span>
                                        @endif
                                        <span class="tabular-nums text-texto-forte">{{ Horas::hm($f['segundos']) }}</span>
                                        <span class="w-12 text-right text-xs tabular-nums text-texto-medio">{{ $pct($f['percentagem']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>

                {{-- Atividades mais registadas --}}
                <section class="cartao min-w-0 overflow-hidden {{ $comEquipa ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                    <header class="flex h-12 items-center justify-between border-b border-borda bg-fundo/80 px-5">
                        <h2 class="text-xs font-medium text-texto-medio">Atividades mais registadas</h2>
                        <select wire:model.live="top" class="rounded-md border-0 bg-transparent py-0 pl-2 pr-7 text-xs font-medium text-texto-forte focus:ring-2 focus:ring-verde-500" aria-label="Quantas">
                            <option value="10">Top 10</option>
                            <option value="20">Top 20</option>
                        </select>
                    </header>
                    @if ($atividades === [])
                        <x-estado-vazio icone="lista" titulo="Sem atividades" class="py-10" />
                    @else
                        <ol class="grid grid-cols-1 gap-x-10 px-5 py-1 {{ $comEquipa ? '' : 'md:grid-flow-col md:grid-cols-2 md:[grid-template-rows:repeat(var(--linhas),auto)]' }}" style="--linhas: {{ $linhasAtividades }}">
                            @foreach ($atividades as $i => $a)
                                @php
                                    $url = $ligacao(['descricao' => $a['descricao'], 'projetos' => [$a['projeto_id'] ?? 0]]);
                                @endphp
                                <li class="flex items-center gap-4 py-3 {{ $loop->last ? '' : 'border-b border-borda' }} {{ ! $comEquipa && ($i + 1) % $linhasAtividades === 0 ? 'md:border-b-0' : '' }}">
                                    <span class="w-5 shrink-0 text-right text-xs tabular-nums text-texto-fraco">{{ $i + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-baseline justify-between gap-3">
                                            <a href="{{ $url }}" wire:navigate class="truncate text-sm hover:text-verde-700 hover:underline {{ $a['descricao'] === '' ? 'text-texto-fraco' : 'text-texto-forte' }}" title="{{ $a['descricao'] }}">{{ $a['descricao'] ?: 'Sem descrição' }}</a>
                                            <span class="shrink-0 text-sm font-medium tabular-nums text-texto-forte">{{ Horas::hm($a['segundos']) }}</span>
                                        </div>
                                        <div class="mt-1 flex items-center gap-3">
                                            <span class="min-w-0 flex-1 truncate text-xs text-texto-fraco">{{ $a['detalhe'] ?: '—' }}</span>
                                            <span class="hidden h-1 w-24 shrink-0 overflow-hidden rounded-full bg-fundo sm:block" aria-hidden="true">
                                                <span class="block h-full rounded-full bg-verde-500" style="width: {{ $a['segundos'] * 100 / $maximoAtividade }}%"></span>
                                            </span>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                {{-- Atividade da equipa --}}
                @if ($comEquipa)
                    <section class="cartao min-w-0 overflow-hidden">
                        <header class="flex h-12 items-center justify-between border-b border-borda bg-fundo/80 px-5">
                            <h2 class="text-xs font-medium text-texto-medio">Atividade da equipa</h2>
                            @php $aRegistar = collect($equipa)->filter(fn ($m) => $m['aCorrer'])->count(); @endphp
                            @if ($aRegistar)
                                <span class="inline-flex items-center gap-1.5 text-xs text-verde-700"><span class="h-2 w-2 animate-pulse rounded-full bg-verde-500"></span>{{ $aRegistar }} a registar</span>
                            @endif
                        </header>
                        <ul class="divide-y divide-borda">
                            @foreach ($equipa as $m)
                                <li class="flex items-start gap-3 px-5 py-3">
                                    <x-avatar :nome="$m['nome']" tom="claro" class="mt-0.5 h-8 w-8 text-[10px]" />
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-baseline justify-between gap-3">
                                            <a href="{{ $ligacao(['membros' => [$m['id']]]) }}" wire:navigate class="truncate text-sm font-medium text-texto-forte hover:text-verde-700 hover:underline">{{ $m['nome'] }}</a>
                                            <span class="shrink-0 text-sm tabular-nums {{ $m['segundos'] ? 'text-texto-forte' : 'text-texto-fraco' }}">{{ Horas::hm($m['segundos']) }}</span>
                                        </div>
                                        @if ($m['aCorrer'])
                                            <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-verde-700">
                                                <span class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-verde-500"></span>
                                                <span class="truncate" title="{{ $m['aCorrer']['descricao'] }}">{{ $m['aCorrer']['descricao'] }}@if ($m['aCorrer']['projeto']) · {{ $m['aCorrer']['projeto'] }}@endif</span>
                                                <span class="shrink-0 tabular-nums text-texto-fraco">desde {{ $m['aCorrer']['desde']->format('H:i') }}</span>
                                            </div>
                                        @elseif ($m['ultimo'])
                                            <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-texto-fraco">
                                                <span class="truncate" title="{{ $m['ultimo']['descricao'] }}">{{ $m['ultimo']['descricao'] }}@if ($m['ultimo']['projeto']) · {{ $m['ultimo']['projeto'] }}@endif</span>
                                                <span class="shrink-0 tabular-nums">{{ ucfirst($m['ultimo']['quando']->translatedFormat('D, d/m')) }}</span>
                                            </div>
                                        @else
                                            <div class="mt-0.5 text-xs text-texto-fraco">—</div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        </div>
    </main>
</div>

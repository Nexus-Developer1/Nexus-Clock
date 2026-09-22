@use('App\Services\Tempos\PainelTempos')
@use('App\Support\Dinheiro')
@use('App\Support\Horas')
@use('Illuminate\Support\Js')

@php
    // Cores: as do Painel (validadas); faturável verde e não faturável cinzento.
    $paleta = ['#16a34a', '#2a78d6', '#eb6834', '#7c3aed', '#eda100'];
    $topo = collect($dados['grupos'])->filter(fn ($g) => $g['chave'] !== '')->take(5)->pluck('chave')->values()->all();
    $corGrupo = fn (string $chave) => in_array($chave, $topo, true) ? $paleta[array_search($chave, $topo, true)] : '#cbd5e1';
    $corSerie = fn (string $serie) => match ($serie) {
        'faturavel' => '#16a34a',
        'nao_faturavel' => '#94a3b8',
        default => $corGrupo($serie),
    };

    $topoHoras = max(1, (int) ceil($dados['maximo'] / 3600));
    $topoSeg = $topoHoras * 3600;
    $muitas = count($dados['barras']) > 12;
    $pct = fn (int $s) => $dados['total'] ? str_replace('.', ',', (string) round($s * 100 / $dados['total'], 1)).'%' : '0%';

    // Anel: os 5 maiores + resto.
    $fatias = collect($dados['grupos'])->filter(fn ($g) => in_array($g['chave'], $topo, true))->values();
    $resto = collect($dados['grupos'])->reject(fn ($g) => in_array($g['chave'], $topo, true));
    if ($resto->sum('segundos') > 0) {
        $fatias->push(['chave' => 'outros', 'nome' => $resto->count() === 1 ? $resto->first()['nome'] : 'Outros', 'segundos' => (int) $resto->sum('segundos')]);
    }
    $raio = 70;
    $perimetro = 2 * M_PI * $raio;
    $somaFatias = max(1, (int) $fatias->sum('segundos'));
@endphp

<div>
    @unless ($partilhado)
        <x-topbar :breadcrumb="['Suporte', 'Relatórios', 'Resumo']" />
    @endunless

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            @if ($partilhado)
                <x-cabecalho-pagina :titulo="$partilhado->nome" />
                <p class="mt-1 text-sm text-texto-medio">Resumo · partilhado por {{ $partilhado->autor->nome }}</p>
            @else
                <x-cabecalho-pagina titulo="Relatórios" />
            @endif

            @include('livewire.relatorios._topo', ['atual' => 'relatorios.resumo'])

            @unless ($partilhado)
                @include('livewire.relatorios._filtros')
            @endunless

            {{-- Totais e gráfico --}}
            <section class="cartao relative z-10 mt-6 overflow-hidden" x-data="{ dica: null }">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-borda bg-fundo/80 px-5 py-3">
                    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                        <div><span class="text-xs text-texto-medio">Total</span> <span class="ml-1 text-xl font-semibold tabular-nums text-texto-forte">{{ PainelTempos::hms($dados['total']) }}</span></div>
                        <div><span class="text-xs text-texto-medio">Faturável</span> <span class="ml-1 text-sm font-medium tabular-nums text-texto-forte">{{ PainelTempos::hms($dados['faturavel']) }}</span></div>
                        @if ($comValor)
                            <div><span class="text-xs text-texto-medio">{{ $rotuloValor }}</span> <span class="ml-1 text-sm font-medium tabular-nums {{ $valorTotal < 0 ? 'text-perigo-600' : 'text-texto-forte' }}">{{ Dinheiro::formatar($valorTotal) }}</span></div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2 print:hidden">
                        <div class="text-xs" x-show="dica" x-cloak aria-live="polite">
                            <span class="font-medium text-texto-forte" x-text="dica?.dia"></span>
                            <span class="ml-1 tabular-nums text-texto-medio" x-text="dica?.total"></span>
                        </div>
                        <select wire:model.live="cor" class="campo-select campo-mini w-auto" aria-label="Cores do gráfico">
                            <option value="faturabilidade">Faturabilidade</option>
                            <option value="grupo">Por {{ mb_strtolower($agrupamentos[$agrupar1], 'UTF-8') }}</option>
                        </select>
                        @if ($podeVerEquipa && ! $partilhado)
                            <select wire:model.live="mostrarValor" class="campo-select campo-mini w-auto" aria-label="Mostrar valor">
                                <option value="faturavel">Valor faturável</option>
                                <option value="custo">Custo</option>
                                <option value="lucro">Lucro</option>
                                <option value="nao">Sem valores</option>
                            </select>
                        @endif
                    </div>
                </header>

                <div class="p-5">
                    <div class="mb-4 flex min-h-[1rem] flex-wrap items-center gap-x-4 gap-y-1 text-xs text-texto-medio">
                        @if ($dados['total'] > 0)
                            @foreach (array_merge($dados['series'], ['outros']) as $serie)
                                @continue(collect($dados['barras'])->sum(fn ($b) => $b['partes'][$serie] ?? 0) === 0)
                                <span class="inline-flex min-w-0 items-center gap-1.5"><span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $corSerie($serie) }}"></span><span class="max-w-[12rem] truncate">{{ $dados['nomes'][$serie] ?? '—' }}</span></span>
                            @endforeach
                        @endif
                    </div>
                    <div class="flex gap-3">
                        <div class="flex h-56 flex-col justify-between text-right text-[11px] tabular-nums text-texto-fraco" aria-hidden="true">
                            <span class="-mt-1.5">{{ $topoHoras }}h</span>
                            <span>{{ rtrim(rtrim(number_format($topoHoras / 2, 1, ',', ''), '0'), ',') }}h</span>
                            <span class="-mb-1.5">0h</span>
                        </div>
                        <div class="relative min-w-0 flex-1">
                            <div class="relative flex h-56 items-end gap-1 border-b border-borda sm:gap-2" role="img" aria-label="Horas por {{ $dados['mensal'] ? 'mês' : 'dia' }}">
                                <div class="pointer-events-none absolute inset-x-0 top-0 border-t border-dashed border-borda"></div>
                                <div class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-borda"></div>
                                @foreach ($dados['barras'] as $b)
                                    @php $dica = ['dia' => $b['dica'], 'total' => PainelTempos::hms($b['total'])]; @endphp
                                    <div class="group relative flex h-full min-w-0 flex-1 flex-col-reverse outline-none focus-visible:ring-2 focus-visible:ring-verde-500" tabindex="0"
                                         @mouseenter="dica = @js($dica)" @focus="dica = @js($dica)" @mouseleave="dica = null" @blur="dica = null">
                                        @foreach ($b['partes'] as $serie => $segundos)
                                            @continue($segundos === 0)
                                            <div class="w-full last:rounded-t-[4px] group-hover:opacity-90 [&:not(:first-child)]:mb-[2px]"
                                                 style="height: {{ $segundos * 100 / $topoSeg }}%; background: {{ $corSerie($serie) }}"
                                                 title="{{ $dados['nomes'][$serie] ?? '—' }}: {{ Horas::hm($segundos) }}"></div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                            @if ($dados['total'] === 0)
                                <div class="pointer-events-none absolute inset-x-0 top-0 flex h-56 items-center justify-center">
                                    <x-estado-vazio icone="grafico" titulo="Sem dados" class="py-0" />
                                </div>
                            @endif
                            <div class="mt-2 flex gap-1 text-center text-[11px] sm:gap-2" aria-hidden="true">
                                @foreach ($dados['barras'] as $i => $b)
                                    <div class="min-w-0 flex-1 whitespace-nowrap">
                                        @if (! $muitas)
                                            <div class="hidden tabular-nums text-texto-forte sm:block">{{ PainelTempos::hms($b['total']) }}</div>
                                            <div class="text-texto-medio"><span class="hidden sm:inline">{{ $b['rotulo'] }}</span><span class="sm:hidden">{{ mb_substr($b['rotulo'], 0, 3) }}</span></div>
                                        @elseif ($i % 3 === 0)
                                            <div class="text-texto-medio">{{ $dados['mensal'] ? $b['rotulo'] : mb_substr($b['rotulo'], -5, 2) }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Tabela agrupada e distribuição --}}
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <section class="cartao min-w-0 overflow-hidden lg:col-span-2" x-data="{ abertos: [] }">
                    <header class="flex flex-wrap items-center gap-2 border-b border-borda bg-fundo/80 px-5 py-2.5">
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
                        @if ($agrupar1 === 'projeto')
                            <label class="ml-auto inline-flex cursor-pointer items-center gap-2 text-xs text-texto-medio print:hidden">
                                <input type="checkbox" wire:model.live="estimativa" class="peer sr-only">
                                <span class="relative h-4 w-7 rounded-full bg-slate-300 transition after:absolute after:left-0.5 after:top-0.5 after:h-3 after:w-3 after:rounded-full after:bg-white after:transition peer-checked:bg-verde-600 peer-checked:after:translate-x-3 peer-focus-visible:ring-2 peer-focus-visible:ring-verde-500"></span>
                                Mostrar estimativa
                            </label>
                        @endif
                    </header>

                    @if ($grupos === [])
                        <x-estado-vazio icone="lista" titulo="Sem dados" class="py-16" />
                    @else
                        <div class="relative overflow-x-auto">
                            <table class="tabela min-w-[520px] [&_td]:px-3 [&_th]:px-3">
                                <thead>
                                    <tr>
                                        <th>
                                            <span class="flex items-center gap-2">
                                                @if ($agrupar2 !== '')
                                                    <button type="button" class="botao-icone h-6 w-6 print:hidden" aria-label="Abrir ou fechar todos"
                                                        @click="abertos = abertos.length ? [] : @js(collect($grupos)->pluck('chave')->all())">
                                                        <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 transition" x-bind:class="abertos.length ? 'rotate-90' : ''" />
                                                    </button>
                                                @endif
                                                <button type="button" wire:click="ordenarPor('titulo')" class="hover:text-texto-forte">Título {{ ltrim($ordem, '-') === 'titulo' ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '' }}</button>
                                            </span>
                                        </th>
                                        @if ($estimativas !== null)<th class="w-40">Estimativa</th>@endif
                                        <th class="w-32"><button type="button" wire:click="ordenarPor('duracao')" class="block w-full text-right hover:text-texto-forte">Duração {{ ltrim($ordem, '-') === 'duracao' ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '' }}</button></th>
                                        @if ($comValor)
                                            <th class="w-36"><button type="button" wire:click="ordenarPor('valor')" class="block w-full text-right hover:text-texto-forte">{{ $rotuloValor }} {{ ltrim($ordem, '-') === 'valor' ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '' }}</button></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($grupos as $g)
                                        <tr wire:key="grupo-{{ $agrupar1 }}-{{ $g['chave'] }}" class="{{ $agrupar2 !== '' ? 'cursor-pointer' : '' }}"
                                            @if ($agrupar2 !== '') @click="abertos.includes(@js($g['chave'])) ? abertos = abertos.filter(c => c !== @js($g['chave'])) : abertos.push(@js($g['chave']))" @endif>
                                            <td>
                                                <div class="flex min-w-0 items-center gap-2">
                                                    @if ($agrupar2 !== '')
                                                        <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 text-texto-fraco transition print:hidden" x-bind:class="abertos.includes({{ Js::from($g['chave']) }}) ? 'rotate-90' : ''" />
                                                    @endif
                                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $corGrupo($g['chave']) }}" aria-hidden="true"></span>
                                                    <span class="truncate font-medium {{ $g['chave'] === '' ? 'text-texto-medio' : 'text-texto-forte' }}" title="{{ $g['nome'] }}">{{ $g['nome'] }}</span>
                                                    <span class="shrink-0 text-xs tabular-nums text-texto-fraco">{{ $pct($g['segundos']) }}</span>
                                                    @if ($agrupar2 !== '')<span class="shrink-0 rounded-full bg-fundo px-1.5 text-[11px] text-texto-medio">{{ count($g['filhos']) }}</span>@endif
                                                </div>
                                            </td>
                                            @if ($estimativas !== null)
                                                <td>
                                                    @php $est = $estimativas[(int) $g['chave']] ?? null; @endphp
                                                    @if ($est)
                                                        @php $p = round($g['segundos'] * 100 / $est); @endphp
                                                        <div class="flex items-center gap-2" title="{{ Horas::hm($g['segundos']) }} de {{ Horas::hm($est) }}">
                                                            <span class="h-1.5 w-16 overflow-hidden rounded-full bg-fundo"><span class="block h-full rounded-full {{ $p > 100 ? 'bg-perigo-500' : 'bg-verde-500' }}" style="width: {{ min(100, $p) }}%"></span></span>
                                                            <span class="text-xs tabular-nums text-texto-medio">{{ $p }}% de {{ Horas::hm($est) }}</span>
                                                        </div>
                                                    @else
                                                        <span class="text-texto-fraco">—</span>
                                                    @endif
                                                </td>
                                            @endif
                                            <td class="text-right font-medium tabular-nums text-texto-forte">{{ PainelTempos::hms($g['segundos']) }}</td>
                                            @if ($comValor)
                                                <td class="text-right tabular-nums text-texto-forte">{{ Dinheiro::formatar($this->valorDe($g)) }}</td>
                                            @endif
                                        </tr>
                                        @foreach ($this->ordenarFilhos($g['filhos']) as $f)
                                            <tr wire:key="filho-{{ $agrupar1 }}-{{ $g['chave'] }}-{{ $agrupar2 }}-{{ $f['chave'] }}" x-show="abertos.includes({{ Js::from($g['chave']) }})" x-cloak class="bg-fundo/40 print:!table-row">
                                                <td class="!pl-14">
                                                    <span class="block truncate {{ $f['chave'] === '' ? 'text-texto-fraco' : 'text-texto-medio' }}" title="{{ $f['nome'] }}">{{ $f['nome'] }}</span>
                                                </td>
                                                @if ($estimativas !== null)<td></td>@endif
                                                <td class="text-right tabular-nums text-texto-medio">{{ PainelTempos::hms($f['segundos']) }}</td>
                                                @if ($comValor)
                                                    <td class="text-right tabular-nums text-texto-medio">{{ Dinheiro::formatar($this->valorDe($f)) }}</td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                <section class="cartao flex min-w-0 flex-col overflow-hidden">
                    <header class="flex h-[49px] items-center border-b border-borda bg-fundo/80 px-5">
                        <h2 class="text-xs font-medium text-texto-medio">Por {{ mb_strtolower($agrupamentos[$agrupar1], 'UTF-8') }}</h2>
                    </header>
                    @if ($fatias->isEmpty())
                        <div class="flex flex-1 items-center justify-center">
                            <x-estado-vazio icone="camadas" titulo="Nada para mostrar" class="py-12" />
                        </div>
                    @else
                        <div class="flex flex-1 flex-col gap-5 p-5">
                            <svg viewBox="0 0 180 180" class="mx-auto h-40 w-40 shrink-0 -rotate-90" role="img" aria-label="Distribuição por {{ mb_strtolower($agrupamentos[$agrupar1], 'UTF-8') }}">
                                <circle cx="90" cy="90" r="{{ $raio }}" fill="none" stroke="#f1f5f9" stroke-width="22" />
                                @php $desvio = 0; @endphp
                                @foreach ($fatias as $f)
                                    @php
                                        $comprimento = $f['segundos'] * $perimetro / $somaFatias;
                                        $visivel = $fatias->count() > 1 ? max(0, $comprimento - 2) : $comprimento;
                                    @endphp
                                    <circle cx="90" cy="90" r="{{ $raio }}" fill="none" stroke="{{ $f['chave'] === 'outros' ? '#cbd5e1' : $corGrupo($f['chave']) }}" stroke-width="22"
                                        stroke-dasharray="{{ $visivel }} {{ $perimetro - $visivel }}" stroke-dashoffset="{{ -$desvio }}">
                                        <title>{{ $f['nome'] }}: {{ Horas::hm($f['segundos']) }}</title>
                                    </circle>
                                    @php $desvio += $comprimento; @endphp
                                @endforeach
                            </svg>
                            <ul class="divide-y divide-borda text-sm">
                                @foreach ($fatias as $f)
                                    <li class="flex items-center gap-2.5 py-1.5">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $f['chave'] === 'outros' ? '#cbd5e1' : $corGrupo($f['chave']) }}"></span>
                                        <span class="min-w-0 flex-1 truncate text-texto-forte" title="{{ $f['nome'] }}">{{ $f['nome'] }}</span>
                                        <span class="tabular-nums text-texto-forte">{{ Horas::hm($f['segundos']) }}</span>
                                        <span class="w-12 text-right text-xs tabular-nums text-texto-medio">{{ $pct($f['segundos']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </main>

    {{-- Partilhar --}}
    @if ($partilhaAberta)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="fecharPartilha" role="dialog" aria-modal="true" aria-labelledby="titulo-partilha">
            <div class="absolute inset-0" wire:click="fecharPartilha"></div>
            <form wire:submit="guardarPartilha" class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl" x-data x-init="$nextTick(() => $refs.nome?.focus())">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-partilha" class="text-lg font-semibold text-texto-forte">Partilhar relatório</h2>
                    <button type="button" wire:click="fecharPartilha" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>

                @if ($linkCriado)
                    <div class="space-y-4 px-6 py-5" x-data="{ copiado: false }">
                        <div class="flex items-center gap-2 rounded-xl border border-verde-200 bg-verde-50 px-4 py-3 text-sm text-verde-800"><x-icone nome="visto" traco="2" /> Link criado.</div>
                        <div class="flex gap-2">
                            <input type="text" readonly value="{{ $linkCriado }}" x-ref="link" @focus="$el.select()" class="campo-input min-w-0 flex-1 text-sm" aria-label="Link do relatório">
                            <button type="button" class="botao-secundario shrink-0"
                                @click="navigator.clipboard?.writeText($refs.link.value); $refs.link.select(); copiado = true; setTimeout(() => copiado = false, 2000)">
                                <x-icone nome="duplicar" /> <span x-text="copiado ? 'Copiado' : 'Copiar'">Copiar</span>
                            </button>
                        </div>
                        <p class="text-sm"><a href="{{ route('relatorios.partilhados') }}" wire:navigate class="font-medium text-verde-700 hover:underline">Ver em Partilhados</a></p>
                    </div>
                    <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                        <a href="{{ $linkCriado }}" target="_blank" rel="noopener" class="botao-secundario"><x-icone nome="seta-canto" /> Abrir</a>
                        <button type="button" wire:click="fecharPartilha" class="botao-primario">Fechar</button>
                    </footer>
                @else
                    <div class="space-y-5 px-6 py-5">
                        <div>
                            <label class="campo-label" for="partilha-nome">Nome do relatório <span class="text-perigo-500">*</span></label>
                            <input id="partilha-nome" x-ref="nome" type="text" wire:model="partilha.nome" maxlength="250" class="campo-input {{ $errors->has('partilha.nome') ? '!border-perigo-500' : '' }}">
                            @error('partilha.nome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="campo-label" for="partilha-visibilidade">Visibilidade</label>
                            <select id="partilha-visibilidade" wire:model.live="partilha.publico" class="campo-select">
                                <option value="1">Público — qualquer pessoa com o link</option>
                                <option value="0">Privado — só quem tem acesso ao Suporte</option>
                            </select>
                        </div>

                        @php
                            $interruptores = [];
                            if ($tipo !== 'datas') {
                                $interruptores['sempre_atual'] = 'Abrir sempre em «'.match ($tipo) { 'mes' => 'Este mês', 'ano' => 'Este ano', default => 'Esta semana' }.'»';
                            }
                            $interruptores['bloquear_datas'] = 'Bloquear datas';
                            $interruptores['email_ativo'] = 'Enviar por email';
                        @endphp
                        <div class="space-y-3">
                            @foreach ($interruptores as $campo => $rotulo)
                                <label class="flex cursor-pointer items-center gap-3 text-sm text-texto-forte" @if ($campo === 'bloquear_datas') title="Quem abre o link não pode mudar o período." @endif>
                                    <input type="checkbox" wire:model.live="partilha.{{ $campo }}" class="peer sr-only">
                                    <span class="relative h-5 w-9 shrink-0 rounded-full bg-slate-300 transition after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition peer-checked:bg-verde-600 peer-checked:after:translate-x-4 peer-focus-visible:ring-2 peer-focus-visible:ring-verde-500"></span>
                                    {{ $rotulo }}
                                </label>
                            @endforeach
                        </div>

                        @if ($partilha['email_ativo'] ?? false)
                            <div class="space-y-4 rounded-xl border border-borda bg-fundo/50 p-4">
                                <div>
                                    <label class="campo-label" for="partilha-emails">Para</label>
                                    <input id="partilha-emails" type="text" wire:model="partilha.email_destinatarios" class="campo-input" placeholder="Emails separados por vírgulas">
                                    @error('partilha.email_destinatarios') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                </div>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_8rem]">
                                    <div>
                                        <label class="campo-label" for="partilha-frequencia">Quando</label>
                                        <select id="partilha-frequencia" wire:model="partilha.email_frequencia" class="campo-select">
                                            @foreach ($frequencias as $valor => $rotulo)
                                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="campo-label" for="partilha-hora">À hora</label>
                                        <select id="partilha-hora" wire:model="partilha.email_hora" class="campo-select">
                                            @for ($h = 0; $h < 24; $h++)
                                                <option value="{{ $h }}">{{ sprintf('%02d:00', $h) }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                        <button type="button" wire:click="fecharPartilha" class="botao-secundario">Cancelar</button>
                        <button type="submit" class="botao-primario"><x-icone nome="partilhar" /> Criar link</button>
                    </footer>
                @endif
            </form>
        </div>
    @endif
</div>

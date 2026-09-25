@use('App\Models\MembroEquipa')
@use('App\Services\Tempos\PainelTempos')
@use('App\Support\Dinheiro')
@use('App\Support\Horas')

@php
    $m = $membro;
    $semHoras = $m->limitado || ! $m->utilizador_id;
    $pctFaturavel = $mesSeg > 0 ? round($mesFaturavel * 100 / $mesSeg) : null;
    $emCurso = $atribuicoes->filter(fn ($a) => $a->de->lte($hoje))->count();
@endphp

<div>
    <x-topbar :breadcrumb="[['label' => 'Suporte'], ['label' => 'Equipa', 'url' => route($m->limitado ? 'equipa.limitados' : 'equipa')], $m->nomeVisivel()]" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-center gap-4">
                    <x-avatar :nome="$m->nomeVisivel()" tom="claro" class="h-12 w-12 text-sm" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $m->nomeVisivel() }}</h1>
                            @if ($m->utilizador_id === auth()->id())<span class="text-texto-fraco">(você)</span>@endif
                            <span class="etiqueta bg-verde-50 text-verde-700">{{ $m->papel->rotulo() }}</span>
                            @if ($m->limitado)<span class="etiqueta bg-slate-100 text-texto-medio">Limitado</span>@endif
                        </div>
                        @if ($m->emailVisivel())
                            <a href="mailto:{{ $m->emailVisivel() }}" class="mt-1 block text-sm text-texto-medio hover:text-texto-forte hover:underline">{{ $m->emailVisivel() }}</a>
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($verRegistos)
                        <a href="{{ route('relatorios.detalhado', ['membros' => [$m->utilizador_id], 'periodo' => 'mes']) }}" wire:navigate class="botao-secundario"><x-icone nome="lista" /> Ver registos</a>
                    @endif
                    <a href="{{ route($m->limitado ? 'equipa.limitados' : 'equipa') }}" wire:navigate class="botao-secundario"><x-icone nome="seta-esq" traco="2" /> Equipa</a>
                </div>
            </div>

            @if ($semHoras)
                <div class="cartao mt-6 px-5 py-4 text-sm text-texto-medio">
                    Membro limitado: está na equipa (grupos, atribuições), mas não tem conta na suite e não regista horas.
                </div>
            @else
                {{-- Indicadores --}}
                <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <x-kpi rotulo="Esta semana" :valor="PainelTempos::hms($semanaSeg)" icone="relogio" tom="verde"
                        :detalhe="$capacidadeSemanal ? 'de '.Horas::hm($capacidadeSemanal).' de capacidade' : 'Sem capacidade definida'" />
                    <x-kpi :rotulo="$rotuloMes" :valor="PainelTempos::hms($mesSeg)" icone="calendario"
                        :detalhe="$porProjeto->count().' '.($porProjeto->count() === 1 ? 'projeto' : 'projetos')" />
                    <x-kpi rotulo="Faturáveis no mês" :valor="PainelTempos::hms($mesFaturavel)" icone="faturacao"
                        :detalhe="$pctFaturavel !== null ? $pctFaturavel.'% do mês' : 'Sem horas'" />
                    <x-kpi rotulo="Atribuições" :valor="$atribuicoes->count()" icone="contrato"
                        :detalhe="$emCurso.' em curso · '.($atribuicoes->count() - $emCurso).' futuras'" />
                </div>
            @endif

            <div class="mt-6 grid gap-6 lg:grid-cols-3">
                {{-- Dados --}}
                <section class="cartao lg:col-span-1">
                    <h2 class="border-b border-borda px-5 py-4 text-base font-semibold text-texto-forte">Dados</h2>
                    <dl class="space-y-4 px-5 py-5 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Papel</dt>
                            <dd class="mt-0.5 text-texto-forte">{{ $m->papel->rotulo() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Grupos</dt>
                            <dd class="mt-1 flex flex-wrap gap-1.5">
                                @forelse ($m->grupos as $g)
                                    <span class="etiqueta bg-fundo text-texto-forte">{{ $g->nome }}</span>
                                @empty
                                    <span class="text-texto-fraco">—</span>
                                @endforelse
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Gestor de equipa</dt>
                            <dd class="mt-0.5 text-texto-forte">{{ $m->gestor?->nomeVisivel() ?? '—' }}</dd>
                        </div>
                        <div class="flex gap-8">
                            <div>
                                <dt class="text-xs font-medium text-texto-medio">Dias de trabalho</dt>
                                <dd class="mt-0.5 text-texto-forte">{{ $m->rotuloDiasTrabalho() }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-texto-medio">Capacidade diária</dt>
                                <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $m->capacidade_diaria_seg ? Horas::hm($m->capacidade_diaria_seg).' h' : '—' }}</dd>
                            </div>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">A semana começa à</dt>
                            <dd class="mt-0.5 text-texto-forte">{{ mb_strtolower(MembroEquipa::DIAS[$m->inicio_semana] ?? '—') }}</dd>
                        </div>
                        @if ($podeGerir)
                            <div class="flex gap-8">
                                <div>
                                    <dt class="text-xs font-medium text-texto-medio">Taxa faturável</dt>
                                    <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $taxaFaturavel !== null ? Dinheiro::formatar($taxaFaturavel).'/h' : '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-texto-medio">Taxa de custo</dt>
                                    <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $taxaCusto !== null ? Dinheiro::formatar($taxaCusto).'/h' : '—' }}</dd>
                                </div>
                            </div>
                        @endif
                    </dl>
                </section>

                @unless ($semHoras)
                    <div class="space-y-6 lg:col-span-2">
                        {{-- Projetos do mês --}}
                        <section class="cartao">
                            <h2 class="border-b border-borda px-5 py-4 text-base font-semibold text-texto-forte">Projetos em {{ mb_strtolower($rotuloMes) }}</h2>
                            @if ($porProjeto->isEmpty())
                                <x-estado-vazio icone="contrato" titulo="Sem horas este mês" />
                            @else
                                <div class="overflow-x-auto rounded-b-2xl">
                                    <table class="tabela min-w-[520px]">
                                        <thead>
                                            <tr>
                                                <th>Projeto</th>
                                                <th class="w-32 text-right">Horas</th>
                                                <th class="w-32 text-right">Faturáveis</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($porProjeto as $l)
                                                <tr>
                                                    <td>
                                                        @if ($l->projeto)
                                                            <div class="flex items-center gap-2.5">
                                                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $l->projeto->cor }}" aria-hidden="true"></span>
                                                                <a href="{{ route('projetos.ver', $l->projeto) }}" wire:navigate class="truncate font-medium text-texto-forte hover:underline">{{ $l->projeto->nome }}</a>
                                                                @if ($l->projeto->cliente)<span class="truncate text-xs text-texto-medio">· {{ $l->projeto->cliente->nome }}</span>@endif
                                                            </div>
                                                        @elseif ($l->chave === 'privados')
                                                            <span class="inline-flex items-center gap-1.5 text-texto-medio"><x-icone nome="cadeado" class="h-3.5 w-3.5" /> Outros projetos (privados)</span>
                                                        @else
                                                            <span class="text-texto-fraco">Sem projeto</span>
                                                        @endif
                                                    </td>
                                                    <td class="text-right tabular-nums text-texto-forte">{{ PainelTempos::hms($l->total) }}</td>
                                                    <td class="text-right tabular-nums text-texto-medio">{{ PainelTempos::hms($l->faturavel) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </section>

                        {{-- Atribuições em curso e futuras --}}
                        <section class="cartao">
                            <h2 class="border-b border-borda px-5 py-4 text-base font-semibold text-texto-forte">Atribuições</h2>
                            @if ($atribuicoes->isEmpty())
                                <x-estado-vazio icone="calendario" titulo="Sem atribuições em curso ou futuras" />
                            @else
                                <ul class="divide-y divide-borda">
                                    @foreach ($atribuicoes as $a)
                                        @php $podeVerProjeto = $a->projeto && in_array($a->projeto->id, $visiveis, true); @endphp
                                        <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-5 py-3 text-sm">
                                            <div class="flex min-w-0 items-center gap-2.5">
                                                @if ($podeVerProjeto)
                                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $a->projeto->cor }}" aria-hidden="true"></span>
                                                    <a href="{{ route('projetos.ver', $a->projeto) }}" wire:navigate class="truncate font-medium text-texto-forte hover:underline">{{ $a->projeto->nome }}</a>
                                                @else
                                                    <span class="inline-flex items-center gap-1.5 text-texto-medio"><x-icone nome="cadeado" class="h-3.5 w-3.5" /> Projeto privado</span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-4 tabular-nums text-texto-medio">
                                                <span>{{ $a->de->format('d/m') }} – {{ $a->ate->format('d/m/Y') }}</span>
                                                <span class="text-texto-forte">{{ Horas::hm($a->horas_dia_seg) }} h/dia</span>
                                                @if ($a->de->lte($hoje))<span class="etiqueta bg-verde-50 text-verde-700">Em curso</span>@endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    </div>
                @endunless
            </div>
        </div>
    </main>
</div>

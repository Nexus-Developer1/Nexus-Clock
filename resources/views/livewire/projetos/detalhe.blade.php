@use('App\Services\Tempos\PainelTempos')
@use('App\Support\Dinheiro')
@use('App\Support\Horas')

@php
    $p = $projeto;
    $pct = fn (float $v) => str_replace('.', ',', rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.')).'%';
    $pctFaturavel = $total > 0 ? round($faturavel * 100 / $total) : null;
    $clienteComPagina = $p->cliente && ! $p->cliente->trashed();
@endphp

<div>
    <x-topbar :breadcrumb="[['label' => 'Suporte'], ['label' => 'Projetos', 'url' => route('projetos')], $p->nome]" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="h-3.5 w-3.5 shrink-0 rounded-full" style="background: {{ $p->cor }}" aria-hidden="true"></span>
                        <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $p->nome }}</h1>
                        @if ($p->estaArquivado())<span class="etiqueta bg-slate-100 text-texto-medio">Arquivado</span>@endif
                        @unless ($p->publico)<span class="etiqueta bg-slate-100 text-texto-medio"><x-icone nome="cadeado" class="h-3 w-3" /> Privado</span>@endunless
                        @unless ($p->faturavel)<span class="etiqueta bg-slate-100 text-texto-medio">Não faturável</span>@endunless
                    </div>
                    <p class="mt-1 text-sm text-texto-medio">
                        @if ($clienteComPagina)
                            <a href="{{ route('clientes.ver', $p->cliente) }}" wire:navigate class="hover:text-texto-forte hover:underline">{{ $p->cliente->nome }}</a>
                        @elseif ($p->cliente)
                            {{ $p->cliente->nome }}
                        @else
                            Sem cliente
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('relatorios.detalhado', ['projetos' => [$p->id], 'periodo' => 'ano']) }}" wire:navigate class="botao-secundario"><x-icone nome="lista" /> Ver registos</a>
                    <a href="{{ route('projetos') }}" wire:navigate class="botao-secundario"><x-icone nome="seta-esq" traco="2" /> Projetos</a>
                </div>
            </div>

            {{-- Indicadores --}}
            <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-kpi rotulo="Horas registadas" :valor="PainelTempos::hms($total)" icone="relogio" tom="verde" :detalhe="$porPessoa->count().' '.($porPessoa->count() === 1 ? 'pessoa' : 'pessoas')" />
                <x-kpi rotulo="Horas faturáveis" :valor="PainelTempos::hms($faturavel)" icone="faturacao"
                    :detalhe="! $p->faturavel ? 'Projeto não faturável' : ($pctFaturavel !== null ? $pctFaturavel.'% do total' : 'Sem horas')" />
                <x-kpi rotulo="Progresso" :valor="$progresso !== null ? $pct($progresso) : '—'" icone="grafico"
                    :tom="$progresso !== null && $progresso > 100 ? 'perigo' : 'normal'"
                    :detalhe="$p->estimativa_seg ? Horas::hm($total).' de '.Horas::hm($p->estimativa_seg).' estimadas' : 'Sem estimativa'" />
                <x-kpi rotulo="Despesas aprovadas" :valor="Dinheiro::formatar($despesasCent)" icone="euro"
                    :detalhe="$despesasPendentes ? $despesasPendentes.' '.($despesasPendentes === 1 ? 'pendente' : 'pendentes') : 'Nenhuma pendente'" />
            </div>

            <div class="mt-6 grid gap-6 lg:grid-cols-3">
                {{-- Dados --}}
                <section class="cartao lg:col-span-1">
                    <h2 class="border-b border-borda px-5 py-4 text-base font-semibold text-texto-forte">Dados</h2>
                    <dl class="space-y-4 px-5 py-5 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Acesso</dt>
                            <dd class="mt-0.5 text-texto-forte">{{ $p->publico ? 'Público — toda a equipa' : 'Privado — só os membros' }}</dd>
                        </div>
                        @if (! $p->publico)
                            <div>
                                <dt class="text-xs font-medium text-texto-medio">Membros</dt>
                                <dd class="mt-1 flex flex-wrap gap-1.5">
                                    @forelse ($membros as $nome)
                                        <span class="etiqueta bg-fundo text-texto-forte">{{ $nome }}</span>
                                    @empty
                                        <span class="text-texto-fraco">Ninguém (só quem gere)</span>
                                    @endforelse
                                </dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Faturável</dt>
                            <dd class="mt-0.5 text-texto-forte">{{ $p->faturavel ? 'Sim' : 'Não' }}</dd>
                        </div>
                        @if ($podeGerir)
                            <div class="flex gap-8">
                                <div>
                                    <dt class="text-xs font-medium text-texto-medio">Taxa</dt>
                                    <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $p->taxa_cent !== null ? Dinheiro::formatar($p->taxa_cent).'/h' : 'A de cada membro' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-texto-medio">Valor faturável</dt>
                                    <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $p->faturavel ? Dinheiro::formatar($valorCent) : '—' }}</dd>
                                </div>
                            </div>
                        @endif
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Estimativa</dt>
                            <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $p->estimativa_seg ? Horas::hm($p->estimativa_seg).' h' : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Nota</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-texto-forte">{{ $p->nota ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Criado</dt>
                            <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $p->created_at?->setTimezone(config('tempos.fuso'))->format('d/m/Y') ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <div class="space-y-6 lg:col-span-2">
                    {{-- Equipa: quem registou horas --}}
                    <section class="cartao">
                        <h2 class="border-b border-borda px-5 py-4 text-base font-semibold text-texto-forte">Equipa</h2>
                        @if ($porPessoa->isEmpty())
                            <x-estado-vazio icone="pessoas" titulo="Ainda ninguém registou horas neste projeto" />
                        @else
                            <div class="overflow-x-auto rounded-b-2xl">
                                <table class="tabela min-w-[520px]">
                                    <thead>
                                        <tr>
                                            <th>Pessoa</th>
                                            <th class="w-32 text-right">Horas</th>
                                            <th class="w-32 text-right">Faturáveis</th>
                                            <th class="w-36">Último registo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($porPessoa as $l)
                                            <tr>
                                                <td class="font-medium text-texto-forte">{{ $l->nome }}</td>
                                                <td class="text-right tabular-nums text-texto-forte">{{ PainelTempos::hms($l->total) }}</td>
                                                <td class="text-right tabular-nums text-texto-medio">{{ PainelTempos::hms($l->faturavel) }}</td>
                                                <td class="tabular-nums text-texto-medio">{{ $l->ultimo->format('d/m/Y') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>

                    {{-- Atribuições em curso e futuras --}}
                    <section class="cartao">
                        <div class="flex items-center justify-between border-b border-borda px-5 py-4">
                            <h2 class="text-base font-semibold text-texto-forte">Atribuições</h2>
                            <a href="{{ route('relatorios.atribuicoes', ['projetos' => [$p->id]]) }}" wire:navigate class="text-sm text-texto-medio hover:text-texto-forte hover:underline">Ver no relatório</a>
                        </div>
                        @if ($atribuicoes->isEmpty())
                            <x-estado-vazio icone="calendario" titulo="Sem atribuições em curso ou futuras" />
                        @else
                            <ul class="divide-y divide-borda">
                                @foreach ($atribuicoes as $a)
                                    <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-5 py-3 text-sm">
                                        <div class="min-w-0">
                                            <div class="font-medium text-texto-forte">{{ $a->utilizador?->nome ?? '—' }}</div>
                                            @if ($a->nota)<div class="truncate text-xs text-texto-fraco">{{ $a->nota }}</div>@endif
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
            </div>
        </div>
    </main>
</div>

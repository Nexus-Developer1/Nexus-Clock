@use('App\Services\Tempos\PainelTempos')
@use('App\Support\Dinheiro')

@php
    $ativos = $projetos->reject(fn ($p) => $p->estaArquivado())->count();
    $arquivados = $projetos->count() - $ativos;
    $pctFaturavel = $totalSeg > 0 ? round($faturavelSeg * 100 / $totalSeg) : null;
@endphp

<div>
    <x-topbar :breadcrumb="[['label' => 'Suporte'], ['label' => 'Clientes', 'url' => route('clientes')], $cliente->nome]" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-cabecalho-pagina :titulo="$cliente->nome">
                <x-slot:acoes>
                    @if ($cliente->estaArquivado())
                        <span class="etiqueta bg-slate-100 text-texto-medio">Arquivado</span>
                    @endif
                    <a href="{{ route('clientes') }}" wire:navigate class="botao-secundario"><x-icone nome="seta-esq" traco="2" /> Clientes</a>
                    @if ($podeGerir)
                        <button type="button" wire:click="abrirFormulario" class="botao-primario"><x-icone nome="lapis" traco="2" /> Alterar</button>
                    @endif
                </x-slot:acoes>
            </x-cabecalho-pagina>

            {{-- Totais (só dos projetos que quem está a ver pode ver) --}}
            <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-kpi rotulo="Horas registadas" :valor="PainelTempos::hms($totalSeg)" icone="relogio" tom="verde" />
                <x-kpi rotulo="Horas faturáveis" :valor="PainelTempos::hms($faturavelSeg)" :detalhe="$pctFaturavel !== null ? $pctFaturavel.'% do total' : 'Sem horas'" icone="faturacao" />
                <x-kpi rotulo="Projetos ativos" :valor="$ativos" :detalhe="$arquivados ? $arquivados.' '.($arquivados === 1 ? 'arquivado' : 'arquivados') : 'Nenhum arquivado'" icone="contrato" />
                <x-kpi rotulo="Despesas aprovadas" :valor="Dinheiro::formatar($despesasCent)" :detalhe="$despesasPendentes ? $despesasPendentes.' '.($despesasPendentes === 1 ? 'pendente' : 'pendentes') : 'Nenhuma pendente'" icone="euro" />
            </div>

            <div class="mt-6 grid gap-6 lg:grid-cols-3">
                {{-- Dados do cliente --}}
                <section class="cartao lg:col-span-1">
                    <h2 class="border-b border-borda px-5 py-4 text-base font-semibold text-texto-forte">Dados</h2>
                    <dl class="space-y-4 px-5 py-5 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Email</dt>
                            <dd class="mt-0.5 text-texto-forte">@if ($cliente->email)<a href="mailto:{{ $cliente->email }}" class="hover:underline">{{ $cliente->email }}</a>@else — @endif</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Emails em cópia</dt>
                            <dd class="mt-0.5 text-texto-forte">
                                @forelse ($cliente->emails_cc as $cc)
                                    <div><a href="mailto:{{ $cc }}" class="hover:underline">{{ $cc }}</a></div>
                                @empty
                                    —
                                @endforelse
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Morada</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-texto-forte">{{ $cliente->morada ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Nota</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-texto-forte">{{ $cliente->nota ?: '—' }}</dd>
                        </div>
                        <div class="flex gap-8">
                            <div>
                                <dt class="text-xs font-medium text-texto-medio">Moeda</dt>
                                <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $cliente->moeda }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-texto-medio">Criado</dt>
                                <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $cliente->created_at?->setTimezone(config('tempos.fuso'))->format('d/m/Y') ?? '—' }}</dd>
                            </div>
                        </div>
                    </dl>
                </section>

                {{-- Projetos do cliente --}}
                <section class="cartao lg:col-span-2">
                    <div class="flex items-center justify-between border-b border-borda px-5 py-4">
                        <h2 class="text-base font-semibold text-texto-forte">Projetos</h2>
                        <a href="{{ route('projetos') }}" wire:navigate class="text-sm text-texto-medio hover:text-texto-forte hover:underline">Ver todos</a>
                    </div>

                    @if ($projetos->isEmpty())
                        <x-estado-vazio icone="contrato" titulo="Sem projetos deste cliente" />
                    @else
                        <div class="overflow-x-auto rounded-b-2xl">
                            <table class="tabela min-w-[560px]">
                                <thead>
                                    <tr>
                                        <th>Projeto</th>
                                        <th class="w-28">Acesso</th>
                                        <th class="w-32 text-right">Horas</th>
                                        <th class="w-32 text-right">Faturáveis</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($projetos as $p)
                                        @php $h = $horas->get($p->id); @endphp
                                        <tr wire:key="projeto-{{ $p->id }}">
                                            <td>
                                                <div class="flex items-center gap-2.5">
                                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $p->cor }}" aria-hidden="true"></span>
                                                    <span class="truncate font-medium {{ $p->estaArquivado() ? 'text-texto-medio' : 'text-texto-forte' }}">{{ $p->nome }}</span>
                                                    @if ($p->estaArquivado())
                                                        <span class="etiqueta bg-slate-100 text-texto-medio">Arquivado</span>
                                                    @endif
                                                    @unless ($p->faturavel)
                                                        <span class="etiqueta bg-slate-100 text-texto-medio">Não faturável</span>
                                                    @endunless
                                                </div>
                                            </td>
                                            <td class="text-texto-medio">{{ $p->publico ? 'Público' : 'Privado' }}</td>
                                            <td class="text-right tabular-nums text-texto-forte">{{ PainelTempos::hms((int) ($h->total_seg ?? 0)) }}</td>
                                            <td class="text-right tabular-nums text-texto-medio">{{ PainelTempos::hms((int) ($h->faturavel_seg ?? 0)) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </main>

    {{-- Janela de alteração (a mesma da listagem) --}}
    @if ($editar)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="fecharFormulario" role="dialog" aria-modal="true" aria-labelledby="titulo-editar-cliente">
            <div class="absolute inset-0" wire:click="fecharFormulario"></div>
            <form wire:submit="guardar" class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl" x-data x-init="$nextTick(() => $refs.nome.focus())">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-editar-cliente" class="text-lg font-semibold text-texto-forte">Alterar cliente</h2>
                    <button type="button" wire:click="fecharFormulario" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>

                <div class="space-y-4 px-6 py-5">
                    @include('livewire.partials.campos-cliente')
                </div>

                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="fecharFormulario" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Guardar</button>
                </footer>
            </form>
        </div>
    @endif
</div>

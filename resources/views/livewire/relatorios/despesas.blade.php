@use('App\Support\Dinheiro')

@php
    $seta = fn (string $campo) => ltrim($ordem, '-') === $campo ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '';
    $classesEstado = [
        'pendente' => 'bg-aviso-100 text-aviso-500',
        'aprovada' => 'bg-verde-50 text-verde-700',
        'rejeitada' => 'bg-perigo-100 text-perigo-600',
    ];
    $euAgora = auth()->user();
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Relatórios', 'Despesas']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-cabecalho-pagina titulo="Relatórios">
                {{-- Tudo na linha do título: período e exportar, e as ações da página (antes numa linha solta). --}}
                <x-slot:acoes>
                    @include('livewire.relatorios._topo', ['atual' => 'relatorios.despesas', 'recibos' => true])
                    @if ($gere)
                        <button type="button" wire:click="$set('categoriasAbertas', true)" class="botao-secundario print:hidden"><x-icone nome="etiqueta" /> Categorias</button>
                    @endif
                    <button type="button" wire:click="nova" class="botao-primario print:hidden"><x-icone nome="mais" traco="2" /> Nova despesa</button>
                </x-slot:acoes>
            </x-cabecalho-pagina>

            {{-- Filtros num cartão como o da Nexus Infra (e o dos outros relatórios): pesquisa na nota em
                 cima, a toda a largura; por baixo, colunas iguais, cada uma com o seu rótulo. --}}
            <section class="cartao relative z-20 mt-6 p-4 sm:p-5 print:hidden">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="relative min-w-[14rem] flex-1">
                        <x-icone nome="pesquisa" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" />
                        <input type="search" wire:model.live.debounce.400ms="descricao" class="campo-input pl-10" placeholder="Pesquisar na nota..." aria-label="Nota contém">
                    </div>
                    @if ($filtrosAtivos > 0)
                        <button type="button" wire:click="limparFiltros" class="px-2 text-sm font-medium text-verde-700 hover:underline">Limpar filtros</button>
                    @endif
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    @if ($podeVerEquipa)
                        <x-filtro-multiplo campo rotulo="Equipa" modelo="membros" :opcoes="$opcoes['membros']" :selecionados="$membros" class="min-w-[11rem] flex-1" />
                    @endif
                    <x-filtro-multiplo campo rotulo="Cliente" modelo="clientes" :opcoes="$opcoes['clientes']" :selecionados="$clientes" class="min-w-[11rem] flex-1" />
                    <x-filtro-multiplo campo rotulo="Projeto" modelo="projetos" :opcoes="$opcoes['projetos']" :selecionados="$projetos" class="min-w-[11rem] flex-1" />
                    <x-filtro-multiplo campo rotulo="Categoria" modelo="categorias" :opcoes="$opcoes['categorias']" :selecionados="$categorias" class="min-w-[11rem] flex-1" />
                    <div class="min-w-[11rem] flex-1">
                        <label for="filtro-situacao" class="campo-label">Estado</label>
                        <select id="filtro-situacao" wire:model.live="situacao" class="campo-select {{ $situacao ? '!border-verde-300 !bg-verde-50 text-verde-800' : '' }}">
                            <option value="">Todos</option>
                            @foreach ($estados as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </section>

            @if ($erro)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <section class="cartao mt-6">
                {{-- Totais --}}
                <header class="flex flex-wrap items-center justify-between gap-3 rounded-t-2xl border-b border-borda bg-fundo/80 px-5 py-3">
                    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                        <div><span class="text-xs text-texto-medio">Total</span> <span class="ml-1 text-xl font-semibold tabular-nums text-texto-forte">{{ Dinheiro::formatar($totais['total']) }}</span></div>
                        <div><span class="text-xs text-texto-medio">Faturável</span> <span class="ml-1 text-sm font-medium tabular-nums text-texto-forte">{{ Dinheiro::formatar($totais['faturavel']) }}</span></div>
                        <div class="text-xs text-texto-fraco">{{ $totais['registos'] }} {{ $totais['registos'] === 1 ? 'despesa' : 'despesas' }}@if ($totais['pendentes']) · {{ $totais['pendentes'] }} {{ $totais['pendentes'] === 1 ? 'pendente' : 'pendentes' }}@endif</div>
                    </div>
                    @if ($totais['recibos'] > 0)
                        <button type="button" wire:click="descarregarRecibos" class="pilula-botao py-1.5 text-xs print:hidden"><x-icone nome="descarregar" class="h-3.5 w-3.5" /> Descarregar recibos ({{ $totais['recibos'] }})</button>
                    @endif
                </header>

                @if ($pagina->isEmpty())
                    {{-- Sem botão aqui: o «Nova despesa» da linha do título chega (pedido de 2026-09-23). --}}
                    <x-estado-vazio icone="euro" :titulo="$filtrosAtivos > 0 ? 'Nenhuma despesa com estes filtros' : 'Sem despesas'" class="py-16" />
                @else
                    <div class="relative overflow-x-auto">
                        <table class="tabela min-w-[900px] [&_td]:px-3 [&_th]:px-3">
                            <thead>
                                <tr>
                                    <th class="w-28"><button type="button" wire:click="ordenarPor('data')" class="hover:text-texto-forte">Data {{ $seta('data') }}</button></th>
                                    @if ($podeVerEquipa)
                                        <th class="w-40"><button type="button" wire:click="ordenarPor('membro')" class="hover:text-texto-forte">Membro {{ $seta('membro') }}</button></th>
                                    @endif
                                    <th><button type="button" wire:click="ordenarPor('projeto')" class="hover:text-texto-forte">Projeto / Nota {{ $seta('projeto') }}</button></th>
                                    <th class="w-40"><button type="button" wire:click="ordenarPor('categoria')" class="hover:text-texto-forte">Categoria {{ $seta('categoria') }}</button></th>
                                    <th class="w-28"><button type="button" wire:click="ordenarPor('valor')" class="block w-full text-right hover:text-texto-forte">Valor {{ $seta('valor') }}</button></th>
                                    <th class="w-28"><button type="button" wire:click="ordenarPor('estado')" class="hover:text-texto-forte">Estado {{ $seta('estado') }}</button></th>
                                    <th class="w-24 print:hidden"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pagina as $d)
                                    <tr wire:key="despesa-{{ $d->id }}" wire:click="ver({{ $d->id }})" class="cursor-pointer" title="Ver a despesa">
                                        <td class="whitespace-nowrap tabular-nums text-texto-forte">{{ $d->data->format('d/m/Y') }}</td>
                                        @if ($podeVerEquipa)
                                            <td class="max-w-[10rem] truncate text-texto-medio">{{ $d->utilizador?->nome ?? '—' }}</td>
                                        @endif
                                        <td class="max-w-0">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $d->projeto?->cor ?? '#cbd5e1' }}"></span>
                                                <span class="truncate {{ $d->projeto ? 'font-medium text-texto-forte' : 'text-texto-fraco' }}">{{ $d->projeto?->nome ?? 'Sem projeto' }}</span>
                                                @if ($d->projeto?->cliente)<span class="truncate text-xs text-texto-medio">· {{ $d->projeto->cliente->nome }}</span>@endif
                                            </div>
                                            @if ($d->nota || $d->motivo_rejeicao)
                                                <div class="mt-0.5 truncate text-xs {{ $d->estado === 'rejeitada' ? 'text-perigo-600' : 'text-texto-fraco' }}" title="{{ $d->estado === 'rejeitada' ? $d->motivo_rejeicao : $d->nota }}">
                                                    {{ $d->estado === 'rejeitada' && $d->motivo_rejeicao ? 'Rejeitada: '.$d->motivo_rejeicao : $d->nota }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="max-w-[10rem] truncate text-texto-medio">{{ $d->categoria?->nome ?? '—' }}</td>
                                        <td class="text-right">
                                            <div class="font-medium tabular-nums text-texto-forte">{{ Dinheiro::formatar($d->valor_cent) }}</div>
                                            @if ($d->faturavel)<div class="text-[11px] text-verde-700">Faturável</div>@endif
                                        </td>
                                        <td><span class="etiqueta {{ $classesEstado[$d->estado] }}">{{ $estados[$d->estado] }}</span></td>
                                        {{-- @click.stop: o recibo e o menu não abrem o detalhe da linha. --}}
                                        <td class="print:hidden" @click.stop>
                                            <div class="flex items-center justify-end gap-1">
                                                @if ($d->recibo_caminho)
                                                    <a href="{{ route('despesas.recibo', $d) }}" class="inline-flex h-8 w-8 items-center justify-center rounded-full text-texto-medio hover:bg-fundo hover:text-verde-700" title="Recibo: {{ $d->recibo_nome }}" aria-label="Descarregar recibo"><x-icone nome="descarregar" /></a>
                                                @endif
                                                <div class="relative" x-data="menuFlutuante('direita')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                    <button type="button" x-ref="botao" @click="alternar()" class="botao-icone h-8 w-8" aria-label="Opções da despesa de {{ $d->data->format('d/m') }}" :aria-expanded="aberto"><x-icone nome="mais-opcoes" /></button>
                                                    <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak x-transition.opacity class="fixed z-50 w-44 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
                                                        <button type="button" wire:click="ver({{ $d->id }})" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="olho" /> Ver detalhes</button>
                                                        @if ($gestor->podeAlterar($euAgora, $d))
                                                            <button type="button" wire:click="editar({{ $d->id }})" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="lapis" /> Alterar</button>
                                                        @endif
                                                        @if ($gere)
                                                            @if ($d->estado !== 'aprovada')
                                                                <button type="button" wire:click="aprovar({{ $d->id }})" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left text-verde-700 hover:bg-verde-50" role="menuitem"><x-icone nome="visto" /> Aprovar</button>
                                                            @endif
                                                            @if ($d->estado !== 'rejeitada')
                                                                <button type="button" wire:click="pedirRejeicao({{ $d->id }})" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="fechar" /> Rejeitar</button>
                                                            @endif
                                                            @if ($d->estado !== 'pendente')
                                                                <button type="button" wire:click="reabrir({{ $d->id }})" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="atualizar" /> Voltar a pendente</button>
                                                            @endif
                                                        @endif
                                                        @if ($gestor->podeAlterar($euAgora, $d))
                                                            <button type="button" wire:click="apagar({{ $d->id }})" wire:confirm="Apagar esta despesa?" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left text-perigo-600 hover:bg-perigo-100" role="menuitem"><x-icone nome="lixo" /> Apagar</button>
                                                        @endif
                                                        @unless ($gestor->podeAlterar($euAgora, $d) || $gere)
                                                            <span class="block px-4 py-2 text-texto-fraco">Aprovada: sem alterações</span>
                                                        @endunless
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($pagina->lastPage() > 1)
                        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-borda px-5 py-3 text-sm print:hidden">
                            <span class="tabular-nums text-texto-medio">{{ $pagina->firstItem() }}–{{ $pagina->lastItem() }} de {{ $pagina->total() }}</span>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="previousPage" @disabled($pagina->onFirstPage()) class="botao-icone h-8 w-8 disabled:opacity-40" aria-label="Página anterior"><x-icone nome="seta-esq" traco="2" /></button>
                                <span class="tabular-nums text-texto-medio">Página {{ $pagina->currentPage() }} de {{ $pagina->lastPage() }}</span>
                                <button type="button" wire:click="nextPage" @disabled(! $pagina->hasMorePages()) class="botao-icone h-8 w-8 disabled:opacity-40" aria-label="Página seguinte"><x-icone nome="seta-dir" traco="2" /></button>
                            </div>
                        </footer>
                    @endif
                @endif
            </section>
        </div>
    </main>

    {{-- Detalhe da despesa (só leitura; carregar na linha). Vem antes das janelas de alterar e de
         rejeitar para, abertas a partir daqui, ficarem por cima — e ao fechá-las volta-se ao detalhe. --}}
    @if ($emDetalhe)
        @php
            $det = $emDetalhe;
            $podeAlterarEsta = $gestor->podeAlterar($euAgora, $det);
            $local = fn ($data) => $data?->setTimezone(config('tempos.fuso'))->format('d/m/Y H:i');
        @endphp
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="fecharDetalhe" role="dialog" aria-modal="true" aria-labelledby="titulo-detalhe">
            <div class="absolute inset-0" wire:click="fecharDetalhe"></div>
            <div class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-borda px-6 py-4">
                    <div class="min-w-0">
                        <h2 id="titulo-detalhe" class="text-lg font-semibold text-texto-forte">Despesa de {{ $det->data->format('d/m/Y') }}</h2>
                        <p class="mt-0.5 truncate text-sm text-texto-medio">{{ $det->utilizador?->nome ?? '—' }}</p>
                    </div>
                    <button type="button" wire:click="fecharDetalhe" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>

                <div class="space-y-5 px-6 py-5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-3xl font-semibold tracking-tight tabular-nums text-texto-forte">{{ Dinheiro::formatar($det->valor_cent) }}</div>
                            <div class="mt-0.5 text-xs {{ $det->faturavel ? 'text-verde-700' : 'text-texto-fraco' }}">{{ $det->faturavel ? 'Faturável ao cliente' : 'Não faturável' }}</div>
                        </div>
                        <span class="etiqueta {{ $classesEstado[$det->estado] }}">{{ $estados[$det->estado] }}</span>
                    </div>

                    @if ($det->estado === 'rejeitada' && $det->motivo_rejeicao)
                        <div class="rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600">
                            <div class="font-medium">Motivo da rejeição</div>
                            <div class="mt-0.5 whitespace-pre-line">{{ $det->motivo_rejeicao }}</div>
                        </div>
                    @endif

                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                        <div class="col-span-2">
                            <dt class="text-xs font-medium text-texto-medio">Projeto</dt>
                            <dd class="mt-0.5 flex items-center gap-2 text-texto-forte">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $det->projeto?->cor ?? '#cbd5e1' }}" aria-hidden="true"></span>
                                <span>{{ $det->projeto?->nome ?? 'Sem projeto' }}@if ($det->projeto?->cliente)<span class="text-texto-medio"> · {{ $det->projeto->cliente->nome }}</span>@endif</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Categoria</dt>
                            <dd class="mt-0.5 text-texto-forte">{{ $det->categoria?->nome ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-texto-medio">Data</dt>
                            <dd class="mt-0.5 tabular-nums text-texto-forte">{{ $det->data->format('d/m/Y') }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-xs font-medium text-texto-medio">Nota</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-texto-forte">{{ $det->nota ?: '—' }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-xs font-medium text-texto-medio">Recibo</dt>
                            <dd class="mt-0.5">
                                @if ($det->recibo_caminho)
                                    <a href="{{ route('despesas.recibo', $det) }}" class="inline-flex max-w-full items-center gap-2 font-medium text-verde-700 hover:underline"><x-icone nome="descarregar" class="shrink-0" /> <span class="truncate">{{ $det->recibo_nome }}</span></a>
                                @else
                                    <span class="text-texto-fraco">Sem recibo</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <div class="space-y-1 border-t border-borda pt-4 text-xs text-texto-medio">
                        <div>Lançada por {{ $nomesRegisto[$det->criado_por] ?? '—' }} em {{ $local($det->created_at) }}</div>
                        @if ($det->decidido_em)
                            <div>{{ $det->estado === 'rejeitada' ? 'Rejeitada' : 'Aprovada' }} por {{ $det->decisor?->nome ?? '—' }} em {{ $local($det->decidido_em) }}</div>
                        @endif
                        @if ($det->alterado_por && $det->updated_at?->ne($det->created_at))
                            <div>Última alteração por {{ $nomesRegisto[$det->alterado_por] ?? '—' }} em {{ $local($det->updated_at) }}</div>
                        @endif
                    </div>
                </div>

                @if ($gere || $podeAlterarEsta)
                    <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-borda bg-fundo/50 px-6 py-4">
                        @if ($podeAlterarEsta)
                            <button type="button" wire:click="apagar({{ $det->id }})" wire:confirm="Apagar esta despesa?" class="botao-secundario mr-auto !text-perigo-600"><x-icone nome="lixo" /> Apagar</button>
                            <button type="button" wire:click="editar({{ $det->id }})" class="botao-secundario"><x-icone nome="lapis" /> Alterar</button>
                        @endif
                        @if ($gere)
                            @if ($det->estado !== 'pendente')
                                <button type="button" wire:click="reabrir({{ $det->id }})" class="botao-secundario"><x-icone nome="atualizar" /> Voltar a pendente</button>
                            @endif
                            @if ($det->estado !== 'rejeitada')
                                <button type="button" wire:click="pedirRejeicao({{ $det->id }})" class="botao-secundario"><x-icone nome="fechar" /> Rejeitar</button>
                            @endif
                            @if ($det->estado !== 'aprovada')
                                <button type="button" wire:click="aprovar({{ $det->id }})" class="botao-primario"><x-icone nome="visto" traco="2" /> Aprovar</button>
                            @endif
                        @endif
                    </footer>
                @endif
            </div>
        </div>
    @endif

    {{-- Nova / alterar despesa --}}
    @if ($editarId !== null)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="fecharFormulario" role="dialog" aria-modal="true" aria-labelledby="titulo-despesa">
            <div class="absolute inset-0" wire:click="fecharFormulario"></div>
            <form wire:submit="guardar" class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-despesa" class="text-lg font-semibold text-texto-forte">{{ $editarId === 0 ? 'Nova despesa' : 'Alterar despesa' }}</h2>
                    <button type="button" wire:click="fecharFormulario" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="space-y-4 px-6 py-5">
                    @error('formulario.geral') <div class="rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600">{{ $message }}</div> @enderror

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @if (count($membrosFormulario) > 1)
                            <div>
                                <label class="campo-label" for="despesa-membro">Membro</label>
                                <select id="despesa-membro" wire:model="formulario.utilizador_id" class="campo-select">
                                    @foreach ($membrosFormulario as $id => $nome)
                                        <option value="{{ $id }}">{{ $nome }}</option>
                                    @endforeach
                                </select>
                                @error('formulario.utilizador_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div class="{{ count($membrosFormulario) > 1 ? '' : 'sm:col-span-2' }}">
                            <label class="campo-label" for="despesa-data">Data <span class="text-perigo-500">*</span></label>
                            <input id="despesa-data" type="date" wire:model="formulario.data" class="campo-input">
                            @error('formulario.data') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="campo-label" for="despesa-projeto">Projeto</label>
                            <select id="despesa-projeto" wire:model="formulario.projeto_id" class="campo-select">
                                <option value="">Sem projeto</option>
                                @foreach ($projetosFormulario as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('formulario.projeto_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="campo-label" for="despesa-categoria">Categoria <span class="text-perigo-500">*</span></label>
                            <select id="despesa-categoria" wire:model="formulario.categoria_id" class="campo-select">
                                <option value="">—</option>
                                @foreach ($categoriasFormulario as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('formulario.categoria_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2">
                        <div>
                            <label class="campo-label" for="despesa-valor">Valor <span class="text-perigo-500">*</span></label>
                            <div class="relative">
                                <input id="despesa-valor" type="text" inputmode="decimal" wire:model="formulario.valor" class="campo-input pr-9 tabular-nums" placeholder="0,00">
                                <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-texto-fraco">€</span>
                            </div>
                            @error('formulario.valor') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex h-[46px] cursor-pointer items-center gap-3 text-sm text-texto-forte">
                            <input type="checkbox" wire:model="formulario.faturavel" class="peer sr-only">
                            <span class="relative h-5 w-9 shrink-0 rounded-full bg-slate-300 transition after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition peer-checked:bg-verde-600 peer-checked:after:translate-x-4 peer-focus-visible:ring-2 peer-focus-visible:ring-verde-500"></span>
                            Faturável ao cliente
                        </label>
                    </div>

                    <div>
                        <label class="campo-label" for="despesa-nota">Nota</label>
                        <textarea id="despesa-nota" wire:model="formulario.nota" rows="2" class="campo-input"></textarea>
                        @error('formulario.nota') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <span class="campo-label">Recibo</span>
                        @if ($emEdicao?->recibo_caminho && ! $retirarRecibo && ! $recibo)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-borda px-4 py-2.5 text-sm">
                                <a href="{{ route('despesas.recibo', $emEdicao) }}" class="inline-flex min-w-0 items-center gap-2 text-verde-700 hover:underline"><x-icone nome="descarregar" /> <span class="truncate">{{ $emEdicao->recibo_nome }}</span></a>
                                <button type="button" wire:click="$set('retirarRecibo', true)" class="shrink-0 text-xs font-medium text-perigo-600 hover:underline">Retirar</button>
                            </div>
                        @else
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-borda px-4 py-3 text-sm text-texto-medio transition hover:border-verde-300 hover:bg-verde-50/40">
                                <x-icone nome="descarregar" class="rotate-180 text-texto-fraco" />
                                <span class="min-w-0 flex-1 truncate">
                                    <span wire:loading.remove wire:target="recibo">{{ $recibo ? $recibo->getClientOriginalName() : 'Escolher ficheiro (PDF ou imagem, até 10 MB)' }}</span>
                                    <span wire:loading wire:target="recibo">A carregar…</span>
                                </span>
                                <input type="file" wire:model="recibo" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,application/pdf,image/*" class="sr-only">
                            </label>
                        @endif
                        @error('recibo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="fecharFormulario" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario" wire:loading.attr="disabled" wire:target="recibo"><x-icone nome="visto" traco="2" /> {{ $editarId === 0 ? 'Acrescentar' : 'Guardar' }}</button>
                </footer>
            </form>
        </div>
    @endif

    {{-- Rejeitar --}}
    @if ($rejeitarId)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="$set('rejeitarId', null)" role="dialog" aria-modal="true" aria-labelledby="titulo-rejeitar">
            <div class="absolute inset-0" wire:click="$set('rejeitarId', null)"></div>
            <form wire:submit="rejeitar" class="janela relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-rejeitar" class="text-lg font-semibold text-texto-forte">Rejeitar despesa</h2>
                    <button type="button" wire:click="$set('rejeitarId', null)" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="px-6 py-5">
                    <label class="campo-label" for="rejeitar-motivo">Motivo <span class="text-perigo-500">*</span></label>
                    <textarea id="rejeitar-motivo" wire:model="motivo" rows="3" class="campo-input"></textarea>
                    @error('motivo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                </div>
                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="$set('rejeitarId', null)" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-perigo"><x-icone nome="fechar" /> Rejeitar</button>
                </footer>
            </form>
        </div>
    @endif

    {{-- Categorias --}}
    @if ($categoriasAbertas && $gere)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="$set('categoriasAbertas', false)" role="dialog" aria-modal="true" aria-labelledby="titulo-categorias">
            <div class="absolute inset-0" wire:click="$set('categoriasAbertas', false)"></div>
            <div class="janela relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-categorias" class="text-lg font-semibold text-texto-forte">Categorias de despesa</h2>
                    <button type="button" wire:click="$set('categoriasAbertas', false)" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="px-6 py-5">
                    <form wire:submit="acrescentarCategoria" class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <input type="text" wire:model="novaCategoria" maxlength="100" class="campo-input campo-barra" placeholder="Nova categoria" aria-label="Nova categoria">
                            @error('novaCategoria') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="botao-primario"><x-icone nome="mais" traco="2" /> Acrescentar</button>
                    </form>
                    <ul class="mt-4 max-h-80 divide-y divide-borda overflow-y-auto rounded-xl border border-borda">
                        @foreach ($todasCategorias as $c)
                            <li wire:key="categoria-{{ $c->id }}" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                <span class="{{ $c->arquivada_em ? 'text-texto-fraco line-through' : 'text-texto-forte' }}">{{ $c->nome }}</span>
                                <button type="button" wire:click="alternarCategoria({{ $c->id }})" class="text-xs font-medium {{ $c->arquivada_em ? 'text-verde-700' : 'text-texto-medio' }} hover:underline">{{ $c->arquivada_em ? 'Restaurar' : 'Arquivar' }}</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif
</div>

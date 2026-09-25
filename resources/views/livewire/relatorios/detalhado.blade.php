@use('App\Livewire\Relatorios\Detalhado')
@use('App\Services\Tempos\PainelTempos')
@use('App\Support\Dinheiro')

@php
    $ids = $linhas->map(fn ($l) => (string) $l['r']->id)->all();
    $todosSelecionados = $ids !== [] && array_diff($ids, $selecionados) === [];
    $seta = fn (string $campo) => ltrim($ordem, '-') === $campo ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '';
    $hora = fn ($d) => $d?->setTimezone(config('tempos.fuso'))->format('H:i');
    $nSelecionados = count($selecionados);
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Relatórios', 'Detalhado']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-cabecalho-pagina titulo="Relatórios">
                <x-slot:acoes>
                    @include('livewire.relatorios._topo', ['atual' => 'relatorios.detalhado'])
                </x-slot:acoes>
            </x-cabecalho-pagina>

            @include('livewire.relatorios._filtros')

            {{-- Auditoria e acrescentar --}}
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
                <select wire:model.live="auditoria" class="campo-select campo-barra w-auto {{ $auditoria ? '!border-amber-300 !bg-amber-50 text-amber-800' : '' }}" aria-label="Auditoria de tempo">
                    <option value="">Auditoria de tempo</option>
                    @foreach ($auditorias as $valor => $rotulo)
                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="novo" class="botao-primario"><x-icone nome="mais" traco="2" /> Acrescentar tempo</button>
            </div>

            @if ($erro)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <section class="cartao mt-4">
                {{-- Totais --}}
                <header class="flex flex-wrap items-center justify-between gap-3 rounded-t-2xl border-b border-borda bg-fundo/80 px-5 py-3">
                    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                        <div><span class="text-xs text-texto-medio">Total</span> <span class="ml-1 text-xl font-semibold tabular-nums text-texto-forte">{{ PainelTempos::hms($totais['total']) }}</span></div>
                        <div><span class="text-xs text-texto-medio">Faturável</span> <span class="ml-1 text-sm font-medium tabular-nums text-texto-forte">{{ PainelTempos::hms($totais['faturavel']) }}</span></div>
                        @if ($comValor)
                            <div><span class="text-xs text-texto-medio">{{ $rotuloValor }}</span> <span class="ml-1 text-sm font-medium tabular-nums {{ $valorTotal < 0 ? 'text-perigo-600' : 'text-texto-forte' }}">{{ Dinheiro::formatar($valorTotal) }}</span></div>
                        @endif
                        <div class="text-xs text-texto-fraco">{{ $totais['registos'] }} {{ $totais['registos'] === 1 ? 'registo' : 'registos' }}</div>
                    </div>
                    @if ($podeVerEquipa)
                        <select wire:model.live="mostrarValor" class="campo-select campo-mini w-auto print:hidden" aria-label="Mostrar valor">
                            <option value="faturavel">Valor faturável</option>
                            <option value="custo">Custo</option>
                            <option value="lucro">Lucro</option>
                            <option value="nao">Sem valores</option>
                        </select>
                    @endif
                </header>

                {{-- Seleção e edição em massa --}}
                @if ($nSelecionados > 0)
                    <div class="border-b border-borda bg-verde-50/60 px-5 py-2.5 print:hidden">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-medium text-texto-forte">{{ $nSelecionados }} {{ $nSelecionados === 1 ? 'selecionado' : 'selecionados' }} <span class="font-normal tabular-nums text-texto-medio">· {{ PainelTempos::hms($segundosSelecionados) }}</span></span>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="$toggle('massaAberta')" class="pilula-botao py-1.5"><x-icone nome="lapis" /> Editar em massa</button>
                                <button type="button" wire:click="apagarMassa" wire:confirm="Apagar os registos selecionados?" class="pilula-botao py-1.5 hover:border-perigo-200 hover:bg-perigo-100 hover:text-perigo-600"><x-icone nome="lixo" /> Apagar</button>
                                <button type="button" wire:click="limparSelecao" class="px-2 text-sm text-texto-medio hover:text-texto-forte">Limpar</button>
                            </div>
                        </div>
                        @if ($massaAberta)
                            <form wire:submit="aplicarMassa" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <select wire:model="massaProjeto" class="campo-select campo-barra" aria-label="Projeto">
                                    <option value="">Projeto: manter</option>
                                    <option value="0">Sem projeto</option>
                                    @foreach ($projetosMassa as $id => $nome)
                                        <option value="{{ $id }}">{{ $nome }}</option>
                                    @endforeach
                                </select>
                                <select wire:model="massaFaturavel" class="campo-select campo-barra" aria-label="Faturável">
                                    <option value="">Faturável: manter</option>
                                    <option value="sim">Faturável</option>
                                    <option value="nao">Não faturável</option>
                                </select>
                                <input type="text" wire:model="massaAcrescentar" class="campo-input campo-barra" placeholder="Acrescentar etiquetas" aria-label="Acrescentar etiquetas">
                                <input type="text" wire:model="massaRetirar" class="campo-input campo-barra" placeholder="Retirar etiquetas" aria-label="Retirar etiquetas">
                                <button type="submit" class="botao-primario justify-center"><x-icone nome="visto" traco="2" /> Aplicar</button>
                            </form>
                        @endif
                    </div>
                @endif

                @if ($linhas->isEmpty())
                    <x-estado-vazio icone="lista" :titulo="$filtrosAtivos > 0 ? 'Nenhum registo com estes filtros' : 'Sem registos'" class="py-16" />
                @else
                    <div class="relative overflow-x-auto">
                        <table class="tabela min-w-[880px] [&_td]:px-3 [&_th]:px-3">
                            <thead>
                                <tr>
                                    <th>
                                        <span class="flex items-center gap-3">
                                            <input type="checkbox" @checked($todosSelecionados) wire:click="$set('selecionados', @js($todosSelecionados ? [] : array_values(array_unique(array_merge($selecionados, $ids)))))"
                                                class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500 print:hidden" aria-label="Selecionar a página">
                                            <button type="button" wire:click="ordenarPor('descricao')" class="hover:text-texto-forte">Descrição {{ $seta('descricao') }}</button>
                                        </span>
                                    </th>
                                    @if ($podeVerEquipa)
                                        <th class="w-40"><button type="button" wire:click="ordenarPor('membro')" class="hover:text-texto-forte">Membro {{ $seta('membro') }}</button></th>
                                    @endif
                                    <th class="w-36"><button type="button" wire:click="ordenarPor('data')" class="hover:text-texto-forte">Dia {{ $seta('data') }}</button></th>
                                    <th class="w-10"><span class="sr-only">Faturável</span></th>
                                    <th class="w-28"><button type="button" wire:click="ordenarPor('duracao')" class="block w-full text-right hover:text-texto-forte">Duração {{ $seta('duracao') }}</button></th>
                                    @if ($comValor)
                                        <th class="w-28"><button type="button" wire:click="ordenarPor('valor')" class="block w-full text-right hover:text-texto-forte">{{ $rotuloValor }} {{ $seta('valor') }}</button></th>
                                    @endif
                                    <th class="w-20 print:hidden"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($linhas as $l)
                                    @php
                                        $r = $l['r'];
                                        $horas = Detalhado::temHorasReais($r);
                                        $podeMexer = auth()->user()->can('update', $r);
                                    @endphp
                                    {{-- A linha abre o «Alterar» do registo; o projeto e o cliente abrem a página deles; caixa, links e menu fazem o que já faziam (notas §50). --}}
                                    <tr wire:key="registo-{{ $r->id }}" class="{{ in_array((string) $r->id, $selecionados, true) ? '!bg-verde-50/60' : '' }} {{ $podeMexer ? 'cursor-pointer' : '' }}"
                                        @if ($podeMexer) @click="$event.target.closest('a, button, input, select, textarea, label, [role=menu], [role=dialog]') || $wire.editar({{ $r->id }})" @endif>
                                        <td class="max-w-0">
                                            <div class="flex items-start gap-3">
                                                <input type="checkbox" wire:model.live="selecionados" value="{{ $r->id }}" class="mt-0.5 h-4 w-4 shrink-0 rounded border-borda text-verde-600 focus:ring-verde-500 print:hidden" aria-label="Selecionar registo de {{ $r->dia()->format('d/m') }}">
                                                <div class="min-w-0">
                                                    <div class="truncate {{ $r->descricao ? 'text-texto-forte' : 'text-texto-fraco' }}" title="{{ $r->descricao }}">{{ $r->descricao ?: 'Sem descrição' }}</div>
                                                    <div class="mt-0.5 flex min-w-0 items-center gap-2 overflow-hidden whitespace-nowrap text-xs">
                                                        <span class="inline-flex max-w-[45%] shrink-0 items-center gap-1.5 {{ $r->projeto ? 'font-medium text-texto-forte' : 'text-texto-fraco' }}">
                                                            <span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $r->projeto?->cor ?? '#cbd5e1' }}"></span>
                                                            @if ($r->projeto && isset($abreProjeto[$r->projeto->id]))
                                                                <a href="{{ route('projetos.ver', $r->projeto) }}" wire:navigate class="truncate hover:text-verde-700 hover:underline" title="{{ $r->projeto->nome }}">{{ $r->projeto->nome }}</a>
                                                            @else
                                                                <span class="truncate" title="{{ $r->projeto?->nome }}">{{ $r->projeto?->nome ?? 'Sem projeto' }}</span>
                                                            @endif
                                                        </span>
                                                        @if ($r->projeto?->cliente && isset($abreCliente[$r->projeto->cliente->id]))
                                                            <a href="{{ route('clientes.ver', $r->projeto->cliente) }}" wire:navigate class="min-w-0 truncate text-texto-medio hover:text-verde-700 hover:underline" title="{{ $r->projeto->cliente->nome }}">{{ $r->projeto->cliente->nome }}</a>
                                                        @else
                                                            <span class="min-w-0 truncate text-texto-medio" title="{{ $r->projeto?->cliente?->nome }}">{{ $r->projeto?->cliente?->nome ?? '—' }}</span>
                                                        @endif
                                                        @foreach ($r->etiquetas as $e)
                                                            <span class="shrink-0 rounded-full bg-fundo px-2 py-0.5 text-[11px] text-texto-medio">{{ $e }}</span>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        @if ($podeVerEquipa)
                                            <td class="max-w-[10rem] truncate text-texto-medio">{{ $r->tecnico?->nome ?? '—' }}</td>
                                        @endif
                                        <td class="whitespace-nowrap">
                                            <div class="tabular-nums text-texto-forte">{{ $r->dia()->format('d/m/Y') }}</div>
                                            @if ($horas)<div class="text-xs tabular-nums text-texto-fraco">{{ $hora($r->inicio) }} – {{ $hora($r->fim) }}</div>@endif
                                        </td>
                                        <td>
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full {{ $l['fat'] ? 'bg-verde-50 text-verde-700' : 'text-slate-300' }}" title="{{ $l['fat'] ? 'Faturável' : 'Não faturável' }}">
                                                <x-icone nome="euro" class="h-4 w-4" />
                                                <span class="sr-only">{{ $l['fat'] ? 'Faturável' : 'Não faturável' }}</span>
                                            </span>
                                        </td>
                                        <td class="text-right font-medium tabular-nums text-texto-forte">{{ PainelTempos::hms((int) $r->duracao_seg) }}</td>
                                        @if ($comValor)
                                            <td class="text-right tabular-nums {{ $l['valor'] ? 'text-texto-forte' : 'text-texto-fraco' }}">{{ Dinheiro::formatar($l['valor']) }}</td>
                                        @endif
                                        <td class="print:hidden">
                                            <div class="flex items-center justify-end gap-1">
                                                @if ($r->faturado_em)
                                                    <span class="inline-flex h-8 w-8 items-center justify-center text-texto-fraco" title="Faturado em {{ $r->faturado_em->setTimezone(config('tempos.fuso'))->format('d/m/Y') }}"><x-icone nome="cadeado" /></span>
                                                @elseif (! $podeMexer)
                                                    <span class="inline-flex h-8 w-8 items-center justify-center text-texto-fraco" title="Não pode ser alterado (semana entregue ou mês fechado)"><x-icone nome="cadeado" /></span>
                                                @endif
                                                <div class="relative" x-data="menuFlutuante('direita')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                    <button type="button" x-ref="botao" @click="alternar()" class="botao-icone h-8 w-8" aria-label="Opções do registo de {{ $r->dia()->format('d/m') }}" :aria-expanded="aberto"><x-icone nome="mais-opcoes" /></button>
                                                    <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak x-transition.opacity class="fixed z-50 w-44 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
                                                        @if ($podeMexer)
                                                            <button type="button" wire:click="editar({{ $r->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="lapis" /> Alterar</button>
                                                        @endif
                                                        <button type="button" wire:click="duplicar({{ $r->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="duplicar" /> Duplicar</button>
                                                        @if ($r->faturado_em && $podeAnular)
                                                            <button type="button" wire:click="pedirAnulacao({{ $r->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left text-perigo-600 hover:bg-perigo-100" role="menuitem"><x-icone nome="lixo" /> Anular</button>
                                                        @elseif ($podeMexer)
                                                            <button type="button" wire:click="apagar({{ $r->id }})" wire:confirm="Apagar este registo?" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left text-perigo-600 hover:bg-perigo-100" role="menuitem"><x-icone nome="lixo" /> Apagar</button>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Páginas --}}
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

    @include('livewire.partials.formulario-registo')

    {{-- Anular registo faturado --}}
    @if ($anularId)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="$set('anularId', null)" role="dialog" aria-modal="true" aria-labelledby="titulo-anular">
            <div class="absolute inset-0" wire:click="$set('anularId', null)"></div>
            <form wire:submit="anular" class="janela relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-anular" class="text-lg font-semibold text-texto-forte">Anular registo faturado</h2>
                    <button type="button" wire:click="$set('anularId', null)" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="px-6 py-5">
                    <label class="campo-label" for="anular-motivo">Motivo <span class="text-perigo-500">*</span></label>
                    <textarea id="anular-motivo" wire:model="motivo" rows="3" class="campo-input"></textarea>
                    @error('motivo') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                </div>
                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="$set('anularId', null)" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-perigo"><x-icone nome="lixo" /> Anular</button>
                </footer>
            </form>
        </div>
    @endif
</div>

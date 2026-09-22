@php
    $ids = $clientes->pluck('id')->map(fn ($id) => (string) $id)->all();
    $todosSelecionados = $ids !== [] && array_diff($ids, $selecionados) === [];
    $selecao = $clientes->whereIn('id', array_map('intval', $selecionados));
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Clientes']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-cabecalho-pagina titulo="Clientes">
                @if ($podeGerir)
                    <x-slot:acoes>
                        <a href="{{ route('clientes.novo') }}" wire:navigate class="botao-primario"><x-icone nome="mais" traco="2" /> Novo cliente</a>
                    </x-slot:acoes>
                @endif
            </x-cabecalho-pagina>

            @if ($erro)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <section class="cartao mt-6">
                {{-- Filtro e pesquisa --}}
                <div class="flex flex-wrap items-center gap-3 border-b border-borda p-4 sm:px-5">
                    <select wire:model.live="mostrar" class="campo-select campo-barra w-full sm:w-44" aria-label="Mostrar">
                        <option value="ativos">Ativos</option>
                        <option value="arquivados">Arquivados</option>
                        <option value="todos">Todos</option>
                    </select>
                    <div class="relative w-full sm:w-auto sm:min-w-[12rem] sm:flex-1">
                        <x-icone nome="pesquisa" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" />
                        <input type="search" wire:model.live.debounce.300ms="pesquisa" class="campo-input campo-barra pl-9" placeholder="Pesquisar" aria-label="Pesquisar pelo nome">
                    </div>
                </div>

                {{-- Seleção --}}
                @if ($podeGerir && $selecionados !== [])
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-borda bg-verde-50/60 px-5 py-2.5">
                        <span class="text-sm font-medium text-texto-forte">{{ count($selecionados) }} {{ count($selecionados) === 1 ? 'selecionado' : 'selecionados' }}</span>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($selecao->contains(fn ($c) => ! $c->estaArquivado()))
                                <button type="button" wire:click="arquivar" class="pilula-botao py-1.5"><x-icone nome="arquivo" /> Arquivar</button>
                            @endif
                            @if ($selecao->contains(fn ($c) => $c->estaArquivado()))
                                <button type="button" wire:click="restaurar" class="pilula-botao py-1.5"><x-icone nome="atualizar" /> Restaurar</button>
                                <button type="button" wire:click="apagar" wire:confirm="Apagar os clientes selecionados? Só se apagam clientes arquivados." class="pilula-botao py-1.5 hover:border-perigo-200 hover:bg-perigo-100 hover:text-perigo-600"><x-icone nome="lixo" /> Apagar</button>
                            @endif
                            <button type="button" wire:click="$set('selecionados', [])" class="px-2 text-sm text-texto-medio hover:text-texto-forte">Limpar</button>
                        </div>
                    </div>
                @endif

                @if ($clientes->isEmpty())
                    @if (trim($pesquisa) !== '')
                        <x-estado-vazio icone="pesquisa" titulo="Nenhum cliente com esse nome" />
                    @elseif ($mostrar === 'arquivados')
                        <x-estado-vazio icone="arquivo" titulo="Sem clientes arquivados" />
                    @else
                        <x-estado-vazio icone="pessoa-circulo" titulo="Ainda sem clientes">
                            @if ($podeGerir)
                                <a href="{{ route('clientes.novo') }}" wire:navigate class="botao-primario"><x-icone nome="mais" traco="2" /> Novo cliente</a>
                            @endif
                        </x-estado-vazio>
                    @endif
                @else
                    <div class="relative overflow-x-auto rounded-b-2xl">
                        <table class="tabela min-w-[720px]">
                            <thead>
                                <tr>
                                    <th class="w-[34%]">
                                        <span class="flex items-center gap-3">
                                            @if ($podeGerir)
                                                <input type="checkbox" @checked($todosSelecionados)
                                                    wire:click="$set('selecionados', @js($todosSelecionados ? [] : $ids))"
                                                    class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500" aria-label="Selecionar todos">
                                            @endif
                                            Nome
                                        </span>
                                    </th>
                                    <th>Morada</th>
                                    <th class="w-32">Moeda</th>
                                    <th class="w-24"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($clientes as $c)
                                    <tr wire:key="cliente-{{ $c->id }}" class="{{ in_array((string) $c->id, $selecionados, true) ? '!bg-verde-50/60' : '' }}">
                                        <td>
                                            <div class="flex items-center gap-3">
                                                @if ($podeGerir)
                                                    <input type="checkbox" wire:model.live="selecionados" value="{{ $c->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500" aria-label="Selecionar {{ $c->nome }}">
                                                @endif
                                                <div class="min-w-0">
                                                    <div class="truncate font-medium {{ $c->estaArquivado() ? 'text-texto-medio' : 'text-texto-forte' }}">{{ $c->nome }}</div>
                                                    @if ($c->email)<div class="truncate text-xs text-texto-fraco">{{ $c->email }}</div>@endif
                                                </div>
                                                @if ($c->estaArquivado())
                                                    <span class="etiqueta bg-slate-100 text-texto-medio">Arquivado</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="max-w-xs truncate text-texto-medio" title="{{ $c->morada }}">{{ $c->morada ? \Illuminate\Support\Str::of($c->morada)->replace("\n", ', ') : '—' }}</td>
                                        <td class="tabular-nums text-texto-forte">{{ $c->moeda }}</td>
                                        <td>
                                            @if ($podeGerir)
                                                <div class="flex justify-end gap-1.5">
                                                    <button type="button" wire:click="editar({{ $c->id }})" class="botao-icone h-8 w-8" title="Alterar" aria-label="Alterar {{ $c->nome }}"><x-icone nome="lapis" /></button>
                                                    <div class="relative" x-data="menuFlutuante('direita')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                        <button type="button" x-ref="botao" @click="alternar()" class="botao-icone h-8 w-8" title="Mais opções" aria-label="Mais opções para {{ $c->nome }}" :aria-expanded="aberto"><x-icone nome="mais-opcoes" /></button>
                                                        <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak x-transition.opacity class="fixed z-50 w-44 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
                                                            @if ($c->estaArquivado())
                                                                <button type="button" wire:click="restaurar({{ $c->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="atualizar" /> Restaurar</button>
                                                                <button type="button" wire:click="apagar({{ $c->id }})" wire:confirm="Apagar «{{ $c->nome }}»?" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left text-perigo-600 hover:bg-perigo-100" role="menuitem"><x-icone nome="lixo" /> Apagar</button>
                                                            @else
                                                                <button type="button" wire:click="arquivar({{ $c->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="arquivo" /> Arquivar</button>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </main>

    {{-- Formulário de alteração --}}
    @if ($editarId)
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

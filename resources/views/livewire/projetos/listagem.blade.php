@use('App\Support\Dinheiro')
@use('App\Support\Horas')

@php
    $ids = $projetos->pluck('id')->map(fn ($id) => (string) $id)->all();
    $todosSelecionados = $ids !== [] && array_diff($ids, $selecionados) === [];
    $selecao = $projetos->whereIn('id', array_map('intval', $selecionados));
    $seta = fn (string $campo) => ltrim($ordem, '-') === $campo ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '';
    $filtrado = trim($pesquisa) !== '' || $filtroCliente !== '' || $filtroAcesso !== '' || $filtroFaturacao !== '';
    $pct = fn (float $p) => str_replace('.', ',', (string) $p).'%';
@endphp

<div>
    <x-topbar :breadcrumb="['Tempos', 'Projetos']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-cabecalho-pagina titulo="Projetos">
                @if ($podeGerir)
                    <x-slot:acoes>
                        @if ($projetos->isNotEmpty())
                            <button type="button" wire:click="exportar" class="botao-secundario"><x-icone nome="descarregar" /> Exportar CSV</button>
                        @endif
                        <button type="button" wire:click="novo" class="botao-primario"><x-icone nome="mais" traco="2" /> Novo projeto</button>
                    </x-slot:acoes>
                @endif
            </x-cabecalho-pagina>

            @if ($erro)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <section class="cartao mt-6">
                {{-- Filtros --}}
                <div class="flex flex-wrap items-center gap-3 border-b border-borda p-4 sm:px-5">
                    <select wire:model.live="mostrar" class="campo-select campo-barra w-full sm:w-40" aria-label="Mostrar">
                        <option value="ativos">Ativos</option>
                        <option value="arquivados">Arquivados</option>
                        <option value="todos">Todos</option>
                    </select>
                    <select wire:model.live="filtroCliente" class="campo-select campo-barra w-full sm:w-44" aria-label="Cliente">
                        <option value="">Cliente</option>
                        <option value="sem">Sem cliente</option>
                        @foreach ($clientes as $c)
                            <option value="{{ $c->id }}">{{ $c->nome }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="filtroAcesso" class="campo-select campo-barra w-full sm:w-36" aria-label="Acesso">
                        <option value="">Acesso</option>
                        <option value="publico">Público</option>
                        <option value="privado">Privado</option>
                    </select>
                    <select wire:model.live="filtroFaturacao" class="campo-select campo-barra w-full sm:w-40" aria-label="Faturação">
                        <option value="">Faturação</option>
                        <option value="faturavel">Faturável</option>
                        <option value="nao">Não faturável</option>
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
                            @if ($selecao->contains(fn ($p) => ! $p->estaArquivado()))
                                <button type="button" wire:click="arquivar" class="pilula-botao py-1.5"><x-icone nome="arquivo" /> Arquivar</button>
                            @endif
                            @if ($selecao->contains(fn ($p) => $p->estaArquivado()))
                                <button type="button" wire:click="restaurar" class="pilula-botao py-1.5"><x-icone nome="atualizar" /> Restaurar</button>
                                <button type="button" wire:click="apagar" wire:confirm="Apagar os projetos selecionados? Só se apagam projetos arquivados." class="pilula-botao py-1.5 hover:border-perigo-200 hover:bg-perigo-100 hover:text-perigo-600"><x-icone nome="lixo" /> Apagar</button>
                            @endif
                            <button type="button" wire:click="$set('selecionados', [])" class="px-2 text-sm text-texto-medio hover:text-texto-forte">Limpar</button>
                        </div>
                    </div>
                @endif

                @if ($projetos->isEmpty())
                    @if ($filtrado)
                        <x-estado-vazio icone="pesquisa" titulo="Nenhum projeto com estes filtros" />
                    @elseif ($mostrar === 'arquivados')
                        <x-estado-vazio icone="arquivo" titulo="Sem projetos arquivados" />
                    @else
                        <x-estado-vazio icone="contrato" titulo="Ainda sem projetos">
                            @if ($podeGerir)
                                <button type="button" wire:click="novo" class="botao-primario"><x-icone nome="mais" traco="2" /> Novo projeto</button>
                            @endif
                        </x-estado-vazio>
                    @endif
                @else
                    <div class="relative overflow-x-auto rounded-b-2xl">
                        <table class="tabela min-w-[820px] [&_td]:px-3 [&_th]:px-3">
                            <thead>
                                <tr>
                                    <th>
                                        <span class="flex items-center gap-3">
                                            @if ($podeGerir)
                                                <input type="checkbox" @checked($todosSelecionados) wire:click="$set('selecionados', @js($todosSelecionados ? [] : $ids))"
                                                    class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500" aria-label="Selecionar todos">
                                            @endif
                                            <button type="button" wire:click="ordenarPor('nome')" class="hover:text-texto-forte">Nome {{ $seta('nome') }}</button>
                                        </span>
                                    </th>
                                    <th class="w-44"><button type="button" wire:click="ordenarPor('cliente')" class="hover:text-texto-forte">Cliente {{ $seta('cliente') }}</button></th>
                                    <th class="w-28"><button type="button" wire:click="ordenarPor('registado')" class="block w-full text-right hover:text-texto-forte">Registado {{ $seta('registado') }}</button></th>
                                    @if ($podeGerir)
                                        <th class="w-32"><button type="button" wire:click="ordenarPor('valor')" class="block w-full text-right hover:text-texto-forte">Valor {{ $seta('valor') }}</button></th>
                                    @endif
                                    <th class="w-44"><button type="button" wire:click="ordenarPor('progresso')" class="hover:text-texto-forte">Progresso {{ $seta('progresso') }}</button></th>
                                    <th class="w-24">Acesso</th>
                                    <th class="w-24"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($projetos as $p)
                                    <tr wire:key="projeto-{{ $p->id }}" class="{{ in_array((string) $p->id, $selecionados, true) ? '!bg-verde-50/60' : '' }}">
                                        <td>
                                            <div class="flex items-center gap-3">
                                                @if ($podeGerir)
                                                    <input type="checkbox" wire:model.live="selecionados" value="{{ $p->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500" aria-label="Selecionar {{ $p->nome }}">
                                                @endif
                                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $p->cor }}" aria-hidden="true"></span>
                                                <span class="truncate font-medium {{ $p->estaArquivado() ? 'text-texto-medio' : 'text-texto-forte' }}">{{ $p->nome }}</span>
                                                @if ($p->estaArquivado())
                                                    <span class="etiqueta bg-slate-100 text-texto-medio">Arquivado</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="max-w-[11rem] truncate {{ $p->cliente ? 'text-texto-medio' : 'text-texto-fraco' }}">{{ $p->cliente?->nome ?? '—' }}</td>
                                        <td class="text-right tabular-nums text-texto-forte">{{ Horas::hm($p->segundos) }}</td>
                                        @if ($podeGerir)
                                            <td class="text-right tabular-nums {{ $p->faturavel ? 'text-texto-forte' : 'text-texto-fraco' }}">{{ $p->faturavel ? Dinheiro::formatar($p->valor_cent) : '—' }}</td>
                                        @endif
                                        <td>
                                            @if ($p->progresso === null)
                                                <span class="text-texto-fraco">—</span>
                                            @else
                                                <div class="flex items-center gap-2" title="{{ Horas::hm($p->segundos) }} de {{ Horas::hm($p->estimativa_seg) }}">
                                                    <span class="h-1.5 w-20 overflow-hidden rounded-full bg-fundo" aria-hidden="true">
                                                        <span class="block h-full rounded-full {{ $p->progresso > 100 ? 'bg-perigo-500' : 'bg-verde-500' }}" style="width: {{ min(100, $p->progresso) }}%"></span>
                                                    </span>
                                                    <span class="text-xs tabular-nums {{ $p->progresso > 100 ? 'font-medium text-perigo-600' : 'text-texto-medio' }}">{{ $pct($p->progresso) }}</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-texto-medio">
                                            <span class="inline-flex items-center gap-1.5">
                                                @unless ($p->publico)<x-icone nome="cadeado" class="h-3.5 w-3.5 text-texto-fraco" />@endunless
                                                {{ $p->publico ? 'Público' : 'Privado' }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button type="button" wire:click="alternarFavorito({{ $p->id }})"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full hover:bg-fundo {{ $p->favorito ? 'text-amber-400' : 'text-texto-fraco hover:text-texto-medio' }}"
                                                    aria-pressed="{{ $p->favorito ? 'true' : 'false' }}" aria-label="Favorito: {{ $p->nome }}" title="{{ $p->favorito ? 'Tirar dos favoritos' : 'Marcar como favorito' }}">
                                                    <x-icone nome="estrela" class="{{ $p->favorito ? 'fill-current' : '' }}" />
                                                </button>
                                                @if ($podeGerir)
                                                    <div class="relative" x-data="menuFlutuante('direita')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                        <button type="button" x-ref="botao" @click="alternar()" class="botao-icone h-8 w-8" title="Mais opções" aria-label="Mais opções para {{ $p->nome }}" :aria-expanded="aberto"><x-icone nome="mais-opcoes" /></button>
                                                        <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak x-transition.opacity class="fixed z-50 w-44 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
                                                            <button type="button" wire:click="editar({{ $p->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="lapis" /> Alterar</button>
                                                            @if ($p->estaArquivado())
                                                                <button type="button" wire:click="restaurar({{ $p->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="atualizar" /> Restaurar</button>
                                                                <button type="button" wire:click="apagar({{ $p->id }})" wire:confirm="Apagar «{{ $p->nome }}»?" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left text-perigo-600 hover:bg-perigo-100" role="menuitem"><x-icone nome="lixo" /> Apagar</button>
                                                            @else
                                                                <button type="button" wire:click="arquivar({{ $p->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="arquivo" /> Arquivar</button>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
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

    {{-- Novo / alterar projeto --}}
    @if ($editarId !== null)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="fecharFormulario" role="dialog" aria-modal="true" aria-labelledby="titulo-projeto">
            <div class="absolute inset-0" wire:click="fecharFormulario"></div>
            <form wire:submit="guardar" class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl" x-data x-init="$nextTick(() => $refs.nome.focus())">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-projeto" class="text-lg font-semibold text-texto-forte">{{ $editarId === 0 ? 'Novo projeto' : 'Alterar projeto' }}</h2>
                    <button type="button" wire:click="fecharFormulario" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>

                <div class="space-y-5 px-6 py-5">
                    <div>
                        <label class="campo-label" for="projeto-nome">Nome <span class="text-perigo-500">*</span></label>
                        <input id="projeto-nome" x-ref="nome" type="text" wire:model="formulario.nome" class="campo-input">
                        @error('formulario.nome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="campo-label" for="projeto-cliente">Cliente</label>
                        <select id="projeto-cliente" wire:model="formulario.cliente_id" class="campo-select">
                            <option value="">Sem cliente</option>
                            @foreach ($clientes as $c)
                                @continue($c->arquivado_em && (string) $c->id !== ($formulario['cliente_id'] ?? ''))
                                <option value="{{ $c->id }}">{{ $c->nome }}</option>
                            @endforeach
                        </select>
                        @error('formulario.cliente_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <span class="campo-label">Cor</span>
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Cor">
                            @foreach ($cores as $cor)
                                <label class="cursor-pointer">
                                    <input type="radio" wire:model.live="formulario.cor" value="{{ $cor }}" class="peer sr-only">
                                    <span class="block h-7 w-7 rounded-full ring-offset-2 transition peer-checked:ring-2 peer-checked:ring-texto-forte peer-focus-visible:ring-2 peer-focus-visible:ring-verde-500" style="background: {{ $cor }}"></span>
                                </label>
                            @endforeach
                        </div>
                        @error('formulario.cor') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <span class="campo-label">Acesso</span>
                        <div class="segmentos" role="group" aria-label="Acesso">
                            <button type="button" wire:click="$set('formulario.publico', true)" class="segmento {{ ($formulario['publico'] ?? true) ? 'segmento-ativo' : '' }}">Público</button>
                            <button type="button" wire:click="$set('formulario.publico', false)" class="segmento {{ ($formulario['publico'] ?? true) ? '' : 'segmento-ativo' }}">Privado</button>
                        </div>
                        @unless ($formulario['publico'] ?? true)
                            <div class="mt-3 max-h-48 space-y-1 overflow-y-auto rounded-xl border border-borda p-2">
                                @foreach ($membros as $m)
                                    <label wire:key="projeto-membro-{{ $m->id }}" class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 text-sm hover:bg-fundo">
                                        <input type="checkbox" wire:model="formulario.membros" value="{{ $m->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
                                        <x-avatar :nome="$m->nomeVisivel()" tom="claro" class="h-6 w-6 text-[9px]" />
                                        <span class="truncate text-texto-forte">{{ $m->nomeVisivel() }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('formulario.membros') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        @endunless
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="campo-label flex cursor-pointer items-center gap-2">
                                <input type="checkbox" wire:model.live="formulario.faturavel" class="h-3.5 w-3.5 rounded border-borda text-verde-600 focus:ring-verde-500"> Faturável
                            </label>
                            <div class="relative">
                                <input type="text" inputmode="decimal" wire:model="formulario.taxa" @disabled(! ($formulario['faturavel'] ?? true))
                                    class="campo-input pr-12 tabular-nums disabled:bg-fundo disabled:text-texto-fraco" placeholder="Taxa do membro" aria-label="Taxa do projeto">
                                <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-texto-fraco">€/h</span>
                            </div>
                            @error('formulario.taxa') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="campo-label" for="projeto-estimativa">Estimativa</label>
                            <div class="relative">
                                <input id="projeto-estimativa" type="text" inputmode="decimal" wire:model="formulario.estimativa" class="campo-input pr-8 tabular-nums" placeholder="—">
                                <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-texto-fraco">h</span>
                            </div>
                            @error('formulario.estimativa') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="campo-label" for="projeto-nota">Nota</label>
                        <textarea id="projeto-nota" wire:model="formulario.nota" rows="3" class="campo-input"></textarea>
                    </div>
                </div>

                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="fecharFormulario" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> {{ $editarId === 0 ? 'Criar' : 'Guardar' }}</button>
                </footer>
            </form>
        </div>
    @endif
</div>

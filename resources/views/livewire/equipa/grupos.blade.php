<div>
    <x-topbar :breadcrumb="['Tempos', 'Equipa', 'Grupos']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-equipa-separadores atual="equipa.grupos">
                @if ($podeGerir)
                    <x-slot:acoes>
                        <form wire:submit="acrescentar" class="flex flex-wrap items-start gap-2">
                            <div class="w-full sm:w-56">
                                <input type="text" wire:model="novoNome" class="campo-input campo-barra {{ $errors->has('novoNome') ? '!border-perigo-500' : '' }}" placeholder="Nome do novo grupo" aria-label="Nome do novo grupo">
                                @error('novoNome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="botao-primario"><x-icone nome="mais" traco="2" /> Criar grupo</button>
                        </form>
                    </x-slot:acoes>
                @endif
            </x-equipa-separadores>

            @if ($erro)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <section class="cartao mt-6">
                <div class="flex flex-wrap items-center gap-3 border-b border-borda p-4 sm:px-5">
                    <div class="relative w-full sm:w-auto sm:min-w-[12rem] sm:flex-1">
                        <x-icone nome="pesquisa" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" />
                        <input type="search" wire:model.live.debounce.300ms="pesquisa" class="campo-input campo-barra pl-9" placeholder="Pesquisar" aria-label="Pesquisar grupos">
                    </div>
                </div>

                @if ($grupos->isEmpty())
                    @if (trim($pesquisa) !== '')
                        <x-estado-vazio icone="pesquisa" titulo="Nenhum grupo com esse nome" />
                    @else
                        <x-estado-vazio icone="camadas" titulo="Ainda sem grupos" />
                    @endif
                @else
                    <div class="relative overflow-x-auto rounded-b-2xl">
                        <table class="tabela min-w-[640px]">
                            <thead>
                                <tr>
                                    <th class="w-1/4">Nome</th>
                                    <th>Membros</th>
                                    <th class="w-24"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($grupos as $g)
                                    <tr wire:key="grupo-{{ $g->id }}">
                                        <td class="font-medium text-texto-forte">{{ $g->nome }}</td>
                                        <td>
                                            @if ($g->membros->isEmpty())
                                                <span class="text-texto-fraco">Sem membros</span>
                                            @else
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    @foreach ($g->membros->sortBy(fn ($m) => mb_strtolower($m->nomeVisivel()))->take(8) as $m)
                                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-fundo py-0.5 pl-0.5 pr-2.5 text-xs text-texto-forte">
                                                            <x-avatar :nome="$m->nomeVisivel()" tom="claro" class="h-5 w-5 text-[8px]" /> {{ $m->nomeVisivel() }}
                                                        </span>
                                                    @endforeach
                                                    @if ($g->membros->count() > 8)
                                                        <span class="text-xs text-texto-medio">e mais {{ $g->membros->count() - 8 }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($podeGerir)
                                                <div class="flex justify-end gap-1.5">
                                                    <button type="button" wire:click="abrir({{ $g->id }})" class="botao-icone h-8 w-8" title="Alterar" aria-label="Alterar {{ $g->nome }}"><x-icone nome="lapis" /></button>
                                                    <button type="button" wire:click="apagar({{ $g->id }})" wire:confirm="Apagar o grupo «{{ $g->nome }}»? As pessoas continuam na equipa." class="botao-icone-perigo h-8 w-8" title="Apagar" aria-label="Apagar {{ $g->nome }}"><x-icone nome="lixo" /></button>
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

    @if ($grupoId)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" role="dialog" aria-modal="true" aria-labelledby="titulo-grupo">
            <div class="absolute inset-0" wire:click="fechar"></div>
            <form wire:submit="guardar" class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-grupo" class="text-lg font-semibold text-texto-forte">Alterar grupo</h2>
                    <button type="button" wire:click="fechar" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="campo-label" for="grupo-nome">Nome <span class="text-perigo-500">*</span></label>
                        <input id="grupo-nome" type="text" wire:model="nome" class="campo-input">
                        @error('nome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="campo-label">Membros</span>
                            <span class="text-xs text-texto-medio">{{ count($membros) }} {{ count($membros) === 1 ? 'escolhido' : 'escolhidos' }}</span>
                        </div>
                        <input type="search" wire:model.live.debounce.300ms="pesquisaMembros" class="campo-input campo-barra" placeholder="Pesquisar pessoas" aria-label="Pesquisar pessoas">
                        <ul class="mt-2 max-h-72 divide-y divide-borda overflow-y-auto rounded-xl border border-borda">
                            @forelse ($candidatos as $c)
                                <li wire:key="candidato-{{ $c->id }}">
                                    <label class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-fundo">
                                        <input type="checkbox" wire:model.live="membros" value="{{ $c->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
                                        <x-avatar :nome="$c->nomeVisivel()" tom="claro" class="h-7 w-7 text-[10px]" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm text-texto-forte">{{ $c->nomeVisivel() }}</span>
                                            <span class="block truncate text-xs text-texto-fraco">{{ $c->emailVisivel() ?: '—' }}</span>
                                        </span>
                                        @if ($c->limitado)<span class="etiqueta bg-slate-100 text-texto-medio">Limitado</span>@endif
                                    </label>
                                </li>
                            @empty
                                <li class="px-4 py-3 text-sm text-texto-medio">Ninguém com esse nome.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="fechar" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Guardar</button>
                </footer>
            </form>
        </div>
    @endif
</div>

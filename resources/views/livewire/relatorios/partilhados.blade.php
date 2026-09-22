<div>
    <x-topbar :breadcrumb="['Tempos', 'Relatórios', 'Partilhados']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-cabecalho-pagina titulo="Relatórios" />

            <div class="mt-6 flex justify-end">
                <div class="relative w-full sm:w-72">
                    <x-icone nome="pesquisa" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" />
                    <input type="search" wire:model.live.debounce.300ms="pesquisa" class="campo-input campo-barra pl-9" placeholder="Pesquisar" aria-label="Pesquisar pelo nome">
                </div>
            </div>

            @if ($erro)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <section class="cartao mt-6">
                @if ($relatorios->isEmpty())
                    @if (trim($pesquisa) !== '')
                        <x-estado-vazio icone="pesquisa" titulo="Nenhum relatório com esse nome" />
                    @else
                        <x-estado-vazio icone="partilhar" titulo="Ainda sem relatórios partilhados">
                            <a href="{{ route('relatorios.resumo') }}" wire:navigate class="botao-primario"><x-icone nome="grafico" /> Abrir o Resumo</a>
                        </x-estado-vazio>
                    @endif
                @else
                    <div class="relative overflow-x-auto rounded-2xl">
                        <table class="tabela min-w-[900px] [&_td]:px-3 [&_th]:px-3">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th class="w-52">Período</th>
                                    <th class="w-32">Visibilidade</th>
                                    <th class="w-48">Email</th>
                                    @if ($veTodos)<th class="w-36">Criado por</th>@endif
                                    <th class="w-48"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($relatorios as $r)
                                    <tr wire:key="partilhado-{{ $r->id }}" x-data="{ copiado: false }">
                                        <td class="max-w-0">
                                            <a href="{{ $r->url() }}" target="_blank" rel="noopener" class="block truncate font-medium text-texto-forte hover:text-verde-700 hover:underline" title="{{ $r->nome }}">{{ $r->nome }}</a>
                                            <div class="text-xs text-texto-fraco">{{ $tipos[$r->tipo] ?? $r->tipo }} · {{ $r->created_at->setTimezone(config('tempos.fuso'))->format('d/m/Y') }}</div>
                                        </td>
                                        <td class="whitespace-nowrap text-texto-medio">
                                            <span class="inline-flex items-center gap-1.5">
                                                @if ($r->bloquear_datas)<x-icone nome="cadeado" class="h-3.5 w-3.5 text-texto-fraco" />@endif
                                                {{ $r->rotuloPeriodo() }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="etiqueta {{ $r->publico ? 'bg-verde-50 text-verde-700' : 'bg-slate-100 text-texto-medio' }}">{{ $r->publico ? 'Público' : 'Privado' }}</span>
                                        </td>
                                        <td class="text-texto-medio">
                                            @if ($r->email_ativo)
                                                <div class="truncate text-texto-forte" title="{{ implode(', ', $r->email_destinatarios) }}">{{ count($r->email_destinatarios) }} {{ count($r->email_destinatarios) === 1 ? 'destinatário' : 'destinatários' }}</div>
                                                <div class="text-xs text-texto-fraco">{{ $frequencias[$r->email_frequencia] }} às {{ sprintf('%02d:00', $r->email_hora) }}</div>
                                            @else
                                                <span class="text-texto-fraco">—</span>
                                            @endif
                                        </td>
                                        @if ($veTodos)
                                            <td class="max-w-[9rem] truncate text-texto-medio">{{ $r->autor?->nome ?? '—' }}</td>
                                        @endif
                                        <td>
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button type="button" class="pilula-botao whitespace-nowrap py-1.5 text-xs"
                                                    @click="navigator.clipboard?.writeText(@js($r->url())); copiado = true; setTimeout(() => copiado = false, 2000)">
                                                    <x-icone nome="duplicar" class="h-3.5 w-3.5" /> <span x-text="copiado ? 'Copiado' : 'Copiar link'">Copiar link</span>
                                                </button>
                                                <div class="relative" x-data="menuFlutuante('direita')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                    <button type="button" x-ref="botao" @click="alternar()" class="botao-icone h-8 w-8" aria-label="Mais opções para {{ $r->nome }}" :aria-expanded="aberto"><x-icone nome="mais-opcoes" /></button>
                                                    <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak x-transition.opacity class="fixed z-50 w-48 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
                                                        <a href="{{ $r->url() }}" target="_blank" rel="noopener" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="seta-canto" /> Abrir</a>
                                                        <button type="button" wire:click="editar({{ $r->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="lapis" /> Alterar</button>
                                                        <button type="button" wire:click="novoLink({{ $r->id }})" wire:confirm="Criar um novo link? O atual deixa de funcionar." @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="atualizar" /> Novo link</button>
                                                        <button type="button" wire:click="apagar({{ $r->id }})" wire:confirm="Apagar «{{ $r->nome }}»? O link deixa de funcionar." @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left text-perigo-600 hover:bg-perigo-100" role="menuitem"><x-icone nome="lixo" /> Apagar</button>
                                                    </div>
                                                </div>
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

    {{-- Alterar --}}
    @if ($editarId)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="fechar" role="dialog" aria-modal="true" aria-labelledby="titulo-partilhado">
            <div class="absolute inset-0" wire:click="fechar"></div>
            <form wire:submit="guardar" class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-partilhado" class="text-lg font-semibold text-texto-forte">Alterar relatório partilhado</h2>
                    <button type="button" wire:click="fechar" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="space-y-5 px-6 py-5">
                    <div>
                        <label class="campo-label" for="partilhado-nome">Nome do relatório <span class="text-perigo-500">*</span></label>
                        <input id="partilhado-nome" type="text" wire:model="formulario.nome" maxlength="250" class="campo-input">
                        @error('formulario.nome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label" for="partilhado-visibilidade">Visibilidade</label>
                        <select id="partilhado-visibilidade" wire:model="formulario.publico" class="campo-select">
                            <option value="1">Público — qualquer pessoa com o link</option>
                            <option value="0">Privado — só quem tem acesso aos Tempos</option>
                        </select>
                    </div>
                    <div class="space-y-3">
                        @foreach (array_filter(['sempre_atual' => ($formulario['datas'] ?? false) ? null : 'Abrir sempre no período atual', 'bloquear_datas' => 'Bloquear datas', 'email_ativo' => 'Enviar por email']) as $campo => $rotulo)
                            <label class="flex cursor-pointer items-center gap-3 text-sm text-texto-forte">
                                <input type="checkbox" wire:model.live="formulario.{{ $campo }}" class="peer sr-only">
                                <span class="relative h-5 w-9 shrink-0 rounded-full bg-slate-300 transition after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition peer-checked:bg-verde-600 peer-checked:after:translate-x-4 peer-focus-visible:ring-2 peer-focus-visible:ring-verde-500"></span>
                                {{ $rotulo }}
                            </label>
                        @endforeach
                    </div>
                    @if ($formulario['email_ativo'] ?? false)
                        <div class="space-y-4 rounded-xl border border-borda bg-fundo/50 p-4">
                            <div>
                                <label class="campo-label" for="partilhado-emails">Para</label>
                                <input id="partilhado-emails" type="text" wire:model="formulario.email_destinatarios" class="campo-input" placeholder="Emails separados por vírgulas">
                                @error('formulario.email_destinatarios') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_8rem]">
                                <div>
                                    <label class="campo-label" for="partilhado-frequencia">Quando</label>
                                    <select id="partilhado-frequencia" wire:model="formulario.email_frequencia" class="campo-select">
                                        @foreach ($frequencias as $valor => $rotulo)
                                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="campo-label" for="partilhado-hora">À hora</label>
                                    <select id="partilhado-hora" wire:model="formulario.email_hora" class="campo-select">
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
                    <button type="button" wire:click="fechar" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Guardar</button>
                </footer>
            </form>
        </div>
    @endif
</div>

@use('App\Models\TaxaMembro')
@use('App\Support\Dinheiro')

@php
    $ids = $membros->pluck('id')->map(fn ($id) => (string) $id)->all();
    $todosSelecionados = $ids !== [] && array_diff($ids, $selecionados) === [];
    $seta = fn (string $campo) => ltrim($ordem, '-') === $campo ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '';
    $filtrosAtivos = collect([$filtroPapel, $filtroGrupo, $filtroFaturavel, $filtroCusto, $filtroInicioSemana, $filtroDia, $filtroCapacidade, $filtroGestor])->filter()->count();
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Equipa', $limitados ? 'Limitados' : 'Membros']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-equipa-separadores :atual="$limitados ? 'equipa.limitados' : 'equipa'">
                @if ($podeGerir)
                    <x-slot:acoes>
                        @if ($membros->isNotEmpty())
                            <button type="button" wire:click="exportar" class="botao-secundario"><x-icone nome="descarregar" /> Exportar CSV</button>
                        @endif
                        @if ($limitados)
                            <div class="relative" x-data="{ aberto: @js($errors->has('novo.nome') || $errors->has('novo.email')) }" @click.outside="aberto = false" @keydown.escape="aberto = false" @limitado-acrescentado.window="aberto = false">
                                <button type="button" @click="aberto = ! aberto; $nextTick(() => aberto && $refs.nome.focus())" class="botao-primario"><x-icone nome="mais" traco="2" /> Novo membro limitado</button>
                                <form x-show="aberto" x-cloak wire:submit="acrescentarLimitado" class="absolute right-0 z-30 mt-2 w-80 space-y-3 rounded-xl border border-borda bg-white p-4 shadow-lg">
                                    <div>
                                        <input type="text" x-ref="nome" wire:model="novoNome" class="campo-input campo-barra {{ $errors->has('novo.nome') ? '!border-perigo-500' : '' }}" placeholder="Nome *" aria-label="Nome">
                                        @error('novo.nome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <input type="email" wire:model="novoEmail" class="campo-input campo-barra {{ $errors->has('novo.email') ? '!border-perigo-500' : '' }}" placeholder="Email (opcional)" aria-label="Email">
                                        @error('novo.email') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                                    </div>
                                    <button type="submit" class="botao-primario w-full justify-center">Acrescentar membro limitado</button>
                                </form>
                            </div>
                        @else
                            <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="botao-primario"><x-icone nome="mais" traco="2" /> Acrescentar membro no portal</a>
                        @endif
                    </x-slot:acoes>
                @endif
            </x-equipa-separadores>

            @if ($erro)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <section class="cartao mt-6">
                {{-- Filtros --}}
                <div class="relative z-10 flex flex-wrap items-center gap-3 border-b border-borda p-4 sm:px-5">
                    {{-- Menu Filtros: que campos aparecem (filtro e coluna) --}}
                    <div class="relative" x-data="{ aberto: false }" @click.outside="aberto = false" @keydown.escape="aberto = false">
                        <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto"
                            class="inline-flex items-center gap-1.5 rounded-lg px-2 py-2 text-xs font-medium uppercase tracking-wide text-texto-medio hover:bg-fundo hover:text-texto-forte">
                            <x-icone nome="filtro" class="h-3.5 w-3.5" /> Filtros
                            @if ($filtrosAtivos > 0)<span class="rounded-full bg-verde-50 px-2 py-0.5 normal-case text-verde-700">{{ $filtrosAtivos }}</span>@endif
                            <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 rotate-90" />
                        </button>
                        <div x-show="aberto" x-cloak x-transition.opacity class="absolute left-0 z-30 mt-1 w-72 rounded-xl border border-borda bg-white py-3 shadow-lg">
                            <div class="px-4 pb-2 text-[11px] font-medium uppercase tracking-wide text-texto-fraco">Campos</div>
                            @foreach ($camposDisponiveis as $chave => $rotulo)
                                <label wire:key="campo-{{ $chave }}" class="flex cursor-pointer items-center gap-3 px-4 py-2 text-sm text-texto-forte hover:bg-fundo">
                                    <input type="checkbox" wire:click="alternarCampo('{{ $chave }}')" @checked(in_array($chave, $campos, true))
                                        class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
                                    {{ $rotulo }}
                                </label>
                            @endforeach
                            <div class="mt-2 border-t border-borda px-4 pt-2">
                                <button type="button" wire:click="camposPorOmissao" class="text-xs font-medium text-verde-700 hover:underline">Repor os campos por omissão</button>
                            </div>
                        </div>
                    </div>
    
                    @if ($ver('papel'))
                        <select wire:model.live="filtroPapel" class="campo-select campo-barra w-full sm:w-40" aria-label="Papel">
                            <option value="">Papel</option>
                            @foreach ($papeis as $p)
                                <option value="{{ $p->value }}">{{ $p->rotulo() }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if ($ver('grupo'))
                        <select wire:model.live="filtroGrupo" class="campo-select campo-barra w-full sm:w-40" aria-label="Grupo">
                            <option value="">Grupo</option>
                            @foreach ($grupos as $g)
                                <option value="{{ $g->id }}">{{ $g->nome }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if ($ver('faturavel'))
                        <select wire:model.live="filtroFaturavel" class="campo-select campo-barra w-full sm:w-40" aria-label="Taxa faturável">
                            <option value="">Taxa faturável</option>
                            <option value="com">Com taxa faturável</option>
                            <option value="sem">Sem taxa faturável</option>
                        </select>
                    @endif
                    @if ($ver('custo'))
                        <select wire:model.live="filtroCusto" class="campo-select campo-barra w-full sm:w-40" aria-label="Taxa de custo">
                            <option value="">Taxa de custo</option>
                            <option value="com">Com taxa de custo</option>
                            <option value="sem">Sem taxa de custo</option>
                        </select>
                    @endif
                    @if ($ver('inicio_semana'))
                        <select wire:model.live="filtroInicioSemana" class="campo-select campo-barra w-full sm:w-52" aria-label="Início da semana">
                            <option value="">Início da semana</option>
                            @foreach ($dias as $n => $dia)
                                <option value="{{ $n }}">Começa à {{ mb_strtolower($dia, 'UTF-8') }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if ($ver('dias_trabalho'))
                        <select wire:model.live="filtroDia" class="campo-select campo-barra w-full sm:w-52" aria-label="Dias de trabalho">
                            <option value="">Dias de trabalho</option>
                            @foreach ($dias as $n => $dia)
                                <option value="{{ $n }}">Trabalha à {{ mb_strtolower($dia, 'UTF-8') }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if ($ver('capacidade'))
                        <select wire:model.live="filtroCapacidade" class="campo-select campo-barra w-full sm:w-52" aria-label="Capacidade diária">
                            <option value="">Capacidade diária</option>
                            <option value="com">Com capacidade diária</option>
                            <option value="sem">Sem capacidade diária</option>
                        </select>
                    @endif
                    @if ($ver('gestor'))
                        <select wire:model.live="filtroGestor" class="campo-select campo-barra w-full sm:w-52" aria-label="Gestor de equipa">
                            <option value="">Gestor de equipa</option>
                            <option value="sem">Sem gestor</option>
                            @foreach ($gestores as $g)
                                <option value="{{ $g->id }}">{{ $g->nomeVisivel() }}</option>
                            @endforeach
                        </select>
                    @endif
                    <div class="relative w-full sm:w-auto sm:min-w-[12rem] sm:flex-1">
                        <x-icone nome="pesquisa" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-texto-fraco" />
                        <input type="search" wire:model.live.debounce.300ms="pesquisa" class="campo-input campo-barra pl-9" placeholder="Pesquisar" aria-label="Pesquisar por nome ou email">
                    </div>
                </div>

                {{-- Seleção --}}
                @if ($podeGerir && $selecionados !== [])
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-borda bg-verde-50/60 px-5 py-2.5">
                        <span class="text-sm font-medium text-texto-forte">{{ count($selecionados) }} {{ count($selecionados) === 1 ? 'selecionado' : 'selecionados' }}</span>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($grupos->isNotEmpty())
                                <div class="relative" x-data="{ aberto: false }" @click.outside="aberto = false">
                                    <button type="button" @click="aberto = ! aberto" class="pilula-botao py-1.5"><x-icone nome="camadas" /> Pôr num grupo</button>
                                    <div x-show="aberto" x-cloak class="absolute right-0 z-20 mt-1 max-h-64 w-52 overflow-auto rounded-xl border border-borda bg-white py-1 text-sm shadow-lg">
                                        @foreach ($grupos as $g)
                                            <button type="button" wire:click="porNoGrupo({{ $g->id }})" @click="aberto = false" class="block w-full px-4 py-2 text-left hover:bg-fundo">{{ $g->nome }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <button type="button" wire:click="$set('selecionados', [])" class="px-2 text-sm text-texto-medio hover:text-texto-forte">Limpar</button>
                        </div>
                    </div>
                @endif

                @if ($membros->isEmpty())
                    @if (trim($pesquisa) !== '' || $filtrosAtivos > 0)
                        <x-estado-vazio icone="pesquisa" titulo="Ninguém com estes filtros" />
                    @elseif ($limitados)
                        <x-estado-vazio icone="pessoa" titulo="Sem membros limitados" />
                    @else
                        <x-estado-vazio icone="pessoas" titulo="Ninguém com acesso ao Suporte" />
                    @endif
                @else
                    <div class="relative overflow-x-auto rounded-b-2xl">
                        <table class="tabela min-w-[760px] [&_td]:px-3 [&_th]:px-3">
                            <thead>
                                <tr>
                                    <th>
                                        <span class="flex items-center gap-3">
                                            @if ($podeGerir)
                                                <input type="checkbox" @checked($todosSelecionados) wire:click="$set('selecionados', @js($todosSelecionados ? [] : $ids))"
                                                    class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500" aria-label="Selecionar todos">
                                            @endif
                                            <button type="button" wire:click="ordenarPor('nome')" class="hover:text-texto-forte">Nome {{ $seta('nome') }}</button>
                                            <span class="text-borda">/</span>
                                            <button type="button" wire:click="ordenarPor('email')" class="-ml-1 hover:text-texto-forte">Email {{ $seta('email') }}</button>
                                        </span>
                                    </th>
                                    @if ($ver('faturavel'))<th class="w-28 whitespace-normal leading-tight">Taxa faturável (€/h)</th>@endif
                                    @if ($ver('custo'))<th class="w-28 whitespace-normal leading-tight">Taxa de custo (€/h)</th>@endif
                                    @if ($ver('papel'))<th class="w-40">Papel</th>@endif
                                    @if ($ver('grupo'))<th class="w-40">Grupo</th>@endif
                                    @if ($ver('inicio_semana'))<th class="w-36">Início da semana</th>@endif
                                    @if ($ver('dias_trabalho'))<th class="w-36">Dias de trabalho</th>@endif
                                    @if ($ver('capacidade'))<th class="w-32">Capacidade diária</th>@endif
                                    @if ($ver('gestor'))<th class="w-44">Gestor de equipa</th>@endif
                                    <th class="w-14"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($membros as $m)
                                    @php $gruposDoMembro = $m->grupos->pluck('id')->all(); @endphp
                                    {{-- A linha toda abre o membro (a pedido), a não ser que o clique seja num link, botão, campo ou menu —
                                         a linha tem muitos controlos para quem gere, e os que vierem ficam protegidos na mesma. --}}
                                    <tr wire:key="membro-{{ $m->id }}" @click="$event.target.closest('a, button, input, select, textarea, label, [role=menu], [role=dialog]') || Livewire.navigate(@js(route('equipa.ver', $m)))" class="cursor-pointer {{ in_array((string) $m->id, $selecionados, true) ? '!bg-verde-50/60' : '' }}">
                                        <td>
                                            <div class="flex items-center gap-3">
                                                @if ($podeGerir)
                                                    <input type="checkbox" wire:model.live="selecionados" value="{{ $m->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500" aria-label="Selecionar {{ $m->nomeVisivel() }}">
                                                @endif
                                                <x-avatar :nome="$m->nomeVisivel()" tom="claro" class="h-8 w-8 text-[10px]" />
                                                <div class="min-w-0">
                                                    <div class="truncate font-medium text-texto-forte">
                                                        <a href="{{ route('equipa.ver', $m) }}" wire:navigate class="hover:underline">{{ $m->nomeVisivel() }}</a>@if ($m->utilizador_id === auth()->id())<span class="font-normal text-texto-fraco"> (você)</span>@endif
                                                    </div>
                                                    <div class="max-w-[16rem] truncate text-xs text-texto-medio" title="{{ $m->emailVisivel() }}">{{ $m->emailVisivel() ?: '—' }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        @if ($podeGerir)
                                            @foreach (['faturavel', 'custo'] as $tipo)
                                                @continue(! $ver($tipo))
                                                <td>
                                                    <button type="button" wire:click="abrirTaxa({{ $m->id }}, '{{ $tipo }}')"
                                                        class="group -ml-2 inline-flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-fundo"
                                                        aria-label="{{ TaxaMembro::TIPOS[$tipo] }} de {{ $m->nomeVisivel() }}">
                                                        <span class="min-w-[3rem] text-left tabular-nums text-texto-forte">{{ Dinheiro::decimal($m->taxaEm($tipo, $hoje)) ?: '—' }}</span>
                                                        <x-icone nome="lapis" class="h-3.5 w-3.5 text-texto-fraco group-hover:text-verde-700" />
                                                    </button>
                                                </td>
                                            @endforeach
                                        @endif
                                        @if ($ver('papel'))
                                        <td>
                                            @if ($podeGerir)
                                                <div class="relative" x-data="menuFlutuante('esquerda')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                    <button type="button" x-ref="botao" @click="alternar()" class="etiqueta {{ $m->papel->classesEtiqueta() }} cursor-pointer gap-1 hover:opacity-90" title="Mudar papel">
                                                        {{ $m->papel->rotulo() }} <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 rotate-90" />
                                                    </button>
                                                    <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak class="fixed z-50 w-48 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
                                                        @foreach ($papeis as $p)
                                                            @continue($m->limitado && $p === \App\Enums\PapelEquipa::Proprietario)
                                                            <button type="button" wire:click="mudarPapel({{ $m->id }}, '{{ $p->value }}')" @click="aberto = false"
                                                                @if ($p === \App\Enums\PapelEquipa::Proprietario && $m->papel !== $p) wire:confirm="Passar a propriedade para {{ $m->nomeVisivel() }}? O proprietário atual passa a administrador." @endif
                                                                class="flex w-full items-center justify-between px-4 py-2 text-left hover:bg-fundo" role="menuitem">
                                                                {{ $p->rotulo() }} @if ($m->papel === $p)<x-icone nome="visto" traco="2.5" class="text-verde-600" />@endif
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @else
                                                <span class="etiqueta {{ $m->papel->classesEtiqueta() }}">{{ $m->papel->rotulo() }}</span>
                                            @endif
                                        </td>
                                        @endif
                                        @if ($ver('grupo'))
                                        <td>
                                            @php $nomesGrupos = $m->grupos->pluck('nome')->implode(', '); @endphp
                                            @if ($podeGerir)
                                                <div class="relative" x-data="menuFlutuante('esquerda')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                    <button type="button" x-ref="botao" @click="alternar()" class="-ml-2 inline-flex max-w-[9rem] items-center gap-1 rounded-lg px-2 py-1.5 text-sm hover:bg-fundo"
                                                        title="{{ $nomesGrupos }}" aria-label="Grupos de {{ $m->nomeVisivel() }}">
                                                        <span class="truncate {{ $nomesGrupos === '' ? 'text-texto-fraco' : 'text-texto-forte' }}">{{ $nomesGrupos ?: 'Sem grupo' }}</span>
                                                        <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 shrink-0 rotate-90 text-texto-fraco" />
                                                    </button>
                                                    <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak class="fixed z-50 max-h-64 w-52 overflow-auto rounded-xl border border-borda bg-white py-1 text-sm shadow-lg">
                                                        @forelse ($grupos as $g)
                                                            <button type="button" wire:click="alternarGrupo({{ $m->id }}, {{ $g->id }})" class="flex w-full items-center justify-between px-4 py-2 text-left hover:bg-fundo">
                                                                {{ $g->nome }} @if (in_array($g->id, $gruposDoMembro, true))<x-icone nome="visto" traco="2.5" class="text-verde-600" />@endif
                                                            </button>
                                                        @empty
                                                            <a href="{{ route('equipa.grupos') }}" wire:navigate class="block px-4 py-2 text-texto-medio hover:bg-fundo">Ainda sem grupos — criar</a>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            @else
                                                <span class="block max-w-[9rem] truncate {{ $nomesGrupos === '' ? 'text-texto-fraco' : 'text-texto-forte' }}" title="{{ $nomesGrupos }}">{{ $nomesGrupos ?: '—' }}</span>
                                            @endif
                                        </td>
                                        @endif
                                        @if ($ver('inicio_semana'))
                                            <td>
                                                @if ($podeGerir)
                                                    <select wire:change="mudarCampo({{ $m->id }}, 'inicio_semana', $event.target.value)" class="campo-select w-full py-1.5 text-sm" aria-label="Início da semana de {{ $m->nomeVisivel() }}">
                                                        @foreach ($dias as $n => $dia)
                                                            <option value="{{ $n }}" @selected($m->inicio_semana === $n)>{{ $dia }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="text-texto-forte">{{ $dias[$m->inicio_semana] }}</span>
                                                @endif
                                            </td>
                                        @endif
                                        @if ($ver('dias_trabalho'))
                                            <td>
                                                @if ($podeGerir)
                                                    <div class="relative" x-data="menuFlutuante('esquerda')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                        <button type="button" x-ref="botao" @click="alternar()" class="inline-flex items-center gap-1 rounded-lg border border-borda bg-white px-3 py-1.5 text-sm text-texto-forte hover:bg-fundo">
                                                            {{ $m->rotuloDiasTrabalho() }} <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 rotate-90 text-texto-fraco" />
                                                        </button>
                                                        <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak class="fixed z-50 w-44 rounded-xl border border-borda bg-white py-1 text-sm shadow-lg">
                                                            @foreach ($dias as $n => $dia)
                                                                <label class="flex cursor-pointer items-center gap-3 px-4 py-1.5 hover:bg-fundo">
                                                                    <input type="checkbox" wire:click="alternarDia({{ $m->id }}, {{ $n }})" @checked(in_array($n, $m->dias_trabalho, true))
                                                                        class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
                                                                    {{ $dia }}
                                                                </label>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @else
                                                    <span class="text-texto-forte">{{ $m->rotuloDiasTrabalho() }}</span>
                                                @endif
                                            </td>
                                        @endif
                                        @if ($ver('capacidade'))
                                            <td>
                                                @if ($podeGerir)
                                                    <div class="relative">
                                                        <input type="text" inputmode="decimal" value="{{ $m->capacidade_diaria_seg ? \App\Services\Tempos\LeitorDuracao::formatar($m->capacidade_diaria_seg) : '' }}"
                                                            wire:change="mudarCampo({{ $m->id }}, 'capacidade', $event.target.value)" placeholder="—"
                                                            class="campo-input w-full py-1.5 pr-7 text-sm tabular-nums" aria-label="Capacidade diária de {{ $m->nomeVisivel() }}" title="Horas por dia (ex.: 8, 7:30)">
                                                        <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-texto-fraco">h</span>
                                                    </div>
                                                @else
                                                    <span class="tabular-nums text-texto-forte">{{ $m->capacidade_diaria_seg ? \App\Services\Tempos\LeitorDuracao::formatar($m->capacidade_diaria_seg).' h' : '—' }}</span>
                                                @endif
                                            </td>
                                        @endif
                                        @if ($ver('gestor'))
                                            <td>
                                                @if ($podeGerir)
                                                    <select wire:change="mudarCampo({{ $m->id }}, 'gestor_id', $event.target.value)" class="campo-select w-full py-1.5 text-sm" aria-label="Gestor de equipa de {{ $m->nomeVisivel() }}">
                                                        <option value="">—</option>
                                                        @foreach ($gestores as $g)
                                                            @continue($g->id === $m->id)
                                                            <option value="{{ $g->id }}" @selected($m->gestor_id === $g->id)>{{ $g->nomeVisivel() }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="text-texto-forte">{{ $m->gestor?->nomeVisivel() ?? '—' }}</span>
                                                @endif
                                            </td>
                                        @endif
                                        <td>
                                            @if ($podeGerir)
                                                <div class="relative flex justify-end" x-data="menuFlutuante('direita')" @click.outside="fechar()" @keydown.escape="fechar()" @scroll.window="fechar()" @resize.window="fechar()">
                                                    <button type="button" x-ref="botao" @click="alternar()" class="botao-icone h-8 w-8" title="Mais opções" aria-label="Mais opções para {{ $m->nomeVisivel() }}"><x-icone nome="mais-opcoes" /></button>
                                                    <div x-ref="menu" x-show="aberto" :style="estilo" x-cloak class="fixed z-50 w-52 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
                                                        @if ($m->limitado)
                                                            <button type="button" wire:click="editarLimitado({{ $m->id }})" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="lapis" /> Alterar</button>
                                                            <button type="button" wire:click="apagarLimitado({{ $m->id }})" wire:confirm="Apagar «{{ $m->nome }}»?" @click="aberto = false" class="flex w-full items-center gap-2 px-4 py-2 text-left text-perigo-600 hover:bg-perigo-100" role="menuitem"><x-icone nome="lixo" /> Apagar</button>
                                                        @else
                                                            <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="flex w-full items-center gap-2 whitespace-nowrap px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="seta-canto" /> Gerir acesso no portal</a>
                                                        @endif
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

    {{-- Mudar taxa --}}
    @if ($membroTaxa)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" role="dialog" aria-modal="true" aria-labelledby="titulo-taxa">
            <div class="absolute inset-0" wire:click="fecharTaxa"></div>
            <form wire:submit="guardarTaxa" class="janela relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <div>
                        <h2 id="titulo-taxa" class="text-lg font-semibold text-texto-forte">{{ TaxaMembro::TIPOS[$taxaTipo] }}</h2>
                        <p class="text-xs text-texto-medio">{{ $membroTaxa->nomeVisivel() }}</p>
                    </div>
                    <button type="button" wire:click="fecharTaxa" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="space-y-4 px-6 py-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="campo-label" for="taxa-valor">Valor por hora</label>
                            <div class="relative">
                                <input id="taxa-valor" type="text" inputmode="decimal" wire:model="taxaValor" class="campo-input pr-9 tabular-nums" placeholder="Sem taxa" autofocus>
                                <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-texto-fraco">€</span>
                            </div>
                            @error('taxa.valor') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="campo-label" for="taxa-apartir">A partir de</label>
                            <input id="taxa-apartir" type="date" wire:model="taxaApartir" class="campo-input">
                            @error('taxa.apartir') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($historicoTaxa->isNotEmpty())
                        <div>
                            <h3 class="text-xs font-medium text-texto-medio">Histórico</h3>
                            <ul class="mt-2 divide-y divide-borda rounded-xl border border-borda text-sm">
                                @foreach ($historicoTaxa as $t)
                                    <li class="flex items-center justify-between px-3 py-2">
                                        <span class="text-texto-medio">desde {{ $t->valido_de->format('d/m/Y') }}@if ($t->criadoPor)<span class="text-texto-fraco"> · {{ $t->criadoPor->nome }}</span>@endif</span>
                                        <span class="font-medium tabular-nums text-texto-forte">{{ Dinheiro::formatar($t->valor_cent) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="fecharTaxa" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Guardar</button>
                </footer>
            </form>
        </div>
    @endif

    {{-- Alterar membro limitado --}}
    @if ($editarId)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" role="dialog" aria-modal="true" aria-labelledby="titulo-editar-membro">
            <div class="absolute inset-0" wire:click="fecharEdicao"></div>
            <form wire:submit="guardarLimitado" class="janela relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-editar-membro" class="text-lg font-semibold text-texto-forte">Alterar membro limitado</h2>
                    <button type="button" wire:click="fecharEdicao" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="campo-label" for="membro-nome">Nome <span class="text-perigo-500">*</span></label>
                        <input id="membro-nome" type="text" wire:model="editarNome" class="campo-input">
                        @error('editar.nome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label" for="membro-email">Email</label>
                        <input id="membro-email" type="email" wire:model="editarEmail" class="campo-input">
                        @error('editar.email') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="fecharEdicao" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Guardar</button>
                </footer>
            </form>
        </div>
    @endif
</div>

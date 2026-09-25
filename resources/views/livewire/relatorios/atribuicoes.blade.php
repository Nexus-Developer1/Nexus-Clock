@use('App\Services\Tempos\PainelTempos')
@use('App\Support\Horas')
@use('Illuminate\Support\Js')

@php
    $hms = fn (int $s) => PainelTempos::hms($s);
    $seta = fn (string $campo) => ltrim($ordem, '-') === $campo ? (str_starts_with($ordem, '-') ? '↓' : '↑') : '';
    $comFilhos = $agrupar2 !== '';
    $classesEstado = [
        'por_comecar' => 'bg-slate-100 text-texto-medio',
        'em_curso' => 'bg-info-100 text-info-600',
        'cumprida' => 'bg-verde-50 text-verde-700',
        'abaixo' => 'bg-perigo-100 text-perigo-600',
        'acima' => 'bg-aviso-100 text-aviso-500',
        'sem_atribuicao' => 'bg-slate-100 text-texto-medio',
        'sem_tempo' => 'bg-slate-50 text-texto-fraco',
    ];
    $classeDiferenca = fn (int $s) => $s < 0 ? 'text-perigo-600' : ($s > 0 ? 'text-aviso-500' : 'text-texto-medio');
    $atribuicoes = $r['atribuicoes'];
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Relatórios', 'Atribuições']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-cabecalho-pagina titulo="Relatórios">
                <x-slot:acoes>
                    @include('livewire.relatorios._topo', ['atual' => 'relatorios.atribuicoes'])
                </x-slot:acoes>
            </x-cabecalho-pagina>

            {{-- Filtros --}}
            <section class="cartao relative z-20 mt-6 flex flex-wrap items-center gap-2 p-4 sm:px-5 print:hidden">
                <span class="mr-1 inline-flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-texto-medio"><x-icone nome="filtro" class="h-3.5 w-3.5" /> Filtros</span>
                @if ($podeVerEquipa)
                    <x-filtro-multiplo rotulo="Equipa" modelo="membros" :opcoes="$opcoes['membros']" :selecionados="$membros" />
                @endif
                <x-filtro-multiplo rotulo="Cliente" modelo="clientes" :opcoes="$opcoes['clientes']" :selecionados="$clientes" />
                <x-filtro-multiplo rotulo="Projeto" modelo="projetos" :opcoes="$opcoes['projetos']" :selecionados="$projetos" />
                @if ($filtrosAtivos > 0)
                    <button type="button" wire:click="limparFiltros" class="px-2 text-sm font-medium text-verde-700 hover:underline">Limpar filtros</button>
                @endif
                @if ($podeGerir)
                    <button type="button" wire:click="nova" class="botao-primario ml-auto"><x-icone nome="mais" traco="2" /> Nova atribuição</button>
                @endif
            </section>

            {{-- Relatório --}}
            <section class="cartao mt-6" x-data="{ abertos: [] }">
                <header class="flex flex-wrap items-center justify-between gap-3 rounded-t-2xl border-b border-borda bg-fundo/80 px-5 py-3">
                    <div class="flex flex-wrap items-center gap-2 print:hidden">
                        <span class="text-xs font-medium text-texto-medio">Agrupar por</span>
                        <select wire:model.live="agrupar1" class="campo-select campo-mini w-auto" aria-label="Agrupar por">
                            @foreach ($agrupamentos as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="agrupar2" class="campo-select campo-mini w-auto" aria-label="Depois por">
                            <option value="">—</option>
                            @foreach ($agrupamentos as $valor => $rotulo)
                                @continue($valor === $agrupar1)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                        <div><span class="text-xs text-texto-medio">Agendado</span> <span class="ml-1 text-lg font-semibold tabular-nums text-texto-forte">{{ $hms($r['agendado']) }}</span></div>
                        <div><span class="text-xs text-texto-medio">Registado</span> <span class="ml-1 text-lg font-semibold tabular-nums text-texto-forte">{{ $hms($r['registado']) }}</span></div>
                    </div>
                </header>

                @if ($grupos === [])
                    <x-estado-vazio icone="calendario" :titulo="$filtrosAtivos > 0 ? 'Nada com estes filtros' : 'Sem atribuições nem horas em projetos'" class="py-16">
                        @if ($podeGerir && $filtrosAtivos === 0)
                            <button type="button" wire:click="nova" class="botao-primario"><x-icone nome="mais" traco="2" /> Nova atribuição</button>
                        @endif
                    </x-estado-vazio>
                @else
                    <div class="relative overflow-x-auto">
                        <table class="tabela min-w-[760px] [&_td]:px-3 [&_th]:px-3">
                            <thead>
                                <tr>
                                    <th>
                                        <span class="flex items-center gap-2">
                                            @if ($comFilhos)
                                                <button type="button" class="botao-icone h-6 w-6 print:hidden" aria-label="Abrir ou fechar todos"
                                                    @click="abertos = abertos.length ? [] : @js(collect($grupos)->pluck('chave')->all())">
                                                    <x-icone nome="seta-dir" traco="2.5" class="h-3 w-3 transition" x-bind:class="abertos.length ? 'rotate-90' : ''" />
                                                </button>
                                            @endif
                                            <button type="button" wire:click="ordenarPor('nome')" class="hover:text-texto-forte">{{ $agrupamentos[$agrupar1] }}{{ $comFilhos ? ' / '.$agrupamentos[$agrupar2] : '' }} {{ $seta('nome') }}</button>
                                        </span>
                                    </th>
                                    <th class="w-32"><button type="button" wire:click="ordenarPor('agendado')" class="block w-full text-right hover:text-texto-forte">Agendado {{ $seta('agendado') }}</button></th>
                                    <th class="w-48"><button type="button" wire:click="ordenarPor('registado')" class="block w-full text-right hover:text-texto-forte">Registado {{ $seta('registado') }}</button></th>
                                    <th class="w-32"><button type="button" wire:click="ordenarPor('diferenca')" class="block w-full text-right hover:text-texto-forte">Diferença {{ $seta('diferenca') }}</button></th>
                                    <th class="w-40">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($grupos as $g)
                                    @include('livewire.relatorios._linha-atribuicao', ['l' => $g, 'filho' => false, 'chavePai' => $g['chave']])
                                    @foreach ($this->ordenarFilhos($g['filhos']) as $f)
                                        @include('livewire.relatorios._linha-atribuicao', ['l' => $f, 'filho' => true, 'chavePai' => $g['chave']])
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($agrupar1 === 'membro' && $podeVerEquipa)
                <div class="mt-4 flex items-center gap-4 print:hidden">
                    <span class="h-px flex-1 bg-borda"></span>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-texto-medio">
                        <input type="checkbox" wire:model.live="semTempo" class="peer sr-only">
                        <span class="relative h-4 w-7 rounded-full bg-slate-300 transition after:absolute after:left-0.5 after:top-0.5 after:h-3 after:w-3 after:rounded-full after:bg-white after:transition peer-checked:bg-verde-600 peer-checked:after:translate-x-3 peer-focus-visible:ring-2 peer-focus-visible:ring-verde-500"></span>
                        Mostrar membros sem tempo
                    </label>
                    <span class="h-px flex-1 bg-borda"></span>
                </div>
            @endif

            {{-- Atribuições do período --}}
            @if ($atribuicoes->isNotEmpty())
                <section class="cartao mt-6">
                    <header class="flex items-center justify-between rounded-t-2xl border-b border-borda bg-fundo/80 px-5 py-3">
                        <h2 class="text-xs font-medium text-texto-medio">Atribuições no período</h2>
                        <span class="text-xs text-texto-fraco">{{ $atribuicoes->count() }}</span>
                    </header>
                    <ul class="divide-y divide-borda">
                        @foreach ($atribuicoes as $a)
                            <li wire:key="atribuicao-{{ $a->id }}" class="flex flex-wrap items-center gap-x-5 gap-y-1 px-5 py-3 text-sm">
                                <span class="w-40 truncate font-medium text-texto-forte">{{ $a->utilizador?->nome ?? '—' }}</span>
                                <span class="inline-flex min-w-0 flex-1 items-center gap-2">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $a->projeto?->cor ?? '#cbd5e1' }}"></span>
                                    <span class="truncate text-texto-forte">{{ $a->projeto?->nome ?? '—' }}</span>
                                    @if ($a->nota)<span class="truncate text-xs text-texto-fraco" title="{{ $a->nota }}">· {{ $a->nota }}</span>@endif
                                </span>
                                <span class="whitespace-nowrap tabular-nums text-texto-medio">{{ $a->de->format('d/m') }} – {{ $a->ate->format('d/m/Y') }}</span>
                                <span class="w-36 whitespace-nowrap text-right tabular-nums text-texto-medio">{{ Horas::hm($a->horas_dia_seg) }}/dia{{ $a->fins_de_semana ? ' · todos os dias' : '' }}</span>
                                <span class="w-20 text-right font-medium tabular-nums text-texto-forte" title="Agendado no período">{{ Horas::hm($a->segundosEntre($de, $ate)) }}</span>
                                @if ($podeGerir)
                                    <span class="flex gap-1.5 print:hidden">
                                        <button type="button" wire:click="editar({{ $a->id }})" class="botao-icone h-8 w-8" aria-label="Alterar atribuição"><x-icone nome="lapis" /></button>
                                        <button type="button" wire:click="apagar({{ $a->id }})" wire:confirm="Apagar esta atribuição?" class="botao-icone-perigo h-8 w-8" aria-label="Apagar atribuição"><x-icone nome="lixo" /></button>
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </main>

    {{-- Nova / alterar atribuição --}}
    @if ($editarId !== null && $podeGerir)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="fechar" role="dialog" aria-modal="true" aria-labelledby="titulo-atribuicao">
            <div class="absolute inset-0" wire:click="fechar"></div>
            <form wire:submit="guardar" class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-atribuicao" class="text-lg font-semibold text-texto-forte">{{ $editarId === 0 ? 'Nova atribuição' : 'Alterar atribuição' }}</h2>
                    <button type="button" wire:click="fechar" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>
                <div class="space-y-4 px-6 py-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="campo-label" for="atribuicao-membro">Membro <span class="text-perigo-500">*</span></label>
                            <select id="atribuicao-membro" wire:model="formulario.utilizador_id" class="campo-select">
                                <option value="">—</option>
                                @foreach ($opcoes['membros'] as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('formulario.utilizador_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="campo-label" for="atribuicao-projeto">Projeto <span class="text-perigo-500">*</span></label>
                            <select id="atribuicao-projeto" wire:model="formulario.projeto_id" class="campo-select">
                                <option value="">—</option>
                                @foreach ($projetosFormulario as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('formulario.projeto_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="campo-label" for="atribuicao-de">De</label>
                            <input id="atribuicao-de" type="date" wire:model="formulario.de" class="campo-input">
                            @error('formulario.de') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="campo-label" for="atribuicao-ate">Até</label>
                            <input id="atribuicao-ate" type="date" wire:model="formulario.ate" class="campo-input">
                            @error('formulario.ate') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="campo-label" for="atribuicao-horas">Horas por dia</label>
                            <div class="relative">
                                <input id="atribuicao-horas" type="text" inputmode="decimal" wire:model="formulario.horas_dia" class="campo-input pr-8 tabular-nums" placeholder="8">
                                <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-texto-fraco">h</span>
                            </div>
                            @error('formulario.horas_dia') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <label class="flex cursor-pointer items-center gap-3 text-sm text-texto-forte">
                        <input type="checkbox" wire:model="formulario.fins_de_semana" class="peer sr-only">
                        <span class="relative h-5 w-9 shrink-0 rounded-full bg-slate-300 transition after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition peer-checked:bg-verde-600 peer-checked:after:translate-x-4 peer-focus-visible:ring-2 peer-focus-visible:ring-verde-500"></span>
                        Incluir fins de semana
                    </label>
                    <div>
                        <label class="campo-label" for="atribuicao-nota">Nota</label>
                        <textarea id="atribuicao-nota" wire:model="formulario.nota" rows="2" class="campo-input"></textarea>
                    </div>
                </div>
                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="fechar" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> {{ $editarId === 0 ? 'Criar' : 'Guardar' }}</button>
                </footer>
            </form>
        </div>
    @endif
</div>

@use('App\Services\Tempos\PainelTempos')
@use('App\Livewire\Concerns\FormularioRegisto')

<div>
    <x-topbar :breadcrumb="['Suporte', 'Cronómetro']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            @if ($erro)
                <div class="mb-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            {{-- Barra: o que se está a fazer --}}
            <section class="cartao p-3 sm:p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <input type="text" wire:model.blur="descricao" class="campo-input campo-barra w-full min-w-[14rem] text-base lg:flex-1" placeholder="Em que está a trabalhar?" aria-label="Descrição">

                    <div class="flex w-full flex-wrap items-center gap-3 lg:w-auto">
                        <select wire:model.live="barraProjeto" class="campo-select campo-barra w-full sm:w-56" aria-label="Projeto do registo">
                            <option value="">Escolher projeto…</option>
                            @foreach ($projetos as $p)
                                <option value="{{ $p->id }}">{{ $p->nome }}{{ $p->cliente ? ' · '.$p->cliente->nome : '' }}</option>
                            @endforeach
                        </select>

                        <div class="relative flex items-center gap-1" x-data="{ aberto: false }" @click.outside="aberto = false" @keydown.escape="aberto = false">
                            <button type="button" @click="aberto = ! aberto" class="botao-quadrado {{ $barraEtiquetas !== '' ? '!border-verde-300 !bg-verde-50 !text-verde-700' : '' }}" title="Etiquetas" aria-label="Etiquetas"><x-icone nome="etiqueta" /></button>
                            <div x-show="aberto" x-cloak class="absolute right-0 top-11 z-30 w-72 rounded-xl border border-borda bg-white p-4 shadow-lg">
                                <label class="campo-label" for="barra-etiquetas">Etiquetas</label>
                                <input id="barra-etiquetas" type="text" wire:model.blur="barraEtiquetas" class="campo-input" placeholder="Separadas por vírgulas">
                            </div>

                            <button type="button" wire:click="$toggle('barraFaturavel')" class="botao-quadrado {{ $barraFaturavel ? '!border-verde-300 !bg-verde-50 !text-verde-700' : '' }}" title="{{ $barraFaturavel ? 'Faturável' : 'Não faturável' }}" aria-label="{{ $barraFaturavel ? 'Faturável' : 'Não faturável' }}" aria-pressed="{{ $barraFaturavel ? 'true' : 'false' }}"><x-icone nome="euro" /></button>
                        </div>

                        <div class="ml-auto flex flex-wrap items-center justify-end gap-2 sm:gap-3">
                            @if ($aCorrer)
                                <div wire:key="a-correr-{{ $aCorrer->id }}" class="tabular-nums text-xl font-semibold text-texto-forte"
                                     x-data="{ inicio: {{ $inicioACorrer }}, agora: Math.floor(Date.now() / 1000) }"
                                     x-init="setInterval(() => agora = Math.floor(Date.now() / 1000), 1000)"
                                     x-text="(() => { const s = Math.max(0, agora - inicio); return [Math.floor(s / 3600), Math.floor(s / 60) % 60, s % 60].map((v, i) => i ? String(v).padStart(2, '0') : v).join(':') })()">0:00:00</div>
                                <button type="button" wire:click="parar" class="botao-perigo h-10 px-5"><x-icone nome="parar" /> Parar</button>
                                <button type="button" wire:click="descartar" wire:confirm="Descartar este cronómetro sem gravar horas?" class="botao-icone h-10 w-10" title="Descartar" aria-label="Descartar cronómetro"><x-icone nome="lixo" /></button>
                            @elseif ($modo === 'manual')
                                <input type="time" wire:model="manualInicio" class="campo-input campo-barra w-[7.5rem] tabular-nums" aria-label="Hora de início">
                                <span class="text-texto-fraco">–</span>
                                <input type="time" wire:model="manualFim" class="campo-input campo-barra w-[7.5rem] tabular-nums" aria-label="Hora de fim">
                                <button type="button" wire:click="acrescentarManual" class="botao-primario h-10 px-5"><x-icone nome="mais" traco="2" /> Acrescentar</button>
                                <button type="button" wire:click="$set('modo', 'cronometro')" class="botao-icone h-10 w-10" title="Usar o cronómetro" aria-label="Usar o cronómetro"><x-icone nome="relogio" /></button>
                            @else
                                <button type="button" wire:click="comecar" class="botao-primario h-10 px-5"><x-icone nome="play" /> Começar</button>
                                <button type="button" wire:click="$set('modo', 'manual')" class="botao-icone h-10 w-10" title="Meter as horas à mão" aria-label="Meter as horas à mão"><x-icone nome="lapis" /></button>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- Semana --}}
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center rounded-lg border border-borda bg-white">
                        <button type="button" wire:click="semanaAnterior" class="h-[38px] rounded-l-lg px-2.5 text-texto-medio hover:bg-fundo" aria-label="Semana anterior"><x-icone nome="seta-esq" traco="2" /></button>
                        <span class="inline-flex h-[38px] min-w-[11rem] items-center justify-center border-x border-borda px-3 text-sm text-texto-forte">{{ $rotuloSemana }}</span>
                        <button type="button" wire:click="semanaSeguinte" class="h-[38px] rounded-r-lg px-2.5 text-texto-medio hover:bg-fundo" aria-label="Semana seguinte"><x-icone nome="seta-dir" traco="2" /></button>
                    </div>
                    @unless ($estaSemana)
                        <button type="button" wire:click="estaSemana" class="botao-secundario">Hoje</button>
                    @endunless
                    {{-- Filtro da lista — o projeto da barra lá em cima é o do registo a começar (notas §46). --}}
                    <select wire:model.live="filtroProjeto" class="campo-select campo-barra w-full sm:w-56 {{ $filtroProjeto !== '' ? '!border-verde-300 !bg-verde-50 text-verde-800' : '' }}" aria-label="Filtrar os registos por projeto">
                        <option value="">Todos os projetos</option>
                        <option value="0">Sem projeto</option>
                        @foreach ($projetosFiltro as $id => $nome)
                            <option value="{{ $id }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="text-sm text-texto-medio">{{ $filtroProjeto !== '' ? 'Total do filtro' : 'Total da semana' }} <span class="ml-1 text-lg font-semibold tabular-nums text-texto-forte">{{ PainelTempos::hms($totalSemana) }}</span></div>
            </div>

            {{-- Registos por dia --}}
            <div class="mt-4 space-y-4">
                @forelse ($dias as $grupo)
                    <section class="cartao overflow-hidden p-0">
                        <header class="flex items-center justify-between border-b border-borda bg-fundo/60 px-4 py-2.5 sm:px-5">
                            <h2 class="text-sm font-semibold text-texto-forte">
                                {{ ucfirst($grupo['dia']->locale('pt_PT')->isoFormat('dddd, D [de] MMMM')) }}
                                @if ($grupo['dia']->isToday()) <span class="ml-2 rounded-full bg-verde-100 px-2 py-0.5 text-[11px] font-medium text-verde-700">Hoje</span> @endif
                            </h2>
                            <span class="text-sm font-semibold tabular-nums text-texto-forte">{{ PainelTempos::hms($grupo['total']) }}</span>
                        </header>

                        <ul class="divide-y divide-borda">
                            @foreach ($grupo['registos'] as $r)
                                @php($horas = FormularioRegisto::temHorasReais($r))
                                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-fundo/50 sm:px-5" wire:key="r-{{ $r->id }}">
                                    <button type="button" wire:click="editar({{ $r->id }})" class="min-w-0 flex-1 text-left">
                                        <div class="truncate text-sm font-medium text-texto-forte">{{ $r->descricao ?: 'Sem descrição' }}</div>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-texto-medio">
                                            @if ($r->projeto)
                                                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" style="background: {{ $r->projeto->cor }}"></span>{{ $r->projeto->nome }}</span>
                                                @if ($r->projeto->cliente) <span class="text-texto-fraco">· {{ $r->projeto->cliente->nome }}</span> @endif
                                            @else
                                                <span class="text-texto-fraco">Sem projeto</span>
                                            @endif
                                            @foreach ($r->etiquetas as $etiqueta)
                                                <span class="rounded-full bg-fundo px-2 py-0.5 text-[11px] text-texto-medio">{{ $etiqueta }}</span>
                                            @endforeach
                                        </div>
                                    </button>

                                    <div class="flex items-center gap-3 sm:gap-4">
                                        <span class="{{ $r->faturavel ? 'text-verde-600' : 'text-texto-fraco' }}" title="{{ $r->faturavel ? 'Faturável' : 'Não faturável' }}"><x-icone nome="euro" class="h-4 w-4" /></span>
                                        @if ($horas)
                                            <span class="hidden tabular-nums text-xs text-texto-medio sm:inline">{{ $r->inicio->setTimezone(config('tempos.fuso'))->format('H:i') }} – {{ $r->fim->setTimezone(config('tempos.fuso'))->format('H:i') }}</span>
                                        @endif
                                        <span class="w-20 text-right text-sm font-semibold tabular-nums text-texto-forte">{{ PainelTempos::hms((int) $r->duracao_seg) }}</span>
                                        <button type="button" wire:click="continuar({{ $r->id }})" class="botao-icone h-8 w-8 text-verde-700" title="Continuar" aria-label="Continuar este trabalho"><x-icone nome="play" /></button>

                                        <div class="relative" x-data="menuFlutuante('direita')" @keydown.escape="fechar()">
                                            <button type="button" x-ref="botao" @click="alternar()" class="botao-icone h-8 w-8" aria-label="Opções do registo" :aria-expanded="aberto"><x-icone nome="mais-opcoes" /></button>
                                            <div x-ref="menu" x-show="aberto" x-cloak :style="estilo" class="fixed z-40 w-44 overflow-hidden rounded-xl border border-borda bg-white py-1 text-sm shadow-lg" role="menu">
                                                <button type="button" wire:click="editar({{ $r->id }})" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="lapis" /> Alterar</button>
                                                <button type="button" wire:click="duplicar({{ $r->id }})" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-fundo" role="menuitem"><x-icone nome="duplicar" /> Duplicar</button>
                                                <button type="button" wire:click="apagar({{ $r->id }})" wire:confirm="Apagar este registo?" @click="fechar()" class="flex w-full items-center gap-2 px-4 py-2 text-left text-perigo-600 hover:bg-perigo-100" role="menuitem"><x-icone nome="lixo" /> Apagar</button>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @empty
                    <section class="cartao">
                        @if ($filtroProjeto !== '')
                            <x-estado-vazio icone="filtro" titulo="Sem horas deste projeto nesta semana" class="py-16">
                                <button type="button" wire:click="$set('filtroProjeto', '')" class="botao-secundario">Ver todos os projetos</button>
                            </x-estado-vazio>
                        @else
                            <x-estado-vazio icone="relogio" titulo="Sem horas nesta semana" class="py-16">
                                <button type="button" wire:click="novo" class="botao-primario"><x-icone nome="mais" traco="2" /> Acrescentar tempo</button>
                            </x-estado-vazio>
                        @endif
                    </section>
                @endforelse
            </div>
        </div>
    </main>

    @include('livewire.partials.formulario-registo')
</div>

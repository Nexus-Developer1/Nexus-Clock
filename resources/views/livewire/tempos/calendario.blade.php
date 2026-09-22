@use('App\Services\Tempos\PainelTempos')

<div>
    <x-topbar :breadcrumb="['Tempos', 'Calendário']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            @if ($erro)
                <div class="mb-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <div class="flex items-center rounded-lg border border-borda bg-white">
                        <button type="button" wire:click="semanaAnterior" class="h-[38px] rounded-l-lg px-2.5 text-texto-medio hover:bg-fundo" aria-label="Semana anterior"><x-icone nome="seta-esq" traco="2" /></button>
                        <span class="inline-flex h-[38px] min-w-[11rem] items-center justify-center border-x border-borda px-3 text-sm text-texto-forte">{{ $rotuloSemana }}</span>
                        <button type="button" wire:click="semanaSeguinte" class="h-[38px] rounded-r-lg px-2.5 text-texto-medio hover:bg-fundo" aria-label="Semana seguinte"><x-icone nome="seta-dir" traco="2" /></button>
                    </div>
                    @unless ($estaSemana)
                        <button type="button" wire:click="estaSemana" class="botao-secundario">Hoje</button>
                    @endunless
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-texto-medio">Total da semana <span class="ml-1 text-lg font-semibold tabular-nums text-texto-forte">{{ PainelTempos::hms($totalSemana) }}</span></span>
                    <button type="button" wire:click="novo" class="botao-primario"><x-icone nome="mais" traco="2" /> Acrescentar tempo</button>
                </div>
            </div>

            <section class="cartao mt-6 overflow-hidden p-0">
                {{-- Cabeçalho dos dias --}}
                <div class="flex border-b border-borda bg-fundo/60 pr-[10px]">
                    <div class="w-14 shrink-0"></div>
                    @foreach ($dias as $dia)
                        <div class="min-w-0 flex-1 border-l border-borda px-2 py-2 text-center">
                            <div class="text-xs uppercase tracking-wide text-texto-fraco">{{ ucfirst($dia['data']->locale('pt_PT')->isoFormat('ddd')) }}</div>
                            <div class="mt-0.5 flex items-center justify-center gap-2">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full text-sm font-semibold {{ $dia['data']->isToday() ? 'bg-verde-700 text-white' : 'text-texto-forte' }}">{{ $dia['data']->format('d') }}</span>
                                @if ($dia['total'])
                                    <span class="text-xs font-medium tabular-nums text-texto-medio">{{ PainelTempos::hms($dia['total']) }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Registos só com duração (sem horas) --}}
                @if ($dias->contains(fn ($d) => $d['semHoras']->isNotEmpty()))
                    <div class="flex border-b border-borda bg-white pr-[10px]">
                        <div class="flex w-14 shrink-0 items-center justify-end pr-2 text-[11px] text-texto-fraco">Sem horas</div>
                        @foreach ($dias as $dia)
                            <div class="min-w-0 flex-1 space-y-1 border-l border-borda p-1">
                                @foreach ($dia['semHoras'] as $r)
                                    <button type="button" wire:click="editar({{ $r->id }})" wire:key="sh-{{ $r->id }}"
                                            class="block w-full truncate rounded-md border-l-[3px] bg-fundo px-2 py-1 text-left text-[11px] text-texto-forte hover:bg-verde-50"
                                            style="border-color: {{ $r->projeto?->cor ?? '#16a34a' }}">
                                        {{ PainelTempos::hms((int) $r->duracao_seg) }} · {{ $r->descricao ?: ($r->projeto?->nome ?? 'Sem descrição') }}
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Grelha das horas --}}
                <div class="max-h-[68vh] overflow-y-auto" x-data x-init="$el.scrollTop = {{ 8 * $alturaHora }}">
                    <div class="flex">
                        <div class="w-14 shrink-0">
                            @foreach (range(0, 23) as $h)
                                <div class="relative border-b border-transparent text-right" style="height: {{ $alturaHora }}px">
                                    <span class="absolute -top-2 right-2 text-[11px] tabular-nums text-texto-fraco">{{ $h ? sprintf('%02d:00', $h) : '' }}</span>
                                </div>
                            @endforeach
                        </div>

                        @foreach ($dias as $dia)
                            <div class="relative min-w-0 flex-1 select-none border-l border-borda {{ $dia['data']->isToday() ? 'bg-verde-50/40' : '' }}"
                                 style="height: {{ 24 * $alturaHora }}px; background-image: repeating-linear-gradient(to bottom, var(--linha-hora) 0 1px, transparent 1px {{ $alturaHora }}px);"
                                 wire:key="col-{{ $dia['data']->toDateString() }}"
                                 x-data="{
                                     altura: {{ $alturaHora }},
                                     arrastar: false,
                                     de: 0,
                                     ate: 0,
                                     minuto(e) {
                                         const r = $el.getBoundingClientRect();
                                         const m = Math.round((e.clientY - r.top) / this.altura * 60 / 15) * 15;
                                         return Math.min(1440, Math.max(0, m));
                                     },
                                     hhmm(m) { return String(Math.floor(m / 60) % 24).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); },
                                     comecar(e) { this.de = this.minuto(e); this.ate = this.de + 15; this.arrastar = true; },
                                     mover(e) { if (this.arrastar) this.ate = Math.max(this.de + 15, this.minuto(e)); },
                                     largar() {
                                         if (! this.arrastar) return;
                                         this.arrastar = false;
                                         const fim = Math.min(this.ate, 1425);
                                         $wire.novo({ dia: '{{ $dia['data']->toDateString() }}', hora_inicio: this.hhmm(this.de), hora_fim: this.hhmm(fim) });
                                     },
                                 }"
                                 @mousedown.prevent="comecar($event)"
                                 @mousemove="mover($event)"
                                 @mouseup.window="largar()">

                                {{-- A arrastar --}}
                                <div x-show="arrastar" x-cloak class="pointer-events-none absolute inset-x-1 rounded-md border border-verde-400 bg-verde-100/80 px-1 text-[11px] text-verde-800"
                                     :style="`top: ${de / 60 * altura}px; height: ${(ate - de) / 60 * altura}px`">
                                    <span x-text="hhmm(de) + ' – ' + hhmm(ate)"></span>
                                </div>

                                @foreach ($dia['blocos'] as $b)
                                    @php($r = $b['registo'])
                                    <button type="button" wire:click="editar({{ $r->id }})" wire:key="b-{{ $r->id }}" @mousedown.stop
                                            class="absolute overflow-hidden rounded-md border-l-[3px] bg-white/95 px-1.5 py-1 text-left shadow-sm ring-1 ring-borda hover:ring-verde-400"
                                            style="top: {{ $b['minuto'] / 60 * $alturaHora }}px;
                                                   height: {{ max(18, $b['minutos'] / 60 * $alturaHora - 2) }}px;
                                                   left: calc({{ $b['coluna'] / $b['colunas'] * 100 }}% + 2px);
                                                   width: calc({{ 100 / $b['colunas'] }}% - 4px);
                                                   border-color: {{ $r->projeto?->cor ?? '#16a34a' }}">
                                        <span class="block truncate text-[11px] font-medium text-texto-forte">{{ $r->descricao ?: 'Sem descrição' }}</span>
                                        @if ($b['minutos'] >= 45)
                                            <span class="block truncate text-[11px] text-texto-medio">{{ $b['inicio'] }} – {{ $b['fim'] }}</span>
                                            <span class="block truncate text-[11px] text-texto-fraco">{{ $r->projeto?->nome }}{{ $r->projeto?->cliente ? ' · '.$r->projeto->cliente->nome : '' }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <p class="mt-3 text-xs text-texto-fraco">Arraste numa coluna para acrescentar tempo; carregue num bloco para o alterar.</p>
        </div>
    </main>

    @include('livewire.partials.formulario-registo')
</div>

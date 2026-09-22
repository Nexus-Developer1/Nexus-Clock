@php
    // "Seg–Sex", "Seg, Qua, Sex" ou "Todos os dias".
    $descreverDias = function (array $d) use ($dias) {
        sort($d);
        if (count($d) === 7) {
            return 'todos os dias';
        }
        if (count($d) > 2 && $d === range($d[0], end($d))) {
            return $dias[$d[0]].'–'.$dias[end($d)];
        }

        return implode(', ', array_map(fn ($n) => $dias[$n], $d));
    };
    $nomesGrupos = $grupos->pluck('nome', 'id');
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Equipa', 'Lembretes']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-equipa-separadores atual="equipa.lembretes">
                <x-slot:acoes>
                    <button type="button" wire:click="novo" class="botao-primario"><x-icone nome="mais" traco="2" /> Novo lembrete</button>
                </x-slot:acoes>
            </x-equipa-separadores>

            @if ($erro)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600"><x-icone nome="aviso" class="shrink-0" /> {{ $erro }}</div>
            @endif

            <div class="mt-6 space-y-3">
                @forelse ($lembretes as $l)
                    <article wire:key="lembrete-{{ $l->id }}" class="cartao flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4 {{ $l->ativo ? '' : 'opacity-60' }}">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium text-texto-forte">
                                Menos de {{ str_replace('.', ',', rtrim(rtrim(number_format($l->horas_minimas, 2, '.', ''), '0'), '.')) }} h {{ $l->periodo === 'semana' ? 'na semana anterior' : 'no dia anterior' }}
                            </div>
                            <div class="mt-0.5 text-sm text-texto-medio">
                                {{ ucfirst($descreverDias($l->dias)) }} às {{ sprintf('%02d:00', $l->hora) }}
                                · {{ $l->destinatarios === 'todos' ? 'todos os membros' : 'grupos: '.collect($l->grupos)->map(fn ($id) => $nomesGrupos[$id] ?? null)->filter()->implode(', ') }}
                                @if ($l->enviado_em) · <span class="text-texto-fraco">último envio {{ $l->enviado_em->format('d/m') }}</span>@endif
                            </div>
                        </div>
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-texto-medio">
                            <input type="checkbox" wire:click="alternar({{ $l->id }})" @checked($l->ativo) class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
                            {{ $l->ativo ? 'Ativo' : 'Desligado' }}
                        </label>
                        <div class="flex gap-1.5">
                            <button type="button" wire:click="editar({{ $l->id }})" class="botao-icone h-8 w-8" title="Alterar" aria-label="Alterar lembrete"><x-icone nome="lapis" /></button>
                            <button type="button" wire:click="apagar({{ $l->id }})" wire:confirm="Apagar este lembrete?" class="botao-icone-perigo h-8 w-8" title="Apagar" aria-label="Apagar lembrete"><x-icone nome="lixo" /></button>
                        </div>
                    </article>
                @empty
                    <div class="cartao">
                        <x-estado-vazio icone="relogio" titulo="Ainda sem lembretes">
                            <button type="button" wire:click="novo" class="botao-primario"><x-icone nome="mais" traco="2" /> Novo lembrete</button>
                        </x-estado-vazio>
                    </div>
                @endforelse
            </div>
        </div>
    </main>

    @if ($aEditar)
        <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" role="dialog" aria-modal="true" aria-labelledby="titulo-lembrete">
            <div class="absolute inset-0" wire:click="fechar"></div>
            <form wire:submit="guardar" class="janela relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                    <h2 id="titulo-lembrete" class="text-lg font-semibold text-texto-forte">{{ $lembreteId ? 'Alterar lembrete' : 'Novo lembrete' }}</h2>
                    <button type="button" wire:click="fechar" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
                </header>

                <div class="space-y-5 px-6 py-5">
                    <div>
                        <span class="campo-label">Avisar quem registou menos de</span>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="relative w-28">
                                <input type="text" inputmode="decimal" wire:model="formulario.horas_minimas" class="campo-input pr-8 tabular-nums" aria-label="Horas mínimas">
                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-texto-fraco">h</span>
                            </div>
                            <select wire:model="formulario.periodo" class="campo-select w-auto" aria-label="Período">
                                <option value="dia">no dia anterior</option>
                                <option value="semana">na semana anterior</option>
                            </select>
                        </div>
                        @error('formulario.horas_minimas') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <span class="campo-label">Dias</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($dias as $n => $rotulo)
                                <label class="cursor-pointer">
                                    <input type="checkbox" wire:model.live="formulario.dias" value="{{ $n }}" class="peer sr-only">
                                    <span class="inline-flex h-9 w-12 items-center justify-center rounded-lg border border-borda text-sm font-medium text-texto-medio transition peer-checked:border-verde-600 peer-checked:bg-verde-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-verde-500">{{ $rotulo }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('formulario.dias') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="campo-label" for="lembrete-hora">À hora</label>
                        <select id="lembrete-hora" wire:model="formulario.hora" class="campo-select w-32">
                            @for ($h = 0; $h < 24; $h++)
                                <option value="{{ $h }}">{{ sprintf('%02d:00', $h) }}</option>
                            @endfor
                        </select>
                        @error('formulario.hora') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <span class="campo-label">A quem</span>
                        <div class="segmentos" role="group" aria-label="A quem">
                            <button type="button" wire:click="$set('formulario.destinatarios', 'todos')" class="segmento {{ ($formulario['destinatarios'] ?? 'todos') === 'todos' ? 'segmento-ativo' : '' }}">Todos os membros</button>
                            <button type="button" wire:click="$set('formulario.destinatarios', 'grupos')" class="segmento {{ ($formulario['destinatarios'] ?? '') === 'grupos' ? 'segmento-ativo' : '' }}">Só alguns grupos</button>
                        </div>
                        @if (($formulario['destinatarios'] ?? '') === 'grupos')
                            @if ($grupos->isEmpty())
                                <p class="mt-2 text-sm text-texto-medio">Ainda não há grupos. <a href="{{ route('equipa.grupos') }}" wire:navigate class="font-medium text-verde-700 underline">Criar grupos</a></p>
                            @else
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($grupos as $g)
                                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-full border border-borda px-3 py-1.5 text-sm hover:bg-fundo">
                                            <input type="checkbox" wire:model="formulario.grupos" value="{{ $g->id }}" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500">
                                            {{ $g->nome }}
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @error('formulario.grupos') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm text-texto-forte">
                        <input type="checkbox" wire:model="formulario.ativo" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500"> Ativo
                    </label>
                </div>

                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <button type="button" wire:click="fechar" class="botao-secundario">Cancelar</button>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Guardar</button>
                </footer>
            </form>
        </div>
    @endif
</div>

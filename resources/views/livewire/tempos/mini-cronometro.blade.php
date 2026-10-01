{{-- Cronómetro pequeno (notas §69): no canto inferior direito, ou a ocupar a janela por cima de tudo. --}}
<div x-data="miniCronometro" @cronometro-mudou-fora.window="$wire.$refresh()"
     @if ($janela)
         class="flex min-h-screen flex-col justify-center bg-white p-3"
     @else
         x-show="! naJanela" x-cloak @janela-cronometro.window="naJanela = $event.detail"
         class="fixed bottom-4 right-4 z-20 print:hidden"
     @endif
>
    @unless ($janela)
        {{-- Minimizado: só um botão redondo com o tempo a correr. --}}
        <button type="button" x-show="minimizado" @click="alternar()"
                class="flex items-center gap-2 rounded-full border border-borda bg-white px-4 py-2.5 text-sm font-semibold text-texto-forte shadow-lg transition hover:border-verde-300"
                title="Abrir o cronómetro" aria-label="Abrir o cronómetro">
            @if ($aCorrer)
                <span class="h-2 w-2 animate-pulse rounded-full bg-verde-500" aria-hidden="true"></span>
                <span wire:key="mini-pilula-{{ $aCorrer->id }}" class="tabular-nums" x-text="tempo({{ $aCorrer->inicio->getTimestamp() }})">0:00:00</span>
            @else
                <x-icone nome="relogio" class="h-5 w-5 text-verde-600" /> Cronómetro
            @endif
        </button>
    @endunless

    <div @unless ($janela) x-show="! minimizado" class="w-80 max-w-[calc(100vw-2rem)] rounded-2xl border border-borda bg-white p-4 shadow-xl" @endunless>
        @unless ($janela)
            <div class="mb-3 flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-texto-forte">Cronómetro</span>
                <div class="flex items-center gap-1">
                    <button type="button" x-show="podeJanela" x-cloak @click="abrirJanela('{{ route('cronometro.janela') }}')"
                            class="rounded-lg p-1.5 text-texto-medio transition hover:bg-fundo hover:text-texto-forte"
                            title="Abrir numa janela que fica por cima dos outros programas" aria-label="Abrir numa janela por cima de tudo"><x-icone nome="janela" /></button>
                    <button type="button" @click="alternar()" class="rounded-lg p-1.5 text-texto-medio transition hover:bg-fundo hover:text-texto-forte"
                            title="Minimizar" aria-label="Minimizar o cronómetro"><x-icone nome="minimizar" /></button>
                </div>
            </div>
        @endunless

        @if ($aCorrer)
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <div wire:key="mini-tempo-{{ $aCorrer->id }}" class="tabular-nums text-2xl font-semibold text-texto-forte" x-text="tempo({{ $aCorrer->inicio->getTimestamp() }})">0:00:00</div>
                    <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-texto-medio">
                        @if ($aCorrer->projeto)
                            <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $aCorrer->projeto->cor ?: '#9CA3AF' }}" aria-hidden="true"></span>
                            <span class="truncate">{{ $aCorrer->projeto->nome }}</span>
                        @else
                            <span class="truncate">Sem projeto</span>
                        @endif
                    </div>
                    @if ($aCorrer->descricao)
                        <div class="mt-0.5 truncate text-xs text-texto-fraco" title="{{ $aCorrer->descricao }}">{{ $aCorrer->descricao }}</div>
                    @endif
                </div>
                <button type="button" wire:click="parar" class="botao-perigo h-10 shrink-0 px-4"><x-icone nome="parar" /> Parar</button>
            </div>
        @else
            <form wire:submit="comecar" class="space-y-2">
                <input type="text" wire:model="descricao" maxlength="1000" class="campo-input" placeholder="Em que está a trabalhar?" aria-label="Descrição">
                <div class="flex items-center gap-2">
                    <select wire:model="projeto" class="campo-select min-w-0 flex-1" aria-label="Projeto">
                        <option value="">Sem projeto</option>
                        @foreach ($projetos as $id => $nome)
                            <option value="{{ $id }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="botao-primario h-10 shrink-0 px-4"><x-icone nome="play" /> Começar</button>
                </div>
            </form>
        @endif

        @if ($erro)
            <p class="mt-2 text-xs text-perigo-600">{{ $erro }}</p>
        @elseif ($aviso)
            <p wire:key="mini-aviso-{{ uniqid() }}" x-data="{ v: true }" x-init="setTimeout(() => v = false, 4000)" x-show="v" x-transition.opacity
               class="mt-2 text-xs font-medium text-verde-700">{{ $aviso }}</p>
        @endif
    </div>
</div>

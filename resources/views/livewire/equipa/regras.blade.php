@php
    $explicacao = [
        'projeto' => 'Cada registo tem de ter um projeto.',
        'descricao' => 'Cada registo tem de dizer em que se trabalhou.',
        'etiquetas' => 'Cada registo tem de ter pelo menos uma etiqueta.',
    ];
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Equipa', 'Regras']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-equipa-separadores atual="equipa.regras" />

            <section class="cartao mt-6 max-w-2xl">
                <header class="border-b border-borda px-5 py-4">
                    <h2 class="text-base font-semibold text-texto-forte">Campos obrigatórios</h2>
                    <p class="mt-1 text-sm text-texto-medio">
                        O que tem de estar preenchido em cada registo de horas. Vale para toda a gente, incluindo quem gere a equipa.
                        O cronómetro pode começar sem estes campos; para o parar, ou para gravar um registo, têm de estar preenchidos.
                    </p>
                </header>

                <form wire:submit="guardar">
                    <ul class="divide-y divide-borda">
                        @foreach ($disponiveis as $campo => $rotulo)
                            <li>
                                <label class="flex items-start gap-3 px-5 py-4 {{ $podeGerir ? 'cursor-pointer hover:bg-fundo' : '' }}">
                                    <input type="checkbox" wire:model="campos" value="{{ $campo }}" @disabled(! $podeGerir)
                                        class="mt-0.5 h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500 disabled:opacity-60">
                                    <span>
                                        <span class="block text-sm font-medium text-texto-forte">{{ $rotulo }}</span>
                                        <span class="block text-sm text-texto-medio">{{ $explicacao[$campo] }}</span>
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>

                    @if ($podeGerir)
                        <footer class="flex justify-end border-t border-borda bg-fundo/50 px-5 py-4">
                            <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Guardar</button>
                        </footer>
                    @else
                        <p class="border-t border-borda px-5 py-4 text-sm text-texto-medio">Só quem gere a equipa muda estas regras.</p>
                    @endif
                </form>
            </section>
        </div>
    </main>
</div>

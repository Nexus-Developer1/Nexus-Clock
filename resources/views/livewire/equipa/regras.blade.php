@php
    // O que cada regra quer dizer, por sítio e campo.
    $explicacao = [
        'registos' => [
            'projeto' => 'Cada registo tem de ter um projeto.',
            'descricao' => 'Cada registo tem de dizer em que se trabalhou.',
            'etiquetas' => 'Cada registo tem de ter pelo menos uma etiqueta.',
        ],
        'despesas' => [
            'recibo' => 'Não se grava uma despesa sem o recibo (PDF ou fotografia).',
            'projeto' => 'Cada despesa tem de estar ligada a um projeto.',
        ],
        'clientes' => [
            'email' => 'Cada cliente tem de ter email de contacto.',
            'morada' => 'Cada cliente tem de ter morada.',
            'nif' => 'Cada cliente tem de ter NIF.',
        ],
        'projetos' => [
            'cliente' => 'Cada projeto tem de pertencer a um cliente.',
            'estimativa' => 'Cada projeto tem de ter as horas previstas.',
        ],
    ];
    $sempre = [
        'registos' => 'O cronómetro pode começar sem estes campos; para o parar, ou para gravar um registo, têm de estar preenchidos.',
        'despesas' => 'A data, o valor e a categoria são sempre obrigatórios.',
        'clientes' => 'O nome é sempre obrigatório.',
        'projetos' => 'O nome é sempre obrigatório.',
    ];
@endphp

<div>
    <x-topbar :breadcrumb="['Suporte', 'Equipa', 'Regras']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">

            <x-toast-sucesso />

            <x-equipa-separadores atual="equipa.regras" />

            <form wire:submit="guardar" class="mt-6 max-w-3xl">
                <div class="cartao">
                    <header class="border-b border-borda px-5 py-4">
                        <h2 class="text-base font-semibold text-texto-forte">Campos obrigatórios</h2>
                        <p class="mt-1 text-sm text-texto-medio">
                            O que tem de estar preenchido ao gravar. Vale para toda a gente, incluindo quem gere a equipa.
                            Ao alterar algo que já existe e ficou incompleto, também é preciso preencher.
                        </p>
                    </header>

                    @foreach ($areas as $area => $info)
                        <section class="border-b border-borda last:border-b-0">
                            <div class="px-5 pt-4">
                                <h3 class="text-sm font-semibold text-texto-forte">{{ $info['titulo'] }}</h3>
                                <p class="mt-0.5 text-xs text-texto-fraco">{{ $sempre[$area] }}</p>
                            </div>
                            <ul class="pb-2">
                                @foreach ($info['campos'] as $campo => $rotulo)
                                    <li wire:key="regra-{{ $area }}-{{ $campo }}">
                                        <label class="flex items-start gap-3 px-5 py-2.5 {{ $podeGerir ? 'cursor-pointer hover:bg-fundo' : '' }}">
                                            <input type="checkbox" wire:model="campos.{{ $area }}" value="{{ $campo }}" @disabled(! $podeGerir)
                                                class="mt-0.5 h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500 disabled:opacity-60">
                                            <span>
                                                <span class="block text-sm font-medium text-texto-forte">{{ $rotulo }}</span>
                                                <span class="block text-sm text-texto-medio">{{ $explicacao[$area][$campo] }}</span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach

                    @if ($podeGerir)
                        <footer class="flex justify-end border-t border-borda bg-fundo/50 px-5 py-4">
                            <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Guardar</button>
                        </footer>
                    @else
                        <p class="border-t border-borda px-5 py-4 text-sm text-texto-medio">Só quem gere a equipa muda estas regras.</p>
                    @endif
                </div>
            </form>
        </div>
    </main>
</div>

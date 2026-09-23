<div>
    <x-topbar :breadcrumb="[['label' => 'Suporte'], ['label' => 'Clientes', 'url' => route('clientes')], 'Novo cliente']" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-2xl">

            <x-cabecalho-pagina titulo="Novo cliente">
                <x-slot:acoes>
                    <a href="{{ route('clientes') }}" wire:navigate class="botao-secundario"><x-icone nome="seta-esq" traco="2" /> Clientes</a>
                </x-slot:acoes>
            </x-cabecalho-pagina>

            <form wire:submit="guardar" class="cartao mt-6 overflow-hidden" x-data x-init="$nextTick(() => $refs.nome?.focus())">
                <div class="space-y-4 px-6 py-5">
                    @include('livewire.partials.campos-cliente')
                </div>

                <footer class="flex items-center justify-end gap-3 border-t border-borda bg-fundo/50 px-6 py-4">
                    <a href="{{ route('clientes') }}" wire:navigate class="botao-secundario">Cancelar</a>
                    <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> Criar cliente</button>
                </footer>
            </form>
        </div>
    </main>
</div>

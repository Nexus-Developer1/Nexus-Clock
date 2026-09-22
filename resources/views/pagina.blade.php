{{-- Página ainda por fazer: só o título. Recebe $ativo, $titulo e, opcionalmente, $seccao (breadcrumb). --}}
<x-layouts.app :ativo="$ativo" :titulo="$titulo">
    <x-topbar :breadcrumb="array_values(array_filter(['Suporte', $seccao ?? null, $titulo]))" />

    <main class="flex-1 px-4 py-6 sm:px-10 sm:py-9">
        <div class="mx-auto max-w-7xl">
            <x-cabecalho-pagina :titulo="$titulo" />

            <section class="cartao mt-6">
                <x-estado-vazio icone="lista" :titulo="'Sem '.mb_strtolower($titulo, 'UTF-8')" class="py-16" />
            </section>
        </div>
    </main>
</x-layouts.app>

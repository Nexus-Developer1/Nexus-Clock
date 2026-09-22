@props(['titulo'])

{{-- Cabeçalho comum das páginas: título e ações à direita. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-between gap-4']) }}>
    <h1 class="text-3xl font-semibold tracking-tight text-texto-forte">{{ $titulo }}</h1>
    @isset($acoes)
        <div class="flex flex-wrap items-center gap-2">{{ $acoes }}</div>
    @endisset
</div>

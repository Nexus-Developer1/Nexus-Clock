@props(['icone' => 'info', 'titulo'])

{{-- Estado vazio: ícone, título e (no slot) as ações. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center px-6 py-12 text-center']) }}>
    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-verde-50 text-verde-600">
        <x-icone :nome="$icone" class="h-7 w-7" />
    </span>
    <p class="mt-4 text-base font-medium text-texto-forte">{{ $titulo }}</p>
    @if ($slot->isNotEmpty())
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>

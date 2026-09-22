@props(['breadcrumb' => []])

{{-- Barra superior reutilizável: breadcrumb + ações (slot). No telemóvel faz wrap (o breadcrumb
     em cima, as ações por baixo). Igual à da Nexus Infra. Um item pode ser ['label' => …, 'url' => …]. --}}
<header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-b border-borda bg-white px-4 py-4 sm:px-10 sm:py-5 shadow-topbar">
    <nav class="flex min-w-0 items-center gap-2 text-sm">
        @foreach ($breadcrumb as $item)
            @if (! $loop->first)
                <span class="text-texto-fraco">/</span>
            @endif
            @php
                $rotulo = is_array($item) ? ($item['label'] ?? '') : $item;
                $url = is_array($item) ? ($item['url'] ?? null) : null;
            @endphp
            @if ($url && ! $loop->last)
                <a href="{{ $url }}" wire:navigate class="text-texto-medio transition hover:text-texto-forte hover:underline">{{ $rotulo }}</a>
            @else
                <span class="{{ $loop->last ? 'font-medium text-texto-forte' : 'text-texto-medio' }}">{{ $rotulo }}</span>
            @endif
        @endforeach
    </nav>
    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
        {{ $slot }}
    </div>
</header>

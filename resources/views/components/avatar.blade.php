@props(['nome' => '', 'tom' => 'escuro'])

@php
    $iniciais = \Illuminate\Support\Str::of((string) $nome)->explode(' ')->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    $cores = $tom === 'claro' ? 'bg-verde-50 text-verde-700' : 'bg-verde-900 text-white';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold $cores"]) }} aria-hidden="true">{{ mb_strtoupper($iniciais) ?: '–' }}</span>

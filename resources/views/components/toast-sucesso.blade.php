{{-- Toast fixo de sucesso: ao fundo e ao centro, POR CIMA de tudo e seja qual for o scroll —
     desaparece sozinho ao fim de 5s (ou no ×). Substitui os banners no topo das páginas, que
     se perdiam quando o botão "Guardar…" estava lá em baixo (pedido do Davide, 2026-08-31). --}}
@props(['mensagem' => null])
{{-- $mensagem: texto próprio (ex.: barra do cronómetro), em vez do flash da sessão. --}}
@php $texto = $mensagem ?? session('sucesso'); @endphp
@if ($texto)
    <div wire:key="toast-sucesso-{{ uniqid() }}"
         x-data="{ aberto: true }" x-init="setTimeout(() => aberto = false, 5000)" x-show="aberto"
         x-transition.opacity.duration.300ms
         class="fixed bottom-6 left-1/2 z-[60] w-max max-w-[calc(100vw-2rem)] -translate-x-1/2">
        <div class="flex items-center gap-2 rounded-lg border border-verde-200 bg-verde-50 px-4 py-3 text-sm font-medium text-verde-700 shadow-lg">
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <span>{{ $texto }}</span>
            <button type="button" @click="aberto = false" class="ml-1 text-verde-600 hover:text-verde-800" aria-label="Fechar">&times;</button>
        </div>
    </div>
@endif

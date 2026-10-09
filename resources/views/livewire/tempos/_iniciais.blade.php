{{-- Iniciais de quem fez o registo, na cor da pessoa (a mesma da agenda da IFE). Espera $p (PessoaNaAgenda::de). --}}
<span class="inline-flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full px-1 text-[9px] font-semibold leading-none"
      style="background: {{ $p['cor'] }}; color: {{ $p['texto'] }}" title="{{ $p['nome'] }}">{{ $p['iniciais'] }}</span>

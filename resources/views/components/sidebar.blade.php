@props(['ativo' => ''])

@php
    $u = auth()->user();

    // Menu da aplicação. Um item com 'filhos' abre um submenu (fica aberto quando uma das suas
    // páginas está ativa); ['secao' => …] é um título de secção, no menu ou dentro de um submenu.
    $itens = [
        ['id' => 'cronometro', 'label' => 'Cronómetro', 'icone' => 'relogio', 'url' => route('cronometro')],
        ['id' => 'calendario', 'label' => 'Calendário', 'icone' => 'calendario', 'url' => route('calendario')],
        ['id' => 'painel', 'label' => 'Painel', 'icone' => 'inicio', 'url' => route('painel')],
        ['id' => 'relatorios', 'label' => 'Relatórios', 'icone' => 'grafico', 'filhos' => [
            ['secao' => 'Tempo'],
            ['id' => 'relatorios.resumo', 'label' => 'Resumo', 'url' => route('relatorios.resumo')],
            ['id' => 'relatorios.detalhado', 'label' => 'Detalhado', 'url' => route('relatorios.detalhado')],
            ['id' => 'relatorios.semanal', 'label' => 'Semanal', 'url' => route('relatorios.semanal')],
            ['id' => 'relatorios.partilhados', 'label' => 'Partilhados', 'url' => route('relatorios.partilhados')],
            ['secao' => 'Equipa'],
            ['id' => 'relatorios.presencas', 'label' => 'Presenças', 'url' => route('relatorios.presencas')],
            ['id' => 'relatorios.atribuicoes', 'label' => 'Atribuições', 'url' => route('relatorios.atribuicoes')],
            ['secao' => 'Despesas'],
            ['id' => 'relatorios.despesas', 'label' => 'Detalhado', 'url' => route('relatorios.despesas')],
        ]],
        ['id' => 'projetos', 'label' => 'Projetos', 'icone' => 'contrato', 'url' => route('projetos')],
        ['id' => 'equipa', 'label' => 'Equipa', 'icone' => 'pessoas', 'url' => route('equipa')],
        ['id' => 'clientes', 'label' => 'Clientes', 'icone' => 'pessoa-circulo', 'url' => route('clientes')],
    ];

    $iniciais = $u
        ? \Illuminate\Support\Str::of($u->nome)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('')
        : '–';
@endphp

<aside class="bg-sidebar-grad fixed inset-y-0 left-0 z-40 flex h-screen w-sidebar shrink-0 flex-col px-5 py-7 transition-transform duration-200 ease-out -translate-x-full lg:sticky lg:top-0 lg:z-auto lg:translate-x-0"
       :class="sidebarAberta && '!translate-x-0'">
    <div class="flex items-start justify-between px-2">
        <div>
            <img src="{{ asset('img/nexus-1.png') }}" alt="Nexus" class="h-7 w-auto">
            <div class="mt-2 text-[11px] font-medium uppercase tracking-[0.22em] text-white/40">Tempos</div>
        </div>
        <button @click="sidebarAberta = false" aria-label="Fechar menu" class="flex h-9 w-9 items-center justify-center rounded-lg text-white/60 transition hover:bg-white/10 hover:text-white lg:hidden">
            <x-icone nome="fechar" traco="2" class="h-5 w-5" />
        </button>
    </div>

    <nav class="sidebar-deslocar -mx-2 mt-10 flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-2" aria-label="Menu principal">
        @foreach ($itens as $item)
            @if (isset($item['secao']))
                <div class="px-4 pb-1 pt-4 text-[11px] font-medium uppercase tracking-[0.18em] text-white/35">{{ $item['secao'] }}</div>
            @elseif (isset($item['filhos']))
                @php $grupoAtivo = str_starts_with($ativo, $item['id'].'.'); @endphp
                <div x-data="{ aberto: @js($grupoAtivo) }">
                    <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto"
                            class="nav-item w-full {{ $grupoAtivo ? 'text-white' : '' }}">
                        <x-icone :nome="$item['icone']" class="h-5 w-5" />
                        <span class="flex-1 text-left">{{ $item['label'] }}</span>
                        <x-icone nome="seta-dir" traco="2" class="h-4 w-4 transition-transform" ::class="aberto && 'rotate-90'" />
                    </button>
                    <div x-show="aberto" x-cloak x-transition.opacity class="mt-1 flex flex-col gap-1 pl-8">
                        @foreach ($item['filhos'] as $filho)
                            @if (isset($filho['secao']))
                                <div class="px-4 pb-0.5 pt-2 text-[10px] font-medium uppercase tracking-[0.18em] text-white/35">{{ $filho['secao'] }}</div>
                                @continue
                            @endif
                            @php $on = $ativo === $filho['id']; @endphp
                            <a href="{{ $filho['url'] }}" wire:navigate @if ($on) aria-current="page" @endif
                               class="nav-item py-2 {{ $on ? 'nav-item-ativo' : '' }}">
                                <span class="{{ $on ? 'font-semibold' : '' }}">{{ $filho['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                @php $on = $ativo === $item['id']; @endphp
                <a href="{{ $item['url'] }}" wire:navigate @if ($on) aria-current="page" @endif class="nav-item {{ $on ? 'nav-item-ativo' : '' }}">
                    @if ($on)
                        <span class="absolute left-0 top-1/2 h-7 w-1 -translate-x-5 -translate-y-1/2 rounded-r-full bg-sidebar-barra"></span>
                    @endif
                    <x-icone :nome="$item['icone']" class="h-5 w-5" />
                    <span class="{{ $on ? 'font-semibold' : '' }}">{{ $item['label'] }}</span>
                </a>
            @endif
        @endforeach
    </nav>

    <div class="mt-6 flex items-center gap-3 border-t border-white/10 pt-5">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-verde-600 text-sm font-semibold text-white">{{ $iniciais }}</div>
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold text-white">{{ $u?->nome }}</div>
            <div class="truncate text-xs text-white/45">{{ $u?->email }}</div>
        </div>
        {{-- Sair daqui é voltar à escolha de módulos no portal: a sessão é partilhada por toda a
             suite e termina lá, que é onde começa. --}}
        <a href="{{ rtrim(config('app.portal_url'), '/') }}/" title="Voltar aos módulos" aria-label="Voltar aos módulos"
           class="flex h-8 w-8 items-center justify-center rounded-lg text-white/45 transition hover:bg-white/5 hover:text-white">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14M9 4H7a3 3 0 00-3 3v10a3 3 0 003 3h2"/></svg>
        </a>
    </div>
</aside>

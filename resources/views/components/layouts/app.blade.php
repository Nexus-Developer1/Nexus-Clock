@props(['ativo' => '', 'titulo' => null])

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0A2A18">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('img/icon-192.png') }}">
    <title>{{ $titulo ? $titulo.' — Nexus Suporte' : 'Nexus Suporte' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{-- CSS compilado no repositório (npm run css) — o servidor não precisa de Node. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    @livewireStyles
</head>
<body class="min-h-screen">
    <div class="flex min-h-screen" x-data="{ sidebarAberta: false }" @keydown.escape.window="sidebarAberta = false">
        <x-sidebar :ativo="$ativo" />

        {{-- Fundo escuro (só mobile, quando o menu está aberto) --}}
        <div x-show="sidebarAberta" x-cloak x-transition.opacity @click="sidebarAberta = false"
             class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- Barra superior mobile com botão de menu --}}
            <header class="flex items-center gap-3 border-b border-borda bg-white px-4 py-3 lg:hidden">
                <button @click="sidebarAberta = true" aria-label="Abrir menu"
                        class="flex h-10 w-10 items-center justify-center rounded-lg text-texto-medio transition hover:bg-fundo">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <span class="text-lg font-bold text-verde-600">Nexus Suporte</span>
            </header>

            {{ $slot }}
        </div>
    </div>

    <div class="barra-carga" aria-hidden="true"></div>

    <script>
        // Barra no topo enquanto um pedido do Livewire demora mais de 150 ms (filtros, gravações…).
        // O botão (wire:click) ou formulário (wire:submit) que lançou o pedido fica com data-loading até à
        // resposta — o CSS mostra-o a rodar e não aceita mais cliques.
        document.addEventListener('livewire:init', () => {
            let pendentes = 0;
            let temporizador = null;
            const marcados = new Set();
            const limpar = () => {
                marcados.forEach((el) => el.removeAttribute('data-loading'));
                marcados.clear();
            };
            const marcar = (el) => {
                el.setAttribute('data-loading', '');
                marcados.add(el);
                // Se o clique não chegou a fazer pedido (ex.: confirmação cancelada), desmarca.
                setTimeout(() => pendentes === 0 && limpar(), 120);
            };
            document.addEventListener('click', (e) => {
                const el = e.target.closest('[wire\\:click]');
                if (el && ! el.closest('.tabela thead')) marcar(el);
            }, true);
            document.addEventListener('submit', (e) => {
                if (e.target.hasAttribute('wire:submit')) marcar(e.target);
            }, true);

            Livewire.hook('request', ({ respond, fail }) => {
                pendentes++;
                clearTimeout(temporizador);
                temporizador = setTimeout(() => document.body.classList.add('a-carregar'), 150);
                const terminar = () => {
                    pendentes = Math.max(0, pendentes - 1);
                    if (pendentes === 0) {
                        clearTimeout(temporizador);
                        document.body.classList.remove('a-carregar');
                        limpar();
                    }
                };
                respond(terminar);
                fail(terminar);
            });
        });

        // Menus dentro de tabelas: flutuam por cima de tudo (position: fixed), junto ao botão, e abrem
        // para cima quando não há espaço em baixo — assim não ficam cortados pela caixa da tabela.
        // Uso: x-data="menuFlutuante('direita')", x-ref="botao" no botão, x-ref="menu" :style="estilo" no menu.
        document.addEventListener('alpine:init', () => {
            Alpine.data('menuFlutuante', (lado = 'direita') => ({
                aberto: false,
                estilo: '',
                alternar() {
                    this.aberto ? this.fechar() : this.abrir();
                },
                abrir() {
                    this.aberto = true;
                    this.posicionar();
                    this.$nextTick(() => this.posicionar());
                },
                fechar() {
                    this.aberto = false;
                },
                posicionar() {
                    const b = this.$refs.botao.getBoundingClientRect();
                    const altura = this.$refs.menu?.offsetHeight ?? 0;
                    const largura = this.$refs.menu?.offsetWidth ?? 0;
                    const paraCima = altura && window.innerHeight - b.bottom < altura + 8 && b.top > altura + 8;
                    const topo = paraCima ? b.top - altura - 4 : b.bottom + 4;
                    const esquerda = Math.max(8, Math.min(lado === 'direita' ? b.right - largura : b.left, window.innerWidth - largura - 8));
                    this.estilo = `top: ${topo}px; left: ${esquerda}px`;
                },
            }));
        });
    </script>
    @livewireScripts
</body>
</html>

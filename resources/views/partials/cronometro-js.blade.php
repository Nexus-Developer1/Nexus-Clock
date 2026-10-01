{{-- Cronómetro pequeno e janela por cima de tudo (notas §69). Vai no layout e na página da janela. --}}
<script data-navigate-once>
    (() => {
        if (window.cronometroSuporte) return;

        // O cartão, a janela por cima de tudo e a página do Cronómetro avisam-se uns aos outros
        // (mesmo noutros separadores) quando o cronómetro começa ou para, para não ficarem desfasados.
        const canal = 'BroadcastChannel' in window ? new BroadcastChannel('nexus-suporte-cronometro') : null;
        window.cronometroSuporte = { canal };
        if (canal) {
            canal.onmessage = () => window.dispatchEvent(new CustomEvent('cronometro-mudou-fora'));
            window.addEventListener('cronometro-mudou', () => canal.postMessage('mudou'));
        }

        // Janela que fica por cima dos outros programas (Document Picture-in-Picture, Chrome e Edge).
        // Leva a página pequena do cronómetro numa moldura; fecha-se se este separador for recarregado.
        window.abrirJanelaCronometro = async (url) => {
            const pip = window.documentPictureInPicture;
            if (! pip) return;
            if (pip.window) {
                pip.window.focus();
                return;
            }
            const janela = await pip.requestWindow({ width: 340, height: 170 });
            janela.document.title = 'Cronómetro — Nexus Suporte';
            janela.document.body.style.cssText = 'margin: 0; overflow: hidden; background: #fff';
            const moldura = janela.document.createElement('iframe');
            moldura.src = url;
            moldura.title = 'Cronómetro';
            moldura.style.cssText = 'display: block; border: 0; width: 100vw; height: 100vh';
            janela.document.body.append(moldura);
            window.dispatchEvent(new CustomEvent('janela-cronometro', { detail: true }));
            janela.addEventListener('pagehide', () => window.dispatchEvent(new CustomEvent('janela-cronometro', { detail: false })));
        };

        // A janela pertence a este separador: o browser fecha-a quando ele sai do Suporte. Enquanto
        // está aberta, os links para fora (o portal, outras aplicações) abrem num separador novo, e
        // este fica onde está, com a janela viva (notas §70).
        const base = @js(rtrim(url('/'), '/'));
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (! window.documentPictureInPicture?.window || ! link || e.defaultPrevented) return;
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            if (link.target && link.target !== '_self') return;
            if (link.hasAttribute('download')) return;
            const destino = link.href;
            if (destino === base || destino.startsWith(base + '/') || destino.startsWith(base + '?') || destino.startsWith(base + '#')) return;
            e.preventDefault();
            e.stopPropagation();
            window.open(destino, '_blank', 'noopener');
        }, true);

        document.addEventListener('alpine:init', () => {
            Alpine.data('miniCronometro', () => ({
                agora: Math.floor(Date.now() / 1000),
                minimizado: (() => { try { return localStorage.getItem('suporte-cronometro-minimizado') === '1'; } catch { return false; } })(),
                naJanela: !! window.documentPictureInPicture?.window,
                podeJanela: 'documentPictureInPicture' in window && window.top === window,
                init() {
                    this.relogio = setInterval(() => this.agora = Math.floor(Date.now() / 1000), 1000);
                },
                destroy() {
                    clearInterval(this.relogio);
                },
                tempo(inicio) {
                    const s = Math.max(0, this.agora - inicio);
                    return [Math.floor(s / 3600), Math.floor(s / 60) % 60, s % 60].map((v, i) => i ? String(v).padStart(2, '0') : v).join(':');
                },
                alternar() {
                    this.minimizado = ! this.minimizado;
                    try { localStorage.setItem('suporte-cronometro-minimizado', this.minimizado ? '1' : '0'); } catch {}
                },
                abrirJanela(url) {
                    window.abrirJanelaCronometro(url);
                },
            }));
        });
    })();
</script>

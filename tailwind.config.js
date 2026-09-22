/**
 * Design system — o MESMO da Nexus Infra (Technical Suite), copiado de nexus-ops/tailwind.config.js.
 * Cores e medidas vivem AQUI, nada hardcoded nas views. Se lá mudar, acompanhar aqui.
 *
 * O CSS é compilado localmente (`npm run css`) para public/css/app.css e vai no repositório:
 * o servidor não precisa de Node.
 */
module.exports = {
    content: [
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
        './app/Enums/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                verde: {
                    50: '#ECFDF3',
                    100: '#D1FADF',
                    200: '#A6F4C5',
                    300: '#6CE9A6',
                    400: '#32D583',
                    500: '#16A34A',
                    600: '#15803D',
                    700: '#166534',
                    800: '#14532D',
                    900: '#0A2A18',
                    950: '#050D08',
                },
                aviso: {
                    100: '#FEF9C3',
                    200: '#FEF08A',
                    500: '#A16207',
                },
                perigo: {
                    100: '#FEE2E2',
                    200: '#FECACA',
                    500: '#DC2626',
                    600: '#B91C1C',
                },
                info: {
                    100: '#DBEAFE',
                    200: '#BFDBFE',
                    500: '#2563EB',
                    600: '#1D4ED8',
                },
                superficie: '#FFFFFF',
                fundo: '#F8FAFC',
                borda: '#E5E7EB',
                texto: {
                    forte: '#111827',
                    medio: '#6B7280',
                    fraco: '#9CA3AF',
                },
                sidebar: {
                    ativo: '#0F3D24',
                    barra: '#22C55E',
                },
            },
            fontFamily: {
                sans: ['Poppins', 'system-ui', '-apple-system', 'sans-serif'],
            },
            boxShadow: {
                cartao: '0 1px 2px 0 rgba(16,24,40,.04), 0 1px 3px 0 rgba(16,24,40,.06)',
                topbar: '0 1px 0 0 rgba(16,24,40,.05)',
                botao: '0 1px 2px 0 rgba(16,24,40,.08)',
            },
            spacing: {
                sidebar: '290px',
            },
            backgroundImage: {
                'sidebar-grad':
                    'linear-gradient(180deg, #0A2A18 0%, #061008 58%, #020503 100%)',
            },
        },
    },
    plugins: [],
}

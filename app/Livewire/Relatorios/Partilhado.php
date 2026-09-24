<?php

namespace App\Livewire\Relatorios;

use App\Models\RelatorioPartilhado;
use App\Models\User;
use App\Services\Tempos\ResumoTempos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

use function Livewire\before;

/**
 * Relatório partilhado por link (/partilhado/{token}): o Resumo só de leitura, calculado com as
 * permissões de quem o criou e com os filtros que guardou. Público = qualquer pessoa com o link; privado
 * = só quem tem acesso aos Tempos. Com "bloquear datas" o período não muda; com "sempre atual" abre no
 * período corrente. Pode-se mudar o agrupamento, exportar e imprimir.
 *
 * Blindado contra quem abre o link a mandar pedidos à mão (notas §40): só as ACOES abaixo se podem
 * chamar — tudo o que herda do Resumo, e o que ele venha a ganhar, dá 403; os filtros usados nos
 * cálculos vêm SEMPRE do que o autor guardou, nunca das propriedades do componente; o valor é no
 * máximo o faturável (custo e lucro são internos); e as ações têm limite por link e endereço.
 */
#[Layout('components.layouts.publico')]
class Partilhado extends Resumo
{
    /** Ações que quem abre o link pode chamar (e o "$refresh" do próprio Livewire). */
    public const ACOES = ['anterior', 'seguinte', 'escolherPeriodo', 'aplicarDatas', 'ordenarPor', 'exportar', '$refresh'];

    /** Pedidos por minuto, por link e endereço: ações no geral e exportações (o PDF pesa). */
    public const MAXIMO_ACOES = 60;

    public const MAXIMO_EXPORTACOES = 10;

    #[Locked]
    public string $token = '';

    private ?RelatorioPartilhado $emCache = null;

    public function mount(string $token = ''): void
    {
        $this->token = $token;
        $relatorio = $this->relatorio();

        // Primeira visita: o estado guardado, com o período certo.
        foreach ($relatorio->parametros as $campo => $valor) {
            if (property_exists($this, $campo)) {
                $this->{$campo} = $valor;
            }
        }
        ['tipo' => $this->tipo, 'inicio' => $this->inicio, 'fim' => $this->fim] = $relatorio->periodo();

        $this->normalizar();
    }

    /**
     * Lista fechada de ações, registada no arranque (AppServiceProvider). Corre antes de qualquer
     * outro ouvinte do Livewire, incluindo os das ações mágicas ($set, $toggle…).
     */
    public static function registarListaDeAcoes(): void
    {
        before('call', function ($componente, $metodo) {
            if ($componente instanceof self && ! in_array($metodo, self::ACOES, true)) {
                abort(403);
            }
        });
    }

    public function hydrate(): void
    {
        $this->relatorio();
        $this->limitar('acoes', self::MAXIMO_ACOES);
    }

    public function exportar(string $formato = 'csv')
    {
        $this->limitar('exportar', self::MAXIMO_EXPORTACOES);
        $this->normalizar();

        return parent::exportar($formato);
    }

    // Com as datas bloqueadas, o período não muda.
    public function anterior(): void
    {
        if (! $this->relatorio()->bloquear_datas) {
            parent::anterior();
        }
    }

    public function seguinte(): void
    {
        if (! $this->relatorio()->bloquear_datas) {
            parent::seguinte();
        }
    }

    public function escolherPeriodo(string $qual): void
    {
        if (! $this->relatorio()->bloquear_datas) {
            parent::escolherPeriodo($qual);
        }
    }

    public function aplicarDatas(): void
    {
        if (! $this->relatorio()->bloquear_datas) {
            parent::aplicarDatas();
        }
    }

    public function abrirPartilha(): void
    {
        throw new AuthorizationException;
    }

    public function guardarPartilha(): void
    {
        throw new AuthorizationException;
    }

    public function render()
    {
        $relatorio = $this->relatorio();
        // O que se mostra é sempre o que o autor escolheu, seja qual for o estado que chegou do browser.
        $this->normalizar();

        return parent::render()
            ->with(['partilhado' => $relatorio, 'podePartilhar' => false])
            ->layoutData(['titulo' => $relatorio->nome]);
    }

    protected function autor(): User
    {
        return $this->relatorio()->autor;
    }

    /**
     * Os filtros dos cálculos vêm do que o autor guardou — nunca das propriedades, que o browser
     * consegue mexer (foi assim que o «Limpar filtros» herdado abria o relatório a tudo).
     */
    protected function filtrosDoServico(): array
    {
        $p = $this->relatorio()->parametros;
        $ids = fn (string $chave) => array_map('intval', array_values(array_unique(array_filter(array_map('strval', (array) ($p[$chave] ?? [])), 'ctype_digit'))));

        return [
            'membros' => $this->autorVeEquipa() ? ($ids('membros') ?: null) : [$this->autor()->id],
            'clientes' => $ids('clientes'),
            'projetos' => $ids('projetos'),
            'etiquetas' => array_values(array_unique(array_filter(array_map('strval', (array) ($p['etiquetas'] ?? [])), fn ($e) => $e !== ''))),
            'estado' => isset(ResumoTempos::ESTADOS[$p['estado'] ?? '']) ? $p['estado'] : '',
            'descricao' => mb_substr((string) ($p['descricao'] ?? ''), 0, 200),
        ];
    }

    protected function normalizar(): void
    {
        $relatorio = $this->relatorio();
        $p = $relatorio->parametros;

        // Os filtros são os de quem partilhou; o período só muda se não estiver bloqueado.
        foreach (['membros', 'clientes', 'projetos', 'etiquetas', 'estado', 'descricao'] as $campo) {
            $this->{$campo} = $p[$campo] ?? (in_array($campo, ['estado', 'descricao'], true) ? '' : []);
        }
        // Custo e lucro são internos: num link, no máximo o faturável (ou nenhum valor).
        $this->mostrarValor = ($p['mostrarValor'] ?? 'faturavel') === 'nao' ? 'nao' : 'faturavel';
        if ($relatorio->bloquear_datas) {
            ['tipo' => $this->tipo, 'inicio' => $this->inicio, 'fim' => $this->fim] = $relatorio->periodo();
        }
        if (! isset(ResumoTempos::AGRUPAMENTOS[$this->agrupar1])) {
            $this->agrupar1 = $p['agrupar1'] ?? 'projeto';
        }

        parent::normalizar();
    }

    /** Limite de pedidos por link e endereço; o throttle da rota só cobre abrir a página. */
    private function limitar(string $tipo, int $maximo): void
    {
        $chave = 'partilhado-'.$tipo.':'.$this->token.'|'.request()->ip();
        abort_if(RateLimiter::tooManyAttempts($chave, $maximo), 429);
        RateLimiter::hit($chave, 60);
    }

    private function relatorio(): RelatorioPartilhado
    {
        if ($this->emCache) {
            return $this->emCache;
        }

        $relatorio = RelatorioPartilhado::with('autor')->where('token', $this->token)->first();
        abort_if(! $relatorio || ! $relatorio->autor || ! $relatorio->autor->ativo || ! $relatorio->autor->acessoAEstaAplicacao(), 404);

        if (! $relatorio->publico) {
            $quem = auth()->user();
            if (! $quem) {
                throw new HttpResponseException(new RedirectResponse((string) config('app.portal_url')));
            }
            abort_unless($quem->acessoAEstaAplicacao(), 403);
        }

        return $this->emCache = $relatorio;
    }
}

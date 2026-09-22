<?php

namespace App\Livewire\Relatorios;

use App\Models\RelatorioPartilhado;
use App\Models\User;
use App\Services\Tempos\ResumoTempos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * Relatório partilhado por link (/partilhado/{token}): o Resumo só de leitura, calculado com as
 * permissões de quem o criou e com os filtros que guardou. Público = qualquer pessoa com o link; privado
 * = só quem tem acesso aos Tempos. Com "bloquear datas" o período não muda; com "sempre atual" abre no
 * período corrente. Pode-se mudar o agrupamento, exportar e imprimir.
 */
#[Layout('components.layouts.publico')]
class Partilhado extends Resumo
{
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

    public function hydrate(): void
    {
        $this->relatorio();
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

        return parent::render()
            ->with(['partilhado' => $relatorio, 'podePartilhar' => false])
            ->layoutData(['titulo' => $relatorio->nome]);
    }

    protected function autor(): User
    {
        return $this->relatorio()->autor;
    }

    protected function normalizar(): void
    {
        $relatorio = $this->relatorio();
        $p = $relatorio->parametros;

        // Os filtros são os de quem partilhou; o período só muda se não estiver bloqueado.
        foreach (['membros', 'clientes', 'projetos', 'etiquetas', 'estado', 'descricao', 'mostrarValor'] as $campo) {
            $this->{$campo} = $p[$campo] ?? match ($campo) {
                'mostrarValor' => 'faturavel',
                'estado', 'descricao' => '',
                default => [],
            };
        }
        if ($relatorio->bloquear_datas) {
            ['tipo' => $this->tipo, 'inicio' => $this->inicio, 'fim' => $this->fim] = $relatorio->periodo();
        }
        if (! isset(ResumoTempos::AGRUPAMENTOS[$this->agrupar1])) {
            $this->agrupar1 = $p['agrupar1'] ?? 'projeto';
        }

        parent::normalizar();
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

<?php

namespace App\Services\Tempos\Relatorios;

use App\Jobs\AtualizarConsumoContratos;
use App\Models\ConsumoContratoPeriodo;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Models\Intervencao;
use App\Models\User;
use App\Services\Tempos\CalculadorHorasIncluidas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Consumo de um contrato num período: horas incluídas, consumo faturável e não faturável,
 * transporte e excedente por período (regras 8–10), mais o detalhe por técnico e por intervenção.
 *
 * Os PERÍODOS vêm da view materializada `contrato_consumo_periodo` (rápido, refrescada de noite e a
 * pedido) — o transporte é acumulado aqui, desde o início de cada validade, com a mesma regra do
 * CalculadorHorasIncluidas. O DETALHE (técnico, intervenção) é lido dos registos e respeita os
 * filtros; o consumo das horas incluídas é sempre o do contrato inteiro.
 */
class RelatorioContrato
{
    /**
     * @return array{contrato: Contrato, periodos: list<array<string, mixed>>, totais: array<string, int|float|null>,
     *     porTecnico: list<array<string, mixed>>, porIntervencao: list<array<string, mixed>>, atualizadoEm: CarbonImmutable|null}
     */
    public function gerar(Contrato $contrato, FiltrosRelatorio $filtros): array
    {
        $filtros = $filtros->comContrato($contrato->id)->comCliente(null);
        $periodos = $this->periodos($contrato, $filtros->periodo);

        return [
            'contrato' => $contrato->loadMissing('cliente'),
            'periodos' => $periodos,
            'totais' => self::totais($periodos),
            'porTecnico' => $this->porTecnico($filtros),
            'porIntervencao' => $this->porIntervencao($filtros),
            'atualizadoEm' => self::atualizadoEm(),
        ];
    }

    /**
     * Períodos do contrato que tocam no período do relatório, a partir da view materializada.
     *
     * @return list<array{tipo: string, rotulo: string, inicio: CarbonImmutable, fim: CarbonImmutable, incluidas: int,
     *     transportado: int, faturavel: int, nao_faturavel: int, excedente: int, sobra: int, disponivel: int, percentagem: int|null, transita: bool}>
     */
    public function periodos(Contrato $contrato, PeriodoRelatorio $periodo): array
    {
        $linhas = ConsumoContratoPeriodo::where('contrato_id', $contrato->id)
            ->orderBy('periodo_inicio')->orderBy('periodo_fim')->get();
        $conjuntos = ContratoHorasIncluidas::where('contrato_id', $contrato->id)->orderBy('valido_de')->get();

        $resultado = [];

        foreach ($conjuntos as $horas) {
            $doConjunto = $linhas->where('contrato_horas_incluidas_id', $horas->id)
                ->map(fn (ConsumoContratoPeriodo $l) => [$l->periodo_inicio, $l->periodo_fim, $l->faturavel_seg, $l->nao_faturavel_seg]);

            foreach (CalculadorHorasIncluidas::acumular($horas, $doConjunto) as $p) {
                if (! $periodo->sobrepoe($p->inicio, $p->fim)) {
                    continue;
                }

                $base = $p->incluidasSeg + $p->transportadoSeg;
                $resultado[] = [
                    'tipo' => 'incluidas',
                    'rotulo' => $horas->periodo->rotulo().($horas->transita ? ' · transita' : ''),
                    'inicio' => $p->inicio,
                    'fim' => $p->fim,
                    'incluidas' => $p->incluidasSeg,
                    'transportado' => $p->transportadoSeg,
                    'faturavel' => $p->faturavelSeg,
                    'nao_faturavel' => $p->naoFaturavelSeg,
                    'excedente' => $p->excedenteSeg,
                    'sobra' => $p->sobraSeg,
                    'disponivel' => $p->disponivelSeg(),
                    'percentagem' => $base > 0 ? (int) round($p->faturavelSeg * 100 / $base) : null,
                    'transita' => $horas->transita,
                ];
            }
        }

        // Registos fora de quaisquer horas incluídas: todas as horas faturáveis são excedente.
        foreach ($linhas->whereNull('contrato_horas_incluidas_id') as $l) {
            if ($periodo->sobrepoe($l->periodo_inicio, $l->periodo_fim)) {
                $resultado[] = [
                    'tipo' => 'sem_incluidas',
                    'rotulo' => 'Sem horas incluídas',
                    'inicio' => $l->periodo_inicio,
                    'fim' => $l->periodo_fim,
                    'incluidas' => 0,
                    'transportado' => 0,
                    'faturavel' => $l->faturavel_seg,
                    'nao_faturavel' => $l->nao_faturavel_seg,
                    'excedente' => $l->faturavel_seg,
                    'sobra' => 0,
                    'disponivel' => 0,
                    'percentagem' => null,
                    'transita' => false,
                ];
            }
        }

        usort($resultado, fn ($a, $b) => [$a['inicio'], $a['fim']] <=> [$b['inicio'], $b['fim']]);

        return $resultado;
    }

    /**
     * @param  list<array<string, mixed>>  $periodos
     * @return array{incluidas: int, transportado: int, faturavel: int, nao_faturavel: int, excedente: int, disponivel: int, percentagem: int|null}
     */
    public static function totais(array $periodos): array
    {
        $comIncluidas = array_values(array_filter($periodos, fn ($p) => $p['tipo'] === 'incluidas'));
        $incluidas = array_sum(array_column($periodos, 'incluidas'));
        // Transporte que ENTRA no período do relatório (o do primeiro período); o de dentro já está nas incluídas.
        $transportado = $comIncluidas[0]['transportado'] ?? 0;
        $faturavelIncluidas = array_sum(array_column($comIncluidas, 'faturavel'));
        $base = $incluidas + $transportado;

        return [
            'incluidas' => $incluidas,
            'transportado' => $transportado,
            'faturavel' => array_sum(array_column($periodos, 'faturavel')),
            'nao_faturavel' => array_sum(array_column($periodos, 'nao_faturavel')),
            'excedente' => array_sum(array_column($periodos, 'excedente')),
            'disponivel' => $comIncluidas === [] ? 0 : $comIncluidas[array_key_last($comIncluidas)]['disponivel'],
            'percentagem' => $base > 0 ? (int) round($faturavelIncluidas * 100 / $base) : null,
        ];
    }

    /** Quando a view materializada foi refrescada pela última vez (null = desconhecido). */
    public static function atualizadoEm(): ?CarbonImmutable
    {
        $valor = Cache::get(AtualizarConsumoContratos::CHAVE_CACHE);

        return $valor ? CarbonImmutable::parse($valor) : null;
    }

    /** @return list<array{nome: string, total: int, faturavel: int, nao_faturavel: int}> */
    private function porTecnico(FiltrosRelatorio $filtros): array
    {
        $linhas = $this->agrupar($filtros, 'tecnico_id');
        $nomes = User::whereIn('id', array_filter(array_keys($linhas)))->pluck('nome', 'id');

        return $this->comNomes($linhas, fn ($id) => $nomes->get($id) ?? 'Técnico removido');
    }

    /** @return list<array{nome: string, total: int, faturavel: int, nao_faturavel: int}> */
    private function porIntervencao(FiltrosRelatorio $filtros): array
    {
        $linhas = $this->agrupar($filtros, 'intervencao_id');
        $intervencoes = Intervencao::withTrashed()->with('equipamento')->whereIn('id', array_filter(array_keys($linhas)))->get()->keyBy('id');

        return $this->comNomes($linhas, fn ($id) => $id ? ($intervencoes->get($id)?->rotulo() ?? '#'.$id) : 'Sem intervenção');
    }

    /** @return array<int|string, object> */
    private function agrupar(FiltrosRelatorio $filtros, string $coluna): array
    {
        return $filtros->registos()
            ->toBase()
            ->selectRaw("$coluna as chave, sum(duracao_seg) as total")
            ->selectRaw('coalesce(sum(duracao_seg) filter (where faturavel), 0) as faturavel')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where not faturavel), 0) as nao_faturavel')
            ->groupBy($coluna)
            ->get()
            ->keyBy(fn ($l) => $l->chave ?? 0)
            ->all();
    }

    /** @return list<array{nome: string, total: int, faturavel: int, nao_faturavel: int}> */
    private function comNomes(array $linhas, callable $nome): array
    {
        $resultado = array_map(fn ($l) => [
            'nome' => $nome($l->chave),
            'total' => (int) $l->total,
            'faturavel' => (int) $l->faturavel,
            'nao_faturavel' => (int) $l->nao_faturavel,
        ], array_values($linhas));

        usort($resultado, fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['nome'], $b['nome']));

        return $resultado;
    }
}

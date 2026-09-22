<?php

namespace App\Services\Tempos\Relatorios;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\User;
use App\Services\Tempos\ResolvedorTarifa;

/**
 * Margem por contrato, cliente ou técnico num período:
 *   receita = horas FATURÁVEIS × preço/hora da tarifa de venda
 *   custo   = TODAS as horas × custo/hora
 *   margem  = receita − custo (e em % da receita)
 *
 * As tarifas resolvem-se por registo, no dia do registo (regra 11: contrato → cliente → técnico →
 * global). O custo usa a primeira tarifa da mesma cadeia que tenha custo preenchido. Horas sem
 * tarifa de venda ou sem custo não entram nos valores e são mostradas à parte, para não parecerem
 * margem. Os registos são agregados por combinação e dia numa só consulta.
 */
class RelatorioMargem
{
    public const AGRUPAMENTOS = [
        'contrato' => 'Contrato',
        'cliente' => 'Cliente',
        'tecnico' => 'Técnico',
    ];

    public function __construct(private readonly ResolvedorTarifa $resolvedor) {}

    /**
     * @return array{agrupar: string, linhas: list<array<string, mixed>>, totais: array<string, int|null>}
     */
    public function gerar(FiltrosRelatorio $filtros, string $agrupar = 'contrato'): array
    {
        $agrupar = isset(self::AGRUPAMENTOS[$agrupar]) ? $agrupar : 'contrato';
        $fuso = config('tempos.fuso');

        // O faturável não é filtro da margem: o custo conta todas as horas.
        $filtros = new FiltrosRelatorio($filtros->periodo, $filtros->clienteId, $filtros->contratoId, $filtros->tecnicoId, null, $filtros->etiqueta);

        $combinacoes = $filtros->registos()
            ->toBase()
            ->selectRaw('contrato_id, cliente_id, tecnico_id, (inicio at time zone ?)::date as dia', [$fuso])
            ->selectRaw('coalesce(sum(duracao_seg) filter (where faturavel), 0) as faturavel')
            ->selectRaw('sum(duracao_seg) as total')
            ->groupBy('contrato_id', 'cliente_id', 'tecnico_id', 'dia')
            ->get();

        $grupos = [];
        foreach ($combinacoes as $c) {
            $chave = (int) ($c->{$agrupar.'_id'} ?? 0);
            $g = &$grupos[$chave];
            $g ??= ['id' => $chave ?: null, 'faturavel' => 0, 'total' => 0, 'receita' => 0, 'custo' => 0, 'sem_venda' => 0, 'sem_custo' => 0];

            $venda = $this->resolvedor->resolver($c->contrato_id, $c->cliente_id, $c->tecnico_id, (string) $c->dia);
            $custo = $this->resolvedor->resolverCusto($c->contrato_id, $c->cliente_id, $c->tecnico_id, (string) $c->dia);

            $g['faturavel'] += (int) $c->faturavel;
            $g['total'] += (int) $c->total;

            if ($venda) {
                $g['receita'] += (int) round($c->faturavel * $venda->preco_hora_cent / 3600);
            } else {
                $g['sem_venda'] += (int) $c->faturavel;
            }

            if ($custo) {
                $g['custo'] += (int) round($c->total * $custo->custo_hora_cent / 3600);
            } else {
                $g['sem_custo'] += (int) $c->total;
            }
            unset($g);
        }

        $nomes = $this->nomes($agrupar, array_filter(array_keys($grupos)));

        $linhas = array_map(fn (array $g) => [
            'nome' => $g['id'] ? ($nomes[$g['id']] ?? '—') : $this->semAlvo($agrupar),
            'horas_faturaveis' => $g['faturavel'],
            'horas_totais' => $g['total'],
            'receita' => $g['receita'],
            'custo' => $g['custo'],
            'margem' => $g['receita'] - $g['custo'],
            'margem_percentagem' => $g['receita'] > 0 ? round(($g['receita'] - $g['custo']) * 100 / $g['receita'], 1) : null,
            'sem_tarifa_seg' => $g['sem_venda'],
            'sem_custo_seg' => $g['sem_custo'],
        ], array_values($grupos));

        // Pior margem primeiro: é o que se quer ver.
        usort($linhas, fn ($a, $b) => $a['margem'] <=> $b['margem'] ?: strcmp($a['nome'], $b['nome']));

        $receita = array_sum(array_column($linhas, 'receita'));
        $custoTotal = array_sum(array_column($linhas, 'custo'));

        return [
            'agrupar' => $agrupar,
            'linhas' => $linhas,
            'totais' => [
                'horas_faturaveis' => array_sum(array_column($linhas, 'horas_faturaveis')),
                'horas_totais' => array_sum(array_column($linhas, 'horas_totais')),
                'receita' => $receita,
                'custo' => $custoTotal,
                'margem' => $receita - $custoTotal,
                'margem_percentagem' => $receita > 0 ? round(($receita - $custoTotal) * 100 / $receita, 1) : null,
                'sem_tarifa_seg' => array_sum(array_column($linhas, 'sem_tarifa_seg')),
                'sem_custo_seg' => array_sum(array_column($linhas, 'sem_custo_seg')),
                'negativas' => count(array_filter($linhas, fn ($l) => $l['margem'] < 0)),
            ],
        ];
    }

    /** @return array<int, string> */
    private function nomes(string $agrupar, array $ids): array
    {
        return match ($agrupar) {
            'contrato' => Contrato::withTrashed()->with('cliente')->whereIn('id', $ids)->get()
                ->mapWithKeys(fn (Contrato $c) => [$c->id => $c->numero.' · '.($c->cliente?->nome ?? '—')])->all(),
            'cliente' => Cliente::withTrashed()->whereIn('id', $ids)->pluck('nome', 'id')->all(),
            default => User::whereIn('id', $ids)->pluck('nome', 'id')->all(),
        };
    }

    private function semAlvo(string $agrupar): string
    {
        return match ($agrupar) {
            'contrato' => 'Sem contrato',
            'cliente' => 'Sem cliente',
            default => 'Técnico removido',
        };
    }
}

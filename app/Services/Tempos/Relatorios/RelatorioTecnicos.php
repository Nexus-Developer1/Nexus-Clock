<?php

namespace App\Services\Tempos\Relatorios;

use App\Models\Cliente;
use App\Models\User;

/**
 * Horas por técnico e, dentro de cada técnico, por cliente — faturável e não faturável — num período
 * livre, com todos os filtros. Uma consulta agrupada sobre os registos (índice por técnico e início).
 */
class RelatorioTecnicos
{
    /**
     * @return array{tecnicos: list<array<string, mixed>>, totais: array{total: int, faturavel: int, nao_faturavel: int}}
     */
    public function gerar(FiltrosRelatorio $filtros): array
    {
        $linhas = $filtros->registos()
            ->toBase()
            ->selectRaw('tecnico_id, cliente_id, sum(duracao_seg) as total')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where faturavel), 0) as faturavel')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where not faturavel), 0) as nao_faturavel')
            ->groupBy('tecnico_id', 'cliente_id')
            ->get();

        $nomesTecnicos = User::whereIn('id', $linhas->pluck('tecnico_id')->filter()->unique())->pluck('nome', 'id');
        $nomesClientes = Cliente::withTrashed()->whereIn('id', $linhas->pluck('cliente_id')->filter()->unique())->pluck('nome', 'id');

        $tecnicos = $linhas->groupBy(fn ($l) => $l->tecnico_id ?? 0)->map(function ($doTecnico, $tecnicoId) use ($nomesTecnicos, $nomesClientes) {
            $total = (int) $doTecnico->sum('total');
            $faturavel = (int) $doTecnico->sum('faturavel');

            return [
                'tecnico_id' => $tecnicoId ?: null,
                'nome' => $nomesTecnicos->get($tecnicoId) ?? 'Técnico removido',
                'total' => $total,
                'faturavel' => $faturavel,
                'nao_faturavel' => (int) $doTecnico->sum('nao_faturavel'),
                'percentagem_faturavel' => $total > 0 ? (int) round($faturavel * 100 / $total) : 0,
                'clientes' => $doTecnico->map(fn ($l) => [
                    'nome' => $nomesClientes->get($l->cliente_id) ?? 'Cliente removido',
                    'total' => (int) $l->total,
                    'faturavel' => (int) $l->faturavel,
                    'nao_faturavel' => (int) $l->nao_faturavel,
                ])->sortByDesc('total')->values()->all(),
            ];
        })->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return [
            'tecnicos' => $tecnicos,
            'totais' => [
                'total' => array_sum(array_column($tecnicos, 'total')),
                'faturavel' => array_sum(array_column($tecnicos, 'faturavel')),
                'nao_faturavel' => array_sum(array_column($tecnicos, 'nao_faturavel')),
            ],
        ];
    }
}

<?php

namespace App\Services\Tempos\Relatorios;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\RegistoTempo;

/**
 * Todos os contratos de um cliente no mesmo período: horas incluídas, consumo, transporte e
 * excedente de cada um (pela view materializada, via RelatorioContrato), mais as horas do cliente
 * registadas sem contrato.
 */
class RelatorioCliente
{
    public function __construct(private readonly RelatorioContrato $relatorioContrato) {}

    /**
     * @return array{cliente: Cliente, contratos: list<array<string, mixed>>, semContrato: array{faturavel: int, nao_faturavel: int}, totais: array<string, int>}
     */
    public function gerar(Cliente $cliente, FiltrosRelatorio $filtros): array
    {
        $contratos = [];

        foreach (Contrato::where('cliente_id', $cliente->id)->orderBy('numero')->get() as $contrato) {
            $periodos = $this->relatorioContrato->periodos($contrato, $filtros->periodo);
            if ($periodos === []) {
                continue; // sem horas incluídas nem consumo no período
            }

            $contratos[] = [
                'contrato' => $contrato,
                'periodos' => $periodos,
                'temHorasIncluidas' => collect($periodos)->contains('tipo', 'incluidas'),
            ] + RelatorioContrato::totais($periodos);
        }

        $semContrato = RegistoTempo::query()
            ->terminados()
            ->noPeriodo($filtros->periodo->de, $filtros->periodo->ate)
            ->where('cliente_id', $cliente->id)
            ->whereNull('contrato_id')
            ->toBase()
            ->selectRaw('coalesce(sum(duracao_seg) filter (where faturavel), 0) as faturavel')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where not faturavel), 0) as nao_faturavel')
            ->first();

        $semContrato = ['faturavel' => (int) $semContrato->faturavel, 'nao_faturavel' => (int) $semContrato->nao_faturavel];

        return [
            'cliente' => $cliente,
            'contratos' => $contratos,
            'semContrato' => $semContrato,
            'totais' => [
                'incluidas' => array_sum(array_column($contratos, 'incluidas')),
                'faturavel' => array_sum(array_column($contratos, 'faturavel')) + $semContrato['faturavel'],
                'nao_faturavel' => array_sum(array_column($contratos, 'nao_faturavel')) + $semContrato['nao_faturavel'],
                // Sem contrato não há horas incluídas: o faturável é todo excedente.
                'excedente' => array_sum(array_column($contratos, 'excedente')) + $semContrato['faturavel'],
            ],
        ];
    }
}

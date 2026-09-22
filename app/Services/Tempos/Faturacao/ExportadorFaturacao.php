<?php

namespace App\Services\Tempos\Faturacao;

use App\Models\ExportacaoFaturacao;
use App\Models\MesTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Exporta a faturação de um mês FECHADO: guarda a fotografia das linhas (CalculadorFaturacao) e marca
 * `faturado_em` nos registos incluídos. Voltar a exportar um mês já exportado só com confirmação
 * explícita: a exportação anterior é anulada (fica no histórico), os seus registos voltam a "por
 * faturar" e o cálculo é refeito. Tudo na auditoria.
 */
class ExportadorFaturacao
{
    public function __construct(
        private readonly CalculadorFaturacao $calculador,
        private readonly MesesFechados $mesesFechados,
    ) {}

    public function exportar(User $autor, CarbonImmutable $mes, bool $confirmarReexportacao = false): ExportacaoFaturacao
    {
        Gate::forUser($autor)->authorize('tempos-exportar');

        $inicio = MesTempo::inicioDoMes($mes);

        if (! $this->mesesFechados->estaFechado($inicio)) {
            throw ValidationException::withMessages(['exportar' => 'Feche o mês antes de exportar a faturação.']);
        }

        $anterior = ExportacaoFaturacao::emVigor()->with('criadaPor')->where('mes', $inicio->toDateString())->first();
        if ($anterior && ! $confirmarReexportacao) {
            throw ValidationException::withMessages(['exportar' => 'Este mês já foi exportado em '
                .$anterior->created_at->setTimezone(config('tempos.fuso'))->format('d/m/Y H:i')
                .' por '.($anterior->criadaPor?->nome ?? '—').'. Confirme para voltar a exportar: a exportação anterior fica anulada.']);
        }

        return DB::transaction(function () use ($autor, $inicio, $anterior) {
            if ($anterior) {
                RegistoTempo::where('exportacao_faturacao_id', $anterior->id)->update(['faturado_em' => null, 'exportacao_faturacao_id' => null]);
                $anterior->update(['anulada_em' => now(), 'anulada_por' => $autor->id]);
            }

            $calculo = $this->calculador->calcular($inicio);

            if ($calculo['avisos'] !== []) {
                throw ValidationException::withMessages(['exportar' => implode(' ', $calculo['avisos'])]);
            }

            if ($calculo['linhas'] === []) {
                throw ValidationException::withMessages(['exportar' => 'Não há nada a faturar em '.MesTempo::rotulo($inicio).'.']);
            }

            $exportacao = ExportacaoFaturacao::create([
                'mes' => $inicio->toDateString(),
                'linhas' => $calculo['linhas'],
                'total_cent' => array_sum(array_column($calculo['linhas'], 'valor_cent')),
                'segundos' => array_sum(array_column($calculo['linhas'], 'segundos')),
                'registos' => count($calculo['registoIds']),
                'criada_por' => $autor->id,
            ]);

            foreach (array_chunk($calculo['registoIds'], 1000) as $ids) {
                RegistoTempo::whereKey($ids)->update(['faturado_em' => now(), 'exportacao_faturacao_id' => $exportacao->id]);
            }

            Auditor::registar($anterior ? 'tempo_faturacao_reexportada' : 'tempo_faturacao_exportada', $exportacao, array_filter([
                'mes' => $inicio->format('Y-m'),
                'linhas' => count($calculo['linhas']),
                'total_cent' => $exportacao->total_cent,
                'anulada' => $anterior?->id,
            ]));

            return $exportacao;
        });
    }
}

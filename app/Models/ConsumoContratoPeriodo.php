<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Linha da view materializada `contrato_consumo_periodo` (só leitura; refrescada pelo job
// AtualizarConsumoContratos). Base dos relatórios de consumo — não traz o transporte de horas.
class ConsumoContratoPeriodo extends Model
{
    protected $table = 'contrato_consumo_periodo';

    public $timestamps = false;

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'periodo_inicio' => 'immutable_date',
            'periodo_fim' => 'immutable_date',
            'incluidas_seg' => 'integer',
            'faturavel_seg' => 'integer',
            'nao_faturavel_seg' => 'integer',
            'excedente_sem_transporte_seg' => 'integer',
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function horasIncluidas(): BelongsTo
    {
        return $this->belongsTo(ContratoHorasIncluidas::class, 'contrato_horas_incluidas_id');
    }
}

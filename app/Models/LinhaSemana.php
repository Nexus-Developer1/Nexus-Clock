<?php

namespace App\Models;

use App\Casts\ListaTextoPostgres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Linha da folha de horas (técnico × semana × cliente/contrato/intervenção) com a descrição, o
// faturável e as etiquetas da linha. Só existe quando é preciso guardar algo que os registos não
// guardam — ver a migração. Montagem da folha: Services\Tempos\FolhaSemanal.
class LinhaSemana extends Model
{
    protected $table = 'linhas_semana';

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'tecnico_id', 'semana_inicio', 'cliente_id', 'contrato_id', 'intervencao_id',
        'descricao', 'faturavel', 'etiquetas', 'removida',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'faturavel' => true,
        'removida' => false,
        'etiquetas' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'semana_inicio' => 'immutable_date',
            'faturavel' => 'boolean',
            'removida' => 'boolean',
            'etiquetas' => ListaTextoPostgres::class,
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }

    /** A linha desta combinação (contrato/intervenção null = sem). */
    public function scopeDaCombinacao(Builder $query, ?int $clienteId, ?int $contratoId, ?int $intervencaoId): void
    {
        foreach (['cliente_id' => $clienteId, 'contrato_id' => $contratoId, 'intervencao_id' => $intervencaoId] as $coluna => $valor) {
            $valor === null ? $query->whereNull($coluna) : $query->where($coluna, $valor);
        }
    }
}

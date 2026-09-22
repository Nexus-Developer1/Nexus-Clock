<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Exportação de faturação de um mês: fotografia das linhas no momento em que foi gerada. O CSV e os
// PDF por cliente saem sempre daqui. Anulada = substituída por uma nova exportação do mesmo mês.
class ExportacaoFaturacao extends Model
{
    protected $table = 'exportacoes_faturacao';

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = ['mes', 'linhas', 'total_cent', 'segundos', 'registos', 'criada_por', 'anulada_em', 'anulada_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mes' => 'immutable_date',
            'linhas' => 'array',
            'total_cent' => 'integer',
            'segundos' => 'integer',
            'registos' => 'integer',
            'anulada_em' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function registosFaturados(): HasMany
    {
        return $this->hasMany(RegistoTempo::class, 'exportacao_faturacao_id');
    }

    public function scopeEmVigor(Builder $query): void
    {
        $query->whereNull('anulada_em');
    }

    /**
     * Linhas de um cliente (para o PDF desse cliente).
     *
     * @return list<array<string, mixed>>
     */
    public function linhasDoCliente(int $clienteId): array
    {
        return array_values(array_filter($this->linhas, fn (array $l) => (int) $l['cliente_id'] === $clienteId));
    }

    /**
     * Clientes presentes na exportação, com o total de cada um.
     *
     * @return list<array{cliente_id: int, cliente: string, total_cent: int, linhas: int}>
     */
    public function clientes(): array
    {
        $porCliente = [];
        foreach ($this->linhas as $l) {
            $c = &$porCliente[$l['cliente_id']];
            $c ??= ['cliente_id' => (int) $l['cliente_id'], 'cliente' => $l['cliente'], 'total_cent' => 0, 'linhas' => 0];
            $c['total_cent'] += (int) $l['valor_cent'];
            $c['linhas']++;
            unset($c);
        }

        usort($porCliente, fn ($a, $b) => strcasecmp($a['cliente'], $b['cliente']));

        return array_values($porCliente);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\TabelaDaNexusInfra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Contrato de manutenção — tabela da Nexus Infra (nasce lá). Só leitura aqui; as horas incluídas
// vivem na tabela própria `contrato_horas_incluidas`.
class Contrato extends Model
{
    use SoftDeletes, TabelaDaNexusInfra;

    protected $table = 'contratos';

    /** @var list<string> */
    protected $fillable = ['numero', 'cliente_id', 'data_inicio', 'data_fim', 'estado', 'tipo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data_inicio' => 'date',
            'data_fim' => 'date',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function horasIncluidas(): HasMany
    {
        return $this->hasMany(ContratoHorasIncluidas::class)->orderBy('valido_de');
    }
}

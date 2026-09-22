<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Taxa faturável ou de custo de um membro a partir de uma data (histórico: a mais recente com
// valido_de <= dia é a que vale). valor_cent nulo = sem taxa a partir dessa data.
class TaxaMembro extends Model
{
    protected $table = 'taxas_membros';

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const TIPOS = ['faturavel' => 'Taxa faturável', 'custo' => 'Taxa de custo'];

    /** @var list<string> */
    protected $fillable = ['membro_id', 'tipo', 'valor_cent', 'valido_de', 'criado_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valor_cent' => 'integer', 'valido_de' => 'immutable_date'];
    }

    public function membro(): BelongsTo
    {
        return $this->belongsTo(MembroEquipa::class, 'membro_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\TabelaDaNexusInfra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Equipamento — tabela da Nexus Infra. Só leitura aqui. Sem local = equipamento "por associar",
// sem cliente.
class Equipamento extends Model
{
    use SoftDeletes, TabelaDaNexusInfra;

    protected $table = 'equipamentos';

    /** @var list<string> */
    protected $fillable = ['local_id', 'tipo', 'fabricante', 'modelo', 'numero_serie', 'estado'];

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }
}

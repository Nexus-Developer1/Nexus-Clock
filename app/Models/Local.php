<?php

namespace App\Models;

use App\Models\Concerns\TabelaDaNexusInfra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Local de um cliente — tabela da Nexus Infra. Só leitura aqui.
class Local extends Model
{
    use TabelaDaNexusInfra;

    protected $table = 'locais';

    /** @var list<string> */
    protected $fillable = ['cliente_id', 'designacao'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}

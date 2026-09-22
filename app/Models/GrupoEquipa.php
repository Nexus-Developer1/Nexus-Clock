<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Grupo de membros da equipa (ex.: Suporte, Obras). Escrever através de Services\Tempos\GestorEquipa.
class GrupoEquipa extends Model
{
    protected $table = 'grupos_equipa';

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = ['nome'];

    public function membros(): BelongsToMany
    {
        return $this->belongsToMany(MembroEquipa::class, 'grupo_membro', 'grupo_id', 'membro_id');
    }
}

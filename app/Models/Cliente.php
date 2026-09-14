<?php

namespace App\Models;

use App\Models\Concerns\TabelaDaNexusInfra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Cliente — tabela da Nexus Infra (sincronizada do ERP lá). Só leitura aqui.
class Cliente extends Model
{
    use SoftDeletes, TabelaDaNexusInfra;

    protected $table = 'clientes';

    /** @var list<string> */
    protected $fillable = ['id_erp', 'nome', 'nif', 'ativo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}

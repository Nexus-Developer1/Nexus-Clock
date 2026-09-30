<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma definição do Suporte (chave → valor em JSON), mudada na aplicação por quem gere a equipa. */
class DefinicaoTempo extends Model
{
    protected $table = 'definicoes_tempos';

    protected $primaryKey = 'chave';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['chave', 'valor', 'alterado_por'];

    protected function casts(): array
    {
        return ['valor' => 'array'];
    }

    public static function valor(string $chave, mixed $omissao = null): mixed
    {
        return static::find($chave)?->valor ?? $omissao;
    }
}

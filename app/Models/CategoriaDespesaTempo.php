<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Categoria de despesa dos Tempos (gerida na página Despesas).
class CategoriaDespesaTempo extends Model
{
    protected $table = 'categorias_despesa_tempos';

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = ['nome'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['arquivada_em' => 'immutable_datetime'];
    }

    public function scopeAtivas(Builder $query): void
    {
        $query->whereNull('arquivada_em');
    }
}

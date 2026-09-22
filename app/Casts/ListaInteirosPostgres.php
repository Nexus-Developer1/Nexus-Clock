<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Coluna `smallint[]`/`bigint[]` do PostgreSQL ↔ lista de inteiros em PHP (ordenada, sem repetidos).
 *
 * @implements CastsAttributes<list<int>, iterable<int|string>|null>
 */
class ListaInteirosPostgres implements CastsAttributes
{
    /** @return list<int> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $texto = trim((string) $value, '{}');

        return $texto === '' ? [] : array_map('intval', explode(',', $texto));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $itens = collect($value ?? [])->map(fn ($v) => (int) $v)->unique()->sort()->values();

        return '{'.$itens->implode(',').'}';
    }
}

<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Coluna `text[]` do PostgreSQL ↔ lista de strings em PHP.
 *
 * O PDO devolve o literal do array (`{deslocação,"com espaço"}`); aqui converte-se nos dois
 * sentidos, com aspas e barras escapadas. Valores vazios e repetidos são descartados ao gravar.
 *
 * @implements CastsAttributes<list<string>, iterable<string>|null>
 */
class ListaTextoPostgres implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        return self::lerLiteral((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $itens = collect($value ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn (string $item) => $item !== '')
            ->unique()
            ->values();

        return '{'.$itens->map(fn (string $item) => '"'.addcslashes($item, '"\\').'"')->implode(',').'}';
    }

    /** @return list<string> */
    private static function lerLiteral(string $literal): array
    {
        $interior = substr($literal, 1, -1);
        $itens = [];
        $atual = '';
        $entreAspas = false;
        $comAspas = false;

        for ($i = 0, $n = strlen($interior); $i < $n; $i++) {
            $c = $interior[$i];

            if ($entreAspas) {
                if ($c === '\\' && $i + 1 < $n) {
                    $atual .= $interior[++$i];
                } elseif ($c === '"') {
                    $entreAspas = false;
                } else {
                    $atual .= $c;
                }
            } elseif ($c === '"') {
                $entreAspas = true;
                $comAspas = true;
            } elseif ($c === ',') {
                $itens[] = $atual;
                $atual = '';
                $comAspas = false;
            } else {
                $atual .= $c;
            }
        }

        if ($atual !== '' || $comAspas) {
            $itens[] = $atual;
        }

        return $itens;
    }
}

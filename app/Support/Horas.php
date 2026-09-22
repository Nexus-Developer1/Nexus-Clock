<?php

namespace App\Support;

use App\Services\Tempos\LeitorDuracao;

// Apresentação de durações nos relatórios e exportações. Só apresentação: nada aqui arredonda o
// que fica gravado nem o que se fatura.
final class Horas
{
    /** 5400 → "1:30"; 0 → "0:00". */
    public static function hm(?int $segundos): string
    {
        return LeitorDuracao::formatar($segundos ?? 0);
    }

    /** 5400 → "1,50" (horas decimais com vírgula, para somar numa folha de cálculo). */
    public static function decimal(?int $segundos): string
    {
        return number_format(($segundos ?? 0) / 3600, 2, ',', '');
    }
}

<?php

namespace App\Support;

use InvalidArgumentException;

// Valores em euros: guardados em CÊNTIMOS (inteiros) e mostrados como na Nexus Infra ("1 234,56 €").
final class Dinheiro
{
    /**
     * Lê um valor escrito à mão: "45", "45,5", "45,50", "1 234,56", "1.234,56", "45.50", "45 €".
     * Vazio → null. Negativo ou ilegível → InvalidArgumentException com a mensagem a mostrar.
     */
    public static function paraCentimos(?string $texto): ?int
    {
        $t = trim(str_replace(['€', ' ', "\u{00A0}"], '', (string) $texto));
        if ($t === '') {
            return null;
        }

        // Com vírgula, a vírgula é a decimal e os pontos são milhares; sem vírgula, o ponto é decimal.
        if (str_contains($t, ',')) {
            $t = str_replace(',', '.', str_replace('.', '', $t));
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $t)) {
            throw new InvalidArgumentException("Valor inválido: «{$texto}». Use, por exemplo, 45,50.");
        }

        return (int) round((float) $t * 100);
    }

    /** 4550 → "45,50 €"; null → "—". */
    public static function formatar(?int $centimos): string
    {
        if ($centimos === null) {
            return '—';
        }

        return number_format($centimos / 100, 2, ',', ' ').' €';
    }

    /** 4550 → "45,50" (sem símbolo, para campos de formulário e CSV). */
    public static function decimal(?int $centimos): string
    {
        return $centimos === null ? '' : number_format($centimos / 100, 2, ',', '');
    }
}

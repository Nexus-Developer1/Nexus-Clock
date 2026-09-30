<?php

namespace App\Support;

/**
 * NIF de um cliente (notas §59). Português: 9 dígitos com o dígito de controlo certo (com ou sem «PT» à
 * frente; guarda-se sem). Estrangeiro: o código do país (duas letras) e 2 a 15 letras ou algarismos,
 * como nos números de IVA europeus («GB123456789»). Espaços, pontos e traços não contam.
 */
class Nif
{
    public static function normalizar(string $nif): string
    {
        $nif = strtoupper((string) preg_replace('/[\s.\-]/', '', $nif));

        return preg_match('/^PT(\d{9})$/', $nif, $m) ? $m[1] : $nif;
    }

    public static function valido(string $nif): bool
    {
        $nif = self::normalizar($nif);

        if (preg_match('/^\d{9}$/', $nif)) {
            $soma = 0;
            for ($i = 0; $i < 8; $i++) {
                $soma += (int) $nif[$i] * (9 - $i);
            }
            $resto = $soma % 11;

            return (int) $nif[8] === ($resto < 2 ? 0 : 11 - $resto);
        }

        return (bool) preg_match('/^[A-Z]{2}[A-Z0-9]{2,15}$/', $nif);
    }
}

<?php

namespace App\Enums;

// Como se arredondam as durações NA FATURAÇÃO (nunca na gravação nem no consumo mostrado).
enum ModoArredondamento: string
{
    case Cima = 'cima';
    case Proximo = 'proximo';
    case Baixo = 'baixo';

    public function rotulo(): string
    {
        return match ($this) {
            self::Cima => 'Para cima',
            self::Proximo => 'Ao mais próximo',
            self::Baixo => 'Para baixo',
        };
    }

    // Arredonda uma duração (segundos) a múltiplos de $minutos. 0 minutos = sem arredondamento.
    // "Ao mais próximo" arredonda a metade exata para cima (7,5 min em blocos de 15 → 15).
    public function aplicar(int $segundos, int $minutos): int
    {
        if ($minutos <= 0) {
            return $segundos;
        }

        $bloco = $minutos * 60;

        return match ($this) {
            self::Cima => intdiv($segundos + $bloco - 1, $bloco) * $bloco,
            self::Proximo => intdiv($segundos + intdiv($bloco, 2), $bloco) * $bloco,
            self::Baixo => intdiv($segundos, $bloco) * $bloco,
        };
    }
}

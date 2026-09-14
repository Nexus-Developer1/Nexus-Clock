<?php

namespace App\Enums;

// Período a que se referem as horas incluídas de um contrato. Os períodos seguem o calendário
// (mês, trimestre e ano civis), cortados pela validade das horas incluídas; "total" é a
// validade inteira. Ver docs/modulo-tempos-notas.md.
enum PeriodoHorasIncluidas: string
{
    case Mensal = 'mensal';
    case Trimestral = 'trimestral';
    case Anual = 'anual';
    case Total = 'total';

    public function rotulo(): string
    {
        return match ($this) {
            self::Mensal => 'Mensal',
            self::Trimestral => 'Trimestral',
            self::Anual => 'Anual',
            self::Total => 'Total',
        };
    }

    // Duração de cada período em meses (null = período único, a validade inteira).
    public function meses(): ?int
    {
        return match ($this) {
            self::Mensal => 1,
            self::Trimestral => 3,
            self::Anual => 12,
            self::Total => null,
        };
    }
}

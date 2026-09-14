<?php

namespace App\Services\Tempos;

use InvalidArgumentException;

/**
 * Lê uma duração escrita à mão numa célula da timesheet e devolve segundos.
 *
 * Aceita (sem distinguir maiúsculas, espaços à vontade):
 *   1:30   0:45        horas:minutos
 *   1.5    1,5    2    horas decimais (vírgula ou ponto)
 *   90m    90 min      minutos
 *   1h30   1h 30m  2h  horas e minutos
 *
 * Vazio → null (célula apagada). Qualquer outra coisa, negativo ou mais de 24 horas →
 * InvalidArgumentException com a mensagem a mostrar ao técnico.
 */
class LeitorDuracao
{
    public const MAXIMO_SEG = 86400;

    public static function ler(?string $texto): ?int
    {
        $t = mb_strtolower(trim((string) $texto));
        $t = preg_replace('/\s+/', '', $t);

        if ($t === '') {
            return null;
        }

        $segundos = match (true) {
            // 1:30
            (bool) preg_match('/^(\d{1,2}):([0-5]\d)$/', $t, $m) => (int) $m[1] * 3600 + (int) $m[2] * 60,
            // 1h30, 1h30m, 2h, 1h05min
            (bool) preg_match('/^(\d{1,2})h(?:([0-5]?\d)(?:m|min)?)?$/', $t, $m) => (int) $m[1] * 3600 + (int) ($m[2] ?? 0) * 60,
            // 90m, 90min
            (bool) preg_match('/^(\d{1,4})(?:m|min)$/', $t, $m) => (int) $m[1] * 60,
            // 1.5, 1,5, 2, ,5
            (bool) preg_match('/^(\d{0,2})(?:[.,](\d{1,4}))?$/', $t, $m) && ($m[1] !== '' || ($m[2] ?? '') !== '') => (int) round((float) (($m[1] === '' ? '0' : $m[1]).'.'.($m[2] ?? '0')) * 3600),
            default => throw new InvalidArgumentException("Não percebi a duração «{$texto}». Use, por exemplo, 1:30, 1,5, 90m ou 1h30."),
        };

        if ($segundos > self::MAXIMO_SEG) {
            throw new InvalidArgumentException('Uma duração não pode passar de 24 horas.');
        }

        return $segundos;
    }

    /** Segundos → "h:mm" (ex.: 5400 → "1:30"), como se mostra na timesheet. */
    public static function formatar(?int $segundos): string
    {
        if ($segundos === null) {
            return '';
        }

        $minutos = intdiv($segundos + 30, 60); // ao minuto mais próximo, só na apresentação

        return intdiv($minutos, 60).':'.str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }
}

<?php

namespace App\Enums;

// De onde veio um registo de tempo.
enum OrigemRegistoTempo: string
{
    case Timesheet = 'timesheet';
    case Cronometro = 'cronometro';
    case Importacao = 'importacao';

    public function rotulo(): string
    {
        return match ($this) {
            self::Timesheet => 'Timesheet',
            self::Cronometro => 'Cronómetro',
            self::Importacao => 'Importação',
        };
    }
}

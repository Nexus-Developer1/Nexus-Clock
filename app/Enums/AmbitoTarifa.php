<?php

namespace App\Enums;

// A que se aplica uma tarifa. Sem âmbito (null na tabela) = tarifa por omissão, global.
// A ordem de resolução é esta: contrato → cliente → técnico → global.
enum AmbitoTarifa: string
{
    case Contrato = 'contrato';
    case Cliente = 'cliente';
    case Tecnico = 'tecnico';

    public function rotulo(): string
    {
        return match ($this) {
            self::Contrato => 'Contrato',
            self::Cliente => 'Cliente',
            self::Tecnico => 'Técnico',
        };
    }
}

<?php

namespace App\Enums;

// Estado da semana de um técnico na timesheet (fluxo de submissão).
enum EstadoSemanaTempo: string
{
    case Rascunho = 'rascunho';
    case Submetida = 'submetida';
    case Reaberta = 'reaberta';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Submetida => 'Submetida',
            self::Reaberta => 'Reaberta',
        };
    }

    // Só a semana submetida fica fechada ao técnico; rascunho e reaberta são editáveis.
    public function bloqueiaTecnico(): bool
    {
        return $this === self::Submetida;
    }
}

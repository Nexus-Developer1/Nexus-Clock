<?php

namespace App\Enums;

// Estado da semana de um técnico na timesheet (fluxo de submissão e aprovação, um só nível).
//   rascunho → submetida → aprovada
//                        ↘ rejeitada (com motivo) → submetida…
//   submetida/aprovada → reaberta (admin, para corrigir) → submetida…
enum EstadoSemanaTempo: string
{
    case Rascunho = 'rascunho';
    case Submetida = 'submetida';
    case Aprovada = 'aprovada';
    case Rejeitada = 'rejeitada';
    case Reaberta = 'reaberta';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Submetida => 'Submetida',
            self::Aprovada => 'Aprovada',
            self::Rejeitada => 'Rejeitada',
            self::Reaberta => 'Reaberta',
        };
    }

    // Classes Tailwind da etiqueta de estado (tokens do design system, como na Nexus Infra).
    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Rascunho => 'bg-slate-100 text-texto-medio',
            self::Submetida => 'bg-verde-50 text-verde-700',
            self::Aprovada => 'bg-verde-600 text-white',
            self::Rejeitada => 'bg-perigo-100 text-perigo-600',
            self::Reaberta => 'bg-info-100 text-info-600',
        };
    }

    /** Entregue pelo técnico (submetida ou já aprovada): não está em falta nem por submeter. */
    public function entregue(): bool
    {
        return $this === self::Submetida || $this === self::Aprovada;
    }

    /** @return list<string> valores dos estados entregues, para consultas */
    public static function valoresEntregues(): array
    {
        return [self::Submetida->value, self::Aprovada->value];
    }

    // Entregue fica fechada ao técnico; rascunho, rejeitada e reaberta são editáveis.
    public function bloqueiaTecnico(): bool
    {
        return $this->entregue();
    }

    // Aprovada fica fechada a todos, admin incluído: para corrigir, reabre-se (fica na auditoria).
    public function bloqueiaTodos(): bool
    {
        return $this === self::Aprovada;
    }
}

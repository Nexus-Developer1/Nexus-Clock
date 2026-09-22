<?php

namespace App\Enums;

// Papel de um membro na equipa (ao estilo do Clockify), guardado nos Tempos. Por agora é informativo:
// as permissões continuam a vir do papel no portal (admin/técnico).
enum PapelEquipa: string
{
    case Proprietario = 'proprietario';
    case Administrador = 'administrador';
    case GestorProjeto = 'gestor_projeto';
    case GestorEquipa = 'gestor_equipa';
    case Membro = 'membro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Proprietario => 'Proprietário',
            self::Administrador => 'Administrador',
            self::GestorProjeto => 'Gestor de projeto',
            self::GestorEquipa => 'Gestor de equipa',
            self::Membro => 'Membro',
        };
    }

    public function classesEtiqueta(): string
    {
        return match ($this) {
            self::Proprietario => 'bg-verde-900 text-white',
            self::Administrador => 'bg-verde-600 text-white',
            self::GestorProjeto, self::GestorEquipa => 'bg-info-100 text-info-600',
            self::Membro => 'bg-verde-50 text-verde-700',
        };
    }
}

<?php

namespace App\Support;

use App\Models\User;

/**
 * Iniciais e cor de uma pessoa no Calendário, iguais às da agenda da Nexus IFE (notas §75).
 *
 * A cor é a da conta (`utilizadores.cor_agenda`, coluna da Nexus Infra): a mesma pessoa tem a mesma
 * cor nas duas agendas. O Suporte só a LÊ — quem a atribui e guarda é a IFE. Quem ainda não tem cor
 * fica com uma da mesma paleta, escolhida pelo id, só para mostrar (não se grava nada).
 */
class PessoaNaAgenda
{
    /** A paleta da IFE (App\Services\Agenda\FonteCalendario::PALETA, as cores das categorias do Outlook), pela mesma ordem. */
    public const PALETA = [
        '#22b14c', '#3a96dd', '#5c2d91', '#c25a21', '#1f6e7b', '#e2318c',
        '#986f0b', '#1c3f95', '#2f9e9e', '#a4262c', '#6b7d0c', '#b3a3e0',
    ];

    /** Primeiras letras das duas primeiras palavras do nome, como o x-avatar ("Davide Fonseca" → "DF"). */
    public static function iniciais(?string $nome): string
    {
        $partes = preg_split('/\s+/u', trim((string) $nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $p) => mb_substr($p, 0, 1), array_slice($partes, 0, 2)))) ?: '–';
    }

    public static function cor(?User $pessoa): string
    {
        $cor = (string) ($pessoa?->cor_agenda ?? '');

        return preg_match('/^#[0-9a-f]{6}$/i', $cor) ? strtolower($cor) : self::PALETA[(int) $pessoa?->id % count(self::PALETA)];
    }

    /** Texto legível por cima da cor: escuro nas cores claras (o lilás da paleta), branco nas outras. */
    public static function textoSobre(string $cor): string
    {
        [$r, $g, $b] = sscanf($cor, '#%02x%02x%02x') ?: [0, 0, 0];

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#1f2937' : '#ffffff';
    }

    /** @return array{id: int, nome: string, iniciais: string, cor: string, texto: string} */
    public static function de(?User $pessoa): array
    {
        $cor = self::cor($pessoa);

        return [
            'id' => (int) $pessoa?->id,
            'nome' => (string) ($pessoa?->nome ?? '—'),
            'iniciais' => self::iniciais($pessoa?->nome),
            'cor' => $cor,
            'texto' => self::textoSobre($cor),
        ];
    }

    /** Fundo de um bloco com várias pessoas: uma faixa vertical por pessoa, em tom claro (o texto fica legível). */
    public static function faixas(array $pessoas): string
    {
        $n = max(1, count($pessoas));
        $partes = [];
        foreach (array_values($pessoas) as $i => $p) {
            $partes[] = $p['cor'].'33 '.round(100 / $n * $i, 2).'% '.round(100 / $n * ($i + 1), 2).'%';
        }

        return 'linear-gradient(to right, '.implode(', ', $partes).')';
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Limite de exportações (CSV, PDF, ZIP de recibos) por pessoa — ou por endereço, num link partilhado
 * sem sessão. Cada uma ocupa um processo do PHP a gerar o ficheiro; sem limite, meia dúzia de cliques
 * seguidos (ou um script) enchiam o servidor partilhado (notas §52). Quem passa leva 429.
 */
class LimiteExportacoes
{
    public const POR_MINUTO = 20;

    public const ZIPS_POR_MINUTO = 3;

    public static function verificar(string $tipo = 'ficheiro', int $maximo = self::POR_MINUTO): void
    {
        $chave = 'tempos-exportar-'.$tipo.':'.(auth()->id() ?? request()->ip());
        abort_if(RateLimiter::tooManyAttempts($chave, $maximo), 429, 'Demasiadas exportações seguidas. Espere um minuto e tente de novo.');
        RateLimiter::hit($chave, 60);
    }
}

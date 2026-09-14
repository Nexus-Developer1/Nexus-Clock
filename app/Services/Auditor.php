<?php

namespace App\Services;

use App\Models\Auditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

// Ponto único de escrita da auditoria — a mesma tabela `auditoria` da Nexus Infra (consultável no
// ecrã de auditoria de lá), com espelho no laravel.log. NUNCA rebenta a ação auditada: uma falha
// aqui loga e segue. Imitado de App\Services\Auditor da Nexus Infra.
class Auditor
{
    /** @param array<string, mixed> $detalhe */
    public static function registar(string $acao, ?Model $entidade = null, array $detalhe = []): void
    {
        try {
            Auditoria::create([
                'user_id' => auth()->id(),
                'email' => auth()->user()?->email,
                'acao' => $acao,
                'entidade_tipo' => $entidade ? class_basename($entidade) : null,
                'entidade_id' => $entidade?->getKey(),
                'detalhe' => $detalhe ?: null,
                // Em consola/fila não há request — fica null (= sistema).
                'ip' => app()->runningInConsole() ? null : request()?->ip(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Falha a registar auditoria (a ação seguiu normalmente).', [
                'acao' => $acao,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}

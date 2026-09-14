<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Refresca a view materializada `contrato_consumo_periodo` (consumo por contrato e período).
// Corre de noite (routes/console.php) e a pedido depois do fecho mensal. Único na fila: dois
// pedidos seguidos não fazem dois refrescamentos em paralelo.
class AtualizarConsumoContratos implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function handle(): void
    {
        $inicio = microtime(true);

        // CONCURRENTLY não bloqueia quem está a ler relatórios, mas não pode correr dentro de uma
        // transação (acontece nos testes e se alguém o chamar a partir de uma) — aí faz o normal.
        $concorrente = DB::transactionLevel() === 0 ? ' concurrently' : '';
        DB::statement('refresh materialized view'.$concorrente.' contrato_consumo_periodo');

        Log::info('Consumo dos contratos refrescado.', ['duracao_ms' => (int) ((microtime(true) - $inicio) * 1000)]);
    }
}

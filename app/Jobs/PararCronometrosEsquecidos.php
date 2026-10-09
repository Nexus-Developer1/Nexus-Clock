<?php

namespace App\Jobs;

use App\Services\Tempos\Cronometro;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// De 5 em 5 minutos: para os cronómetros esquecidos — às 19:00 os começados antes das 19h, às 23:59 os
// começados depois (config tempos.parar_cronometro_as, notas §78). O fim gravado é o do limite.
class PararCronometrosEsquecidos implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** O cadeado de «um de cada vez» expira: um trabalho perdido na fila não prende os seguintes. */
    public int $uniqueFor = 240;

    public function handle(Cronometro $cronometro): void
    {
        $parados = $cronometro->pararEsquecidos();

        if ($parados > 0) {
            Log::info('Cronómetros esquecidos parados sozinhos.', ['parados' => $parados]);
        }
    }
}

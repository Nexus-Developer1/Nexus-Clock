<?php

namespace App\Jobs;

use App\Models\RegistoTempo;
use App\Notifications\CronometroEsquecido;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// De hora a hora: quem tem o cronómetro a correr há mais de X horas (config tempos.aviso_cronometro_horas,
// 10 por omissão) recebe um email a lembrar de o parar ou corrigir. Um aviso por cronómetro — se o parar
// e se esquecer de outro, é um aviso novo. Só contas ativas com acesso ao Suporte (notas §61).
class AvisarCronometrosEsquecidos implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** O cadeado de «um de cada vez» expira: um trabalho perdido na fila não prende os seguintes. */
    public int $uniqueFor = 3300;

    public function handle(): void
    {
        $horas = (int) config('tempos.aviso_cronometro_horas', 10);

        $esquecidos = RegistoTempo::query()->with('tecnico')
            ->whereNull('fim')
            ->where('inicio', '<=', now()->subHours($horas))
            ->get();

        $avisados = 0;
        foreach ($esquecidos as $registo) {
            $quem = $registo->tecnico;
            if (! $quem || ! $quem->ativo || ! $quem->acessoAEstaAplicacao()) {
                continue;
            }
            // Uma vez por cronómetro (a chave é o registo; dura uma semana).
            if (! Cache::add('tempos-cronometro-avisado:'.$registo->id, true, now()->addWeek())) {
                continue;
            }

            $quem->notify(new CronometroEsquecido($registo->inicio, $registo->descricao));
            $avisados++;
        }

        if ($avisados > 0) {
            Log::info('Avisos de cronómetro esquecido enviados.', ['avisados' => $avisados]);
        }
    }
}

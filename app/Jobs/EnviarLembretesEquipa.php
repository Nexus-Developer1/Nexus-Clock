<?php

namespace App\Jobs;

use App\Models\LembreteEquipa;
use App\Models\MembroEquipa;
use App\Models\RegistoTempo;
use App\Notifications\LembreteHoras;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// De hora a hora: os lembretes ativos para este dia da semana e esta hora (Lisboa), que ainda não
// saíram hoje, avisam por email cada membro pleno (todos ou só dos grupos escolhidos) que registou
// menos horas do que o mínimo no dia anterior ou na semana anterior. Os limitados não têm conta e
// não recebem.
class EnviarLembretesEquipa implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $agora = CarbonImmutable::now(config('tempos.fuso'));
        $hoje = $agora->toDateString();

        $devidos = LembreteEquipa::query()
            ->where('ativo', true)
            ->where('hora', $agora->hour)
            ->whereRaw('? = any(dias)', [$agora->isoWeekday()])
            ->where(fn ($q) => $q->whereNull('enviado_em')->orWhere('enviado_em', '<', $hoje))
            ->get();

        foreach ($devidos as $lembrete) {
            [$de, $ate, $rotulo] = $lembrete->periodo === 'semana'
                ? [$agora->startOfWeek()->subWeek(), $agora->startOfWeek()->subWeek()->addDays(6), 'semana de '.$agora->startOfWeek()->subWeek()->format('d/m').' a '.$agora->startOfWeek()->subDay()->format('d/m')]
                : [$agora->subDay(), $agora->subDay(), 'dia '.$agora->subDay()->format('d/m')];

            $membros = MembroEquipa::query()->comAcesso()->with('utilizador')
                ->when($lembrete->destinatarios === 'grupos', fn ($q) => $q->whereHas('grupos', fn ($g) => $g->whereIn('grupos_equipa.id', $lembrete->grupos)))
                ->get();

            $horas = RegistoTempo::query()->toBase()->whereNull('deleted_at')->whereNotNull('duracao_seg')
                ->whereIn('tecnico_id', $membros->pluck('utilizador_id'))
                ->where('inicio', '>=', RegistoTempo::inicioDoDia($de))
                ->where('inicio', '<', RegistoTempo::inicioDoDia($ate->addDay()))
                ->groupBy('tecnico_id')->selectRaw('tecnico_id, sum(duracao_seg) as segundos')
                ->pluck('segundos', 'tecnico_id');

            $minimoSeg = (int) round($lembrete->horas_minimas * 3600);
            $avisados = 0;
            foreach ($membros as $m) {
                $segundos = (int) ($horas[$m->utilizador_id] ?? 0);
                if ($segundos < $minimoSeg && $m->utilizador) {
                    $m->utilizador->notify(new LembreteHoras($rotulo, $segundos, $minimoSeg));
                    $avisados++;
                }
            }

            $lembrete->forceFill(['enviado_em' => $hoje])->save();
            Log::info('Lembrete de horas enviado.', ['lembrete' => $lembrete->id, 'periodo' => $rotulo, 'avisados' => $avisados]);
        }
    }
}

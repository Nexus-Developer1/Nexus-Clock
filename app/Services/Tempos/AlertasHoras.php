<?php

namespace App\Services\Tempos;

use App\Enums\EstadoSemanaTempo;
use App\Models\RegistoTempo;
use App\Models\SemanaTempo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Alertas de horas de uma semana (ideia tirada do Clockify), para quem gere: técnicos com menos horas
 * do que o mínimo semanal (config tempos.horas_semana_minimas), dias acima de 10 h, semanas por
 * entregar e por aprovar. Só avisa — não bloqueia nada. Férias e ausências não estão nos tempos,
 * por isso uma semana curta pode ter explicação.
 */
class AlertasHoras
{
    /**
     * @return array{segunda: CarbonImmutable, minimoSeg: int, tecnicos: list<array{tecnico: User, segundos: int,
     *     estado: EstadoSemanaTempo, abaixoDoMinimo: bool, diasLongos: list<array{dia: CarbonImmutable, segundos: int}>}>,
     *     abaixoDoMinimo: int, diasLongos: int, emFalta: int, porAprovar: int, temAlertas: bool}
     */
    public function semana(CarbonInterface $segunda): array
    {
        $segunda = FolhaSemanal::segunda($segunda);
        $minimoSeg = (int) round((float) config('tempos.horas_semana_minimas') * 3600);

        // Horas por técnico e por dia local (uma consulta).
        $porDia = RegistoTempo::query()->toBase()
            ->whereNull('deleted_at')->whereNotNull('duracao_seg')
            ->where('inicio', '>=', RegistoTempo::inicioDoDia($segunda))
            ->where('inicio', '<', RegistoTempo::inicioDoDia($segunda->addDays(7)))
            ->selectRaw('tecnico_id, (inicio at time zone ?)::date as dia, sum(duracao_seg) as segundos', [config('tempos.fuso')])
            ->groupBy('tecnico_id', 'dia')
            ->get()
            ->groupBy('tecnico_id');

        $estados = SemanaTempo::where('semana_inicio', $segunda->toDateString())->pluck('estado', 'tecnico_id');

        // Técnicos; administradores só se registaram horas nessa semana (como na vista Semanas).
        $tecnicos = User::comAcessoAosTempos()->orderBy('nome')->get()
            ->filter(fn (User $u) => $u->papelTempos() === 'tecnico' || $porDia->has($u->id))
            ->map(function (User $u) use ($porDia, $estados, $minimoSeg) {
                $dias = $porDia->get($u->id, collect());
                $segundos = (int) $dias->sum('segundos');

                return [
                    'tecnico' => $u,
                    'segundos' => $segundos,
                    'estado' => $estados->get($u->id) ?? EstadoSemanaTempo::Rascunho,
                    'abaixoDoMinimo' => $minimoSeg > 0 && $segundos < $minimoSeg,
                    'diasLongos' => $dias->filter(fn ($d) => (int) $d->segundos > FolhaSemanal::AVISO_DIA_SEG)
                        ->map(fn ($d) => ['dia' => CarbonImmutable::parse($d->dia), 'segundos' => (int) $d->segundos])
                        ->sortBy('dia')->values()->all(),
                ];
            })
            ->values();

        $resumo = [
            'abaixoDoMinimo' => $tecnicos->where('abaixoDoMinimo', true)->count(),
            'diasLongos' => $tecnicos->sum(fn ($t) => count($t['diasLongos'])),
            'emFalta' => $tecnicos->filter(fn ($t) => ! $t['estado']->entregue())->count(),
            'porAprovar' => $tecnicos->filter(fn ($t) => $t['estado'] === EstadoSemanaTempo::Submetida)->count(),
        ];

        return [
            'segunda' => $segunda,
            'minimoSeg' => $minimoSeg,
            'tecnicos' => $tecnicos->all(),
            ...$resumo,
            'temAlertas' => array_sum($resumo) > 0,
        ];
    }
}

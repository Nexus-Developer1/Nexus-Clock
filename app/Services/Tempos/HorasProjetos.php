<?php

namespace App\Services\Tempos;

use Illuminate\Support\Facades\DB;

/**
 * Horas registadas e valor de cada projeto (registos terminados, de sempre). O valor conta só registos
 * faturáveis de projetos faturáveis, à taxa do projeto ou, sem ela, à taxa faturável do membro em vigor
 * no dia do registo. Sem arredondamentos (isso é só na faturação).
 */
class HorasProjetos
{
    /**
     * @param  list<int>  $projetoIds
     * @return array<int, array{segundos: int, valor_cent: int}>
     */
    public function porProjeto(array $projetoIds): array
    {
        if ($projetoIds === []) {
            return [];
        }

        $linhas = DB::table('registos_tempo as r')
            ->join('projetos_tempos as p', 'p.id', '=', 'r.projeto_id')
            ->leftJoin('membros_equipa as m', 'm.utilizador_id', '=', 'r.tecnico_id')
            ->leftJoin(DB::raw('lateral (
                select t.valor_cent from taxas_membros t
                where t.membro_id = m.id and t.tipo = \'faturavel\' and t.valido_de <= (r.inicio at time zone '.DB::getPdo()->quote(config('tempos.fuso')).')::date
                order by t.valido_de desc limit 1
            ) as taxa'), DB::raw('true'), '=', DB::raw('true'))
            ->whereIn('r.projeto_id', $projetoIds)
            ->whereNull('r.deleted_at')
            ->whereNotNull('r.duracao_seg')
            ->groupBy('r.projeto_id')
            ->selectRaw('r.projeto_id, sum(r.duracao_seg) as segundos')
            ->selectRaw('round(sum(case when r.faturavel and p.faturavel then r.duracao_seg * coalesce(p.taxa_cent, taxa.valor_cent, 0) else 0 end) / 3600.0) as valor_cent')
            ->get();

        return $linhas->mapWithKeys(fn ($l) => [(int) $l->projeto_id => ['segundos' => (int) $l->segundos, 'valor_cent' => (int) $l->valor_cent]])->all();
    }
}

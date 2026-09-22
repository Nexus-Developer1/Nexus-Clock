<?php

namespace App\Services\Tempos\Relatorios;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Intervencao;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resumo livre (ideia tirada do "Summary report" do Clockify): as horas agrupadas pela dimensão que se
 * quiser — cliente, contrato, técnico, intervenção, etiqueta, dia, semana ou mês — e, dentro de cada
 * grupo, por uma segunda dimensão. Faturável e não faturável em cada grupo.
 *
 * Uma consulta agrupada sobre os registos que passam nos filtros. Por etiqueta, um registo com várias
 * etiquetas conta em cada uma (e sem etiquetas conta em "Sem etiqueta"): os totais do relatório vêm
 * sempre dos registos, não da soma dos grupos.
 */
class RelatorioResumo
{
    public const AGRUPAMENTOS = [
        'cliente' => 'Cliente',
        'contrato' => 'Contrato',
        'tecnico' => 'Técnico',
        'intervencao' => 'Intervenção',
        'etiqueta' => 'Etiqueta',
        'dia' => 'Dia',
        'semana' => 'Semana',
        'mes' => 'Mês',
    ];

    public const TEMPORAIS = ['dia', 'semana', 'mes'];

    /**
     * @return array{agrupar: string, subgrupo: string|null, grupos: list<array<string, mixed>>,
     *     totais: array{total: int, faturavel: int, nao_faturavel: int, registos: int}, porEtiqueta: bool}
     */
    public function gerar(FiltrosRelatorio $filtros, string $agrupar, ?string $subgrupo = null): array
    {
        $agrupar = isset(self::AGRUPAMENTOS[$agrupar]) ? $agrupar : 'cliente';
        $subgrupo = $subgrupo !== null && isset(self::AGRUPAMENTOS[$subgrupo]) && $subgrupo !== $agrupar ? $subgrupo : null;

        $base = $filtros->registos()->toBase()->select(['duracao_seg', 'faturavel']);
        [$sql1, $bind1] = $this->expressao($agrupar);
        $base->selectRaw($sql1.' as g1', $bind1);
        if ($subgrupo) {
            [$sql2, $bind2] = $this->expressao($subgrupo);
            $base->selectRaw($sql2.' as g2', $bind2);
        }

        // Subconsulta: a expressão da etiqueta (unnest) não pode ir para o GROUP BY diretamente.
        $linhas = DB::query()->fromSub($base, 'r')
            ->selectRaw('g1'.($subgrupo ? ', g2' : ''))
            ->selectRaw('sum(duracao_seg) as total, count(*) as registos')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where faturavel), 0) as faturavel')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where not faturavel), 0) as nao_faturavel')
            ->groupBy($subgrupo ? ['g1', 'g2'] : ['g1'])
            ->get();

        $totais = $filtros->registos()->toBase()
            ->selectRaw('coalesce(sum(duracao_seg), 0) as total, count(*) as registos')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where faturavel), 0) as faturavel')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where not faturavel), 0) as nao_faturavel')
            ->first();
        $totais = array_map('intval', (array) $totais);

        $nomes1 = $this->nomes($agrupar, $linhas->pluck('g1'));
        $nomes2 = $subgrupo ? $this->nomes($subgrupo, $linhas->pluck('g2')) : [];

        $grupos = $linhas->groupBy(fn ($l) => (string) $l->g1)->map(function (Collection $doGrupo, string $chave) use ($agrupar, $subgrupo, $nomes1, $nomes2, $totais) {
            $grupo = $this->numeros($doGrupo, $totais['total']) + ['chave' => $chave, 'nome' => $nomes1[$chave] ?? '—'];

            if ($subgrupo) {
                $grupo['subgrupos'] = $this->ordenar($subgrupo, $doGrupo->map(fn ($l) => $this->numeros(collect([$l]), $grupo['total'])
                    + ['chave' => (string) $l->g2, 'nome' => $nomes2[(string) $l->g2] ?? '—']));
            }

            return $grupo;
        })->values();

        $grupos = $this->ordenar($agrupar, $grupos);
        if (in_array($agrupar, self::TEMPORAIS, true)) {
            $grupos = $this->preencherPeriodo($agrupar, $grupos, $filtros->periodo);
        }

        return [
            'agrupar' => $agrupar,
            'subgrupo' => $subgrupo,
            'grupos' => $grupos,
            'totais' => $totais,
            'porEtiqueta' => $agrupar === 'etiqueta' || $subgrupo === 'etiqueta',
        ];
    }

    public static function rotuloTemporal(string $dimensao, CarbonImmutable $data): string
    {
        return match ($dimensao) {
            'dia' => ucfirst($data->translatedFormat('D, d/m')),
            'semana' => $data->format('d/m').' – '.$data->addDays(6)->format('d/m'),
            default => ucfirst($data->translatedFormat('F Y')),
        };
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function expressao(string $dimensao): array
    {
        $fuso = config('tempos.fuso');

        return match ($dimensao) {
            'cliente' => ['cliente_id', []],
            'contrato' => ['contrato_id', []],
            'tecnico' => ['tecnico_id', []],
            'intervencao' => ['intervencao_id', []],
            'etiqueta' => ['unnest(case when cardinality(etiquetas) = 0 then array[null::text] else etiquetas end)', []],
            'dia' => ['(inicio at time zone ?)::date', [$fuso]],
            'semana' => ["date_trunc('week', inicio at time zone ?)::date", [$fuso]],
            'mes' => ["date_trunc('month', inicio at time zone ?)::date", [$fuso]],
        };
    }

    /** @return array{total: int, faturavel: int, nao_faturavel: int, registos: int, percentagem: float, percentagem_faturavel: int} */
    private function numeros(Collection $linhas, int $totalDeReferencia): array
    {
        $total = (int) $linhas->sum('total');
        $faturavel = (int) $linhas->sum('faturavel');

        return [
            'total' => $total,
            'faturavel' => $faturavel,
            'nao_faturavel' => (int) $linhas->sum('nao_faturavel'),
            'registos' => (int) $linhas->sum('registos'),
            'percentagem' => $totalDeReferencia > 0 ? round($total * 100 / $totalDeReferencia, 1) : 0.0,
            'percentagem_faturavel' => $total > 0 ? (int) round($faturavel * 100 / $total) : 0,
        ];
    }

    /**
     * Nome de cada chave de uma dimensão (chaves em texto, como vêm agrupadas).
     *
     * @return array<string, string>
     */
    private function nomes(string $dimensao, Collection $chaves): array
    {
        $ids = $chaves->filter(fn ($c) => $c !== null && $c !== '')->unique()->values();
        $nomes = match ($dimensao) {
            'cliente' => Cliente::withTrashed()->whereIn('id', $ids)->pluck('nome', 'id')->all(),
            'contrato' => Contrato::withTrashed()->with('cliente:id,nome')->whereIn('id', $ids)->get()
                ->mapWithKeys(fn (Contrato $c) => [$c->id => $c->numero.' · '.($c->cliente?->nome ?? '—')])->all(),
            'tecnico' => User::whereIn('id', $ids)->pluck('nome', 'id')->all(),
            'intervencao' => Intervencao::withTrashed()->with('equipamento')->whereIn('id', $ids)->get()
                ->mapWithKeys(fn (Intervencao $i) => [$i->id => $i->rotulo()])->all(),
            'etiqueta' => $ids->mapWithKeys(fn ($e) => [$e => $e])->all(),
            default => $ids->mapWithKeys(fn ($d) => [$d => self::rotuloTemporal($dimensao, CarbonImmutable::parse($d))])->all(),
        };

        $nomes = collect($nomes)->mapWithKeys(fn ($nome, $chave) => [(string) $chave => $nome])->all();
        $nomes[''] = match ($dimensao) {
            'cliente' => 'Sem cliente',
            'contrato' => 'Sem contrato',
            'tecnico' => 'Técnico removido',
            'intervencao' => 'Sem intervenção',
            'etiqueta' => 'Sem etiqueta',
            default => '—',
        };

        return $nomes;
    }

    /** Temporais por data; os outros do maior para o menor, com "Sem …" no fim. */
    private function ordenar(string $dimensao, Collection $grupos): array
    {
        if (in_array($dimensao, self::TEMPORAIS, true)) {
            return $grupos->sortBy('chave')->values()->all();
        }

        return $grupos->sortBy([fn ($a, $b) => ($a['chave'] === '') <=> ($b['chave'] === ''), fn ($a, $b) => $b['total'] <=> $a['total']])->values()->all();
    }

    /** Dias, semanas ou meses do período sem horas também aparecem (a zero), para o gráfico não saltar. */
    private function preencherPeriodo(string $dimensao, array $grupos, PeriodoRelatorio $periodo): array
    {
        $existentes = collect($grupos)->keyBy('chave');
        $inicio = match ($dimensao) {
            'dia' => $periodo->de,
            'semana' => $periodo->de->startOfWeek(),
            default => $periodo->de->startOfMonth(),
        };

        $todos = [];
        for ($d = $inicio; $d->lte($periodo->ate) && count($todos) < 400; $d = match ($dimensao) {
            'dia' => $d->addDay(),
            'semana' => $d->addWeek(),
            default => $d->addMonthNoOverflow(),
        }) {
            $chave = $d->toDateString();
            $todos[] = $existentes->get($chave) ?? [
                'chave' => $chave, 'nome' => self::rotuloTemporal($dimensao, $d), 'total' => 0, 'faturavel' => 0, 'nao_faturavel' => 0,
                'registos' => 0, 'percentagem' => 0.0, 'percentagem_faturavel' => 0, 'subgrupos' => [],
            ];
        }

        return $todos;
    }
}

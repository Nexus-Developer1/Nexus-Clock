<?php

namespace App\Services\Tempos;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dados do Painel (como o Dashboard do Clockify) num período: tempo total, projeto e cliente com mais
 * horas, horas por dia repartidas pelos grupos principais, distribuição por grupo e as atividades mais
 * registadas. Projeto = projeto dos Tempos (página Projetos). Só registos terminados.
 */
class PainelTempos
{
    public const AGRUPAMENTOS = ['projeto' => 'Projeto', 'cliente' => 'Cliente', 'contrato' => 'Contrato', 'etiqueta' => 'Etiqueta', 'membro' => 'Membro'];

    // Grupos com cor própria no gráfico; os restantes juntam-se em "Outros".
    public const GRUPOS_COM_COR = 5;

    /**
     * @param  int|null  $tecnicoId  null = equipa toda
     * @return array{total: int, topProjeto: array{nome: string, segundos: int}|null, topCliente: array{nome: string, segundos: int}|null,
     *     dias: list<array{dia: CarbonImmutable, total: int, partes: array<string, int>}>, grupos: list<array{chave: string, nome: string, segundos: int, percentagem: float}>,
     *     series: list<string>, atividades: list<array{descricao: string, detalhe: string, segundos: int}>, maximoDia: int}
     */
    public function gerar(?int $tecnicoId, string $agrupar, CarbonImmutable $de, CarbonImmutable $ate, int $topAtividades = 10): array
    {
        $agrupar = isset(self::AGRUPAMENTOS[$agrupar]) ? $agrupar : 'projeto';

        $base = fn () => RegistoTempo::query()->terminados()->noPeriodo($de, $ate)
            ->when($tecnicoId, fn ($q) => $q->doTecnico($tecnicoId))
            ->toBase();

        $totais = $this->totais($tecnicoId, $de, $ate);

        // Por grupo e por dia (subconsulta: a etiqueta usa unnest).
        [$expressao, $bindings] = match ($agrupar) {
            'cliente' => ['cliente_id::text', []],
            'contrato' => ['contrato_id::text', []],
            'membro' => ['tecnico_id::text', []],
            'etiqueta' => ['unnest(case when cardinality(etiquetas) = 0 then array[null::text] else etiquetas end)', []],
            default => ['projeto_id::text', []],
        };
        $sub = $base()->select('duracao_seg')
            ->selectRaw('(inicio at time zone ?)::date as dia', [config('tempos.fuso')])
            ->selectRaw($expressao.' as grupo', $bindings);
        $linhas = DB::query()->fromSub($sub, 'r')
            ->selectRaw('dia, grupo, sum(duracao_seg) as segundos')
            ->groupBy('dia', 'grupo')
            ->get();

        $nomes = $this->nomes($agrupar, $linhas->pluck('grupo'));
        $porGrupo = $linhas->groupBy(fn ($l) => (string) $l->grupo)
            ->map(fn (Collection $g, string $chave) => ['chave' => $chave, 'nome' => $nomes[$chave] ?? '—', 'segundos' => (int) $g->sum('segundos')])
            ->sortByDesc('segundos')->values();

        $somaGrupos = max(1, (int) $porGrupo->sum('segundos'));
        $grupos = $porGrupo->map(fn ($g) => $g + ['percentagem' => round($g['segundos'] * 100 / $somaGrupos, 1)])->all();

        // Séries do gráfico: os grupos principais (com nome) e "Outros".
        $series = $porGrupo->filter(fn ($g) => $g['chave'] !== '')->take(self::GRUPOS_COM_COR)->pluck('chave')->all();

        $dias = [];
        for ($d = $de; $d->lte($ate); $d = $d->addDay()) {
            $doDia = $linhas->filter(fn ($l) => $l->dia === $d->toDateString());
            $partes = array_fill_keys($series, 0) + ['outros' => 0];
            foreach ($doDia as $l) {
                $chave = in_array((string) $l->grupo, $series, true) ? (string) $l->grupo : 'outros';
                $partes[$chave] += (int) $l->segundos;
            }
            $dias[] = ['dia' => $d, 'total' => array_sum($partes), 'partes' => $partes];
        }

        return [
            'total' => $totais['total'],
            'faturavel' => $totais['faturavel'],
            'topProjeto' => $this->topo($base(), 'projeto_id', fn ($ids) => ProjetoTempo::withTrashed()->whereIn('id', $ids)->pluck('nome', 'id')),
            'topCliente' => $this->topo($base(), 'cliente_id', fn ($ids) => Cliente::withTrashed()->whereIn('id', $ids)->pluck('nome', 'id')),
            'dias' => $dias,
            'maximoDia' => (int) collect($dias)->max('total'),
            'grupos' => $grupos,
            'series' => $series,
            'nomes' => $nomes,
            'atividades' => $this->atividades($base(), $topAtividades),
        ];
    }

    /**
     * Total e faturável (registo faturável em projeto faturável, ou sem projeto) do período.
     *
     * @return array{total: int, faturavel: int}
     */
    public function totais(?int $tecnicoId, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $t = RegistoTempo::query()->terminados()->noPeriodo($de, $ate)
            ->when($tecnicoId, fn ($q) => $q->doTecnico($tecnicoId))
            ->toBase()
            ->leftJoin('projetos_tempos as pt', 'pt.id', '=', 'registos_tempo.projeto_id')
            ->selectRaw('coalesce(sum(registos_tempo.duracao_seg), 0) as total')
            ->selectRaw('coalesce(sum(case when registos_tempo.faturavel and coalesce(pt.faturavel, true) then registos_tempo.duracao_seg else 0 end), 0) as faturavel')
            ->first();

        return ['total' => (int) $t->total, 'faturavel' => (int) $t->faturavel];
    }

    /**
     * Atividade da equipa (como o "Team activity" do Clockify): por membro com acesso, horas no período,
     * o cronómetro a correr e o último registo terminado. Quem está a registar primeiro, depois por horas.
     *
     * @return list<array{id: int, nome: string, segundos: int, aCorrer: array{descricao: string, projeto: ?string, cor: ?string, desde: CarbonImmutable}|null, ultimo: array{descricao: string, projeto: ?string, cor: ?string, quando: CarbonImmutable}|null}>
     */
    public function equipa(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $membros = User::comAcessoAosTempos()->orderBy('nome')->get(['id', 'nome']);
        $horas = RegistoTempo::query()->terminados()->noPeriodo($de, $ate)->toBase()
            ->groupBy('tecnico_id')->selectRaw('tecnico_id, sum(duracao_seg) as segundos')->pluck('segundos', 'tecnico_id');
        $aCorrer = RegistoTempo::query()->whereNull('fim')->with('projeto:id,nome,cor')->get()->keyBy('tecnico_id');
        $ultimos = RegistoTempo::query()->with('projeto:id,nome,cor')
            ->whereIn('id', fn ($q) => $q->selectRaw('distinct on (tecnico_id) id')->from('registos_tempo')
                ->whereNull('deleted_at')->whereNotNull('duracao_seg')->orderBy('tecnico_id')->orderByDesc('inicio')->orderByDesc('id'))
            ->get()->keyBy('tecnico_id');

        $resumo = fn (RegistoTempo $r, string $quando) => [
            'descricao' => (string) ($r->descricao ?: 'Sem descrição'),
            'projeto' => $r->projeto?->nome,
            'cor' => $r->projeto?->cor,
            $quando => $r->inicio->setTimezone(config('tempos.fuso')),
        ];

        return $membros->map(fn (User $u) => [
            'id' => $u->id,
            'nome' => $u->nome,
            'segundos' => (int) ($horas[$u->id] ?? 0),
            'aCorrer' => isset($aCorrer[$u->id]) ? $resumo($aCorrer[$u->id], 'desde') : null,
            'ultimo' => isset($ultimos[$u->id]) ? $resumo($ultimos[$u->id], 'quando') : null,
        ])
            ->sortBy(fn ($m) => mb_strtolower($m['nome']))
            ->sortByDesc('segundos')
            ->sortBy(fn ($m) => $m['aCorrer'] ? 0 : 1)
            ->values()->all();
    }

    /** @return array{nome: string, segundos: int}|null */
    private function topo(Builder $query, string $coluna, callable $nomesDe): ?array
    {
        $linha = $query->whereNotNull($coluna)->groupBy($coluna)
            ->selectRaw($coluna.' as id, sum(duracao_seg) as segundos')
            ->orderByDesc('segundos')->first();

        if (! $linha) {
            return null;
        }

        return ['nome' => (string) ($nomesDe([$linha->id])[$linha->id] ?? '—'), 'segundos' => (int) $linha->segundos];
    }

    /** @return list<array{descricao: string, detalhe: string, segundos: int}> */
    private function atividades(Builder $query, int $limite): array
    {
        $linhas = $query->groupBy('descricao', 'projeto_id', 'contrato_id', 'cliente_id')
            ->selectRaw('descricao, projeto_id, contrato_id, cliente_id, sum(duracao_seg) as segundos')
            ->orderByDesc('segundos')->orderBy('descricao')->limit($limite)->get();

        $projetos = ProjetoTempo::withTrashed()->whereIn('id', $linhas->pluck('projeto_id')->filter())->pluck('nome', 'id');
        $contratos = Contrato::withTrashed()->whereIn('id', $linhas->pluck('contrato_id')->filter())->pluck('numero', 'id');
        $clientes = Cliente::withTrashed()->whereIn('id', $linhas->pluck('cliente_id')->filter())->pluck('nome', 'id');

        return $linhas->map(fn ($l) => [
            'descricao' => (string) $l->descricao,
            'detalhe' => collect([
                $l->projeto_id ? $projetos[$l->projeto_id] ?? null : null,
                $l->contrato_id ? $contratos[$l->contrato_id] ?? null : null,
                $l->cliente_id ? $clientes[$l->cliente_id] ?? null : null,
            ])->filter()->implode(' · '),
            'segundos' => (int) $l->segundos,
            'projeto_id' => $l->projeto_id ? (int) $l->projeto_id : null,
            'cliente_id' => $l->cliente_id ? (int) $l->cliente_id : null,
        ])->all();
    }

    /** @return array<string, string> */
    private function nomes(string $agrupar, Collection $chaves): array
    {
        $ids = $chaves->filter(fn ($c) => $c !== null && $c !== '')->unique()->values();
        $nomes = match ($agrupar) {
            'cliente' => Cliente::withTrashed()->whereIn('id', $ids)->pluck('nome', 'id')->all(),
            'contrato' => Contrato::withTrashed()->whereIn('id', $ids)->pluck('numero', 'id')->all(),
            'membro' => User::whereIn('id', $ids)->pluck('nome', 'id')->all(),
            'etiqueta' => $ids->mapWithKeys(fn ($e) => [$e => $e])->all(),
            default => ProjetoTempo::withTrashed()->whereIn('id', $ids)->pluck('nome', 'id')->all(),
        };

        $nomes = collect($nomes)->mapWithKeys(fn ($n, $k) => [(string) $k => (string) $n])->all();
        $nomes[''] = match ($agrupar) {
            'cliente' => 'Sem cliente',
            'contrato' => 'Sem contrato',
            'membro' => 'Sem membro',
            'etiqueta' => 'Sem etiqueta',
            default => 'Sem projeto',
        };
        $nomes['outros'] = 'Outros';

        return $nomes;
    }

    /** 3725 → "1:02:05". */
    public static function hms(int $segundos): string
    {
        return sprintf('%d:%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60), $segundos % 60);
    }
}

<?php

namespace App\Services\Tempos;

use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Relatório Resumo (como o "Summary report" do Clockify): total, faturável, valor e custo do período,
 * horas por dia (ou por mês, em períodos longos) e a árvore agrupada por um ou dois critérios.
 *
 * Só registos terminados. Valor = registos faturáveis (em projeto faturável ou sem projeto) à taxa do
 * projeto ou, sem ela, à taxa faturável do membro no dia; custo = taxa de custo do membro no dia. Sem
 * arredondamentos. Agrupar por etiqueta conta o registo em cada etiqueta.
 */
class ResumoTempos
{
    public const AGRUPAMENTOS = [
        'projeto' => 'Projeto',
        'cliente' => 'Cliente',
        'membro' => 'Membro',
        'etiqueta' => 'Etiqueta',
        'descricao' => 'Descrição',
        'dia' => 'Dia',
    ];

    public const ESTADOS = ['faturavel' => 'Faturável', 'nao_faturavel' => 'Não faturável', 'faturado' => 'Faturado', 'por_faturar' => 'Por faturar'];

    // Auditoria de tempo (relatório Detalhado): registos a rever.
    public const AUDITORIA = ['sem_projeto' => 'Sem projeto', 'sem_descricao' => 'Sem descrição', 'sem_etiquetas' => 'Sem etiquetas', 'longos' => 'Com mais de 8 h'];

    public const LONGO_SEG = 8 * 3600;

    public const GRUPOS_COM_COR = 5;

    /**
     * @param  array{membros?: list<int>|null, clientes?: list<int>, projetos?: list<int>, etiquetas?: list<string>, estado?: string, descricao?: string, auditoria?: string}  $filtros
     *                                                                                                                                                                                   membros null = todos (quem vê a equipa); projeto 0 = sem projeto
     * @return array{total: int, faturavel: int, valor: int, custo: int, barras: list<array{rotulo: string, dica: string, total: int, partes: array<string, int>}>, maximo: int, mensal: bool, grupos: list<array<string, mixed>>, series: list<string>, nomes: array<string, string>}
     */
    public function gerar(User $quem, array $filtros, CarbonImmutable $de, CarbonImmutable $ate, string $agrupar1, ?string $agrupar2, string $cor = 'faturabilidade'): array
    {
        $agrupar1 = isset(self::AGRUPAMENTOS[$agrupar1]) ? $agrupar1 : 'projeto';
        $agrupar2 = $agrupar2 !== null && isset(self::AGRUPAMENTOS[$agrupar2]) && $agrupar2 !== $agrupar1 ? $agrupar2 : null;
        $mensal = $de->diffInDays($ate) > 62;
        $fuso = DB::getPdo()->quote(config('tempos.fuso'));

        $sub = $this->consulta($quem, $filtros, $de, $ate)
            ->selectRaw('registos_tempo.duracao_seg as seg')
            ->selectRaw('(registos_tempo.faturavel and coalesce(pt.faturavel, true)) as fat')
            ->selectRaw("(registos_tempo.inicio at time zone {$fuso})::date as dia")
            ->selectRaw('round(case when registos_tempo.faturavel and coalesce(pt.faturavel, true) then registos_tempo.duracao_seg * coalesce(pt.taxa_cent, tf.valor_cent, 0) else 0 end / 3600.0) as valor')
            ->selectRaw('round(registos_tempo.duracao_seg * coalesce(tc.valor_cent, 0) / 3600.0) as custo')
            ->selectRaw($this->expressao($agrupar1).' as g1')
            ->selectRaw(($agrupar2 ? $this->expressao($agrupar2) : "''").' as g2');

        $linhas = DB::query()->fromSub($sub, 'r')
            ->selectRaw('dia, g1, g2, fat, sum(seg) as seg, sum(valor) as valor, sum(custo) as custo')
            ->groupBy('dia', 'g1', 'g2', 'fat')
            ->get()
            ->map(fn ($l) => (object) ['dia' => $l->dia, 'g1' => (string) $l->g1, 'g2' => (string) $l->g2, 'fat' => (bool) $l->fat, 'seg' => (int) $l->seg, 'valor' => (int) $l->valor, 'custo' => (int) $l->custo]);

        $totais = $this->totais($quem, $filtros, $de, $ate);

        $nomes = $this->nomes($agrupar1, $linhas->pluck('g1'));
        $nomes2 = $agrupar2 ? $this->nomes($agrupar2, $linhas->pluck('g2')) : [];

        $grupos = $linhas->groupBy('g1')->map(fn (Collection $g, string $chave) => [
            'chave' => $chave,
            'nome' => $nomes[$chave] ?? '—',
            'segundos' => $g->sum('seg'),
            'valor' => $g->sum('valor'),
            'custo' => $g->sum('custo'),
            'filhos' => $agrupar2 ? $g->groupBy('g2')->map(fn (Collection $f, string $k) => [
                'chave' => $k,
                'nome' => $nomes2[$k] ?? '—',
                'segundos' => $f->sum('seg'),
                'valor' => $f->sum('valor'),
                'custo' => $f->sum('custo'),
            ])->sortByDesc('segundos')->values()->all() : [],
        ])->sortByDesc('segundos')->values();

        // Séries do gráfico.
        if ($cor === 'grupo') {
            $series = $grupos->filter(fn ($g) => $g['chave'] !== '')->take(self::GRUPOS_COM_COR)->pluck('chave')->all();
            $serieDe = fn ($l) => in_array($l->g1, $series, true) ? $l->g1 : 'outros';
            $nomesSeries = $nomes + ['outros' => 'Outros'];
        } else {
            $series = ['faturavel', 'nao_faturavel'];
            $serieDe = fn ($l) => $l->fat ? 'faturavel' : 'nao_faturavel';
            $nomesSeries = ['faturavel' => 'Faturável', 'nao_faturavel' => 'Não faturável'];
        }

        // Por etiqueta, o mesmo registo aparece em várias linhas: o gráfico por faturabilidade vem de uma
        // consulta sem etiquetas, para não somar horas a mais (com cores por etiqueta, cada etiqueta conta).
        $paraGrafico = $agrupar1 === 'etiqueta' && $cor !== 'grupo'
            ? $this->consulta($quem, $filtros, $de, $ate)
                ->selectRaw("(registos_tempo.inicio at time zone {$fuso})::date as dia")
                ->selectRaw("'' as g1, (registos_tempo.faturavel and coalesce(pt.faturavel, true)) as fat, sum(registos_tempo.duracao_seg) as seg")
                ->groupByRaw('1, 3')->get()->map(fn ($l) => (object) ['dia' => $l->dia, 'g1' => '', 'fat' => (bool) $l->fat, 'seg' => (int) $l->seg])
            : $linhas;

        $barras = [];
        $vazio = array_fill_keys($series, 0) + ($cor === 'grupo' ? ['outros' => 0] : []);
        if ($mensal) {
            for ($m = $de->startOfMonth(); $m->lte($ate); $m = $m->addMonth()) {
                $chave = $m->format('Y-m');
                $partes = $vazio;
                foreach ($paraGrafico->filter(fn ($l) => str_starts_with($l->dia, $chave)) as $l) {
                    $partes[$serieDe($l)] += $l->seg;
                }
                $barras[] = ['rotulo' => ucfirst($m->translatedFormat('M')), 'dica' => ucfirst($m->translatedFormat('F Y')), 'total' => array_sum($partes), 'partes' => $partes];
            }
        } else {
            for ($d = $de; $d->lte($ate); $d = $d->addDay()) {
                $partes = $vazio;
                foreach ($paraGrafico->filter(fn ($l) => $l->dia === $d->toDateString()) as $l) {
                    $partes[$serieDe($l)] += $l->seg;
                }
                $barras[] = ['rotulo' => ucfirst($d->translatedFormat('D, d/m')), 'dica' => ucfirst($d->translatedFormat('l, d/m/Y')), 'total' => array_sum($partes), 'partes' => $partes];
            }
        }

        return [
            'total' => $totais['total'],
            'faturavel' => $totais['faturavel'],
            'valor' => $totais['valor'],
            'custo' => $totais['custo'],
            'barras' => $barras,
            'maximo' => (int) collect($barras)->max('total'),
            'mensal' => $mensal,
            'grupos' => $grupos->all(),
            'series' => $series,
            'nomes' => $nomesSeries,
        ];
    }

    /** Opções dos filtros: só o que aparece em registos (clientes, etiquetas) ou o que a pessoa vê. */
    /**
     * Grelha do relatório Semanal: uma coluna por dia (até 7 dias), por semana (até 93 dias) ou por mês;
     * uma linha por grupo (e subgrupo), com segundos e valor por coluna. Agrupar por etiqueta conta o
     * registo em cada etiqueta; os totais por coluna não duplicam.
     *
     * @return array{colunas: list<array{chave: string, rotulo: string, detalhe: string, de: string, ate: string, fimDeSemana: bool}>, linhas: list<array<string, mixed>>, totais: array<string, array{segundos: int, valor: int}>, total: array{segundos: int, valor: int}, escala: string}
     */
    public function grelha(User $quem, array $filtros, CarbonImmutable $de, CarbonImmutable $ate, string $agrupar1, ?string $agrupar2): array
    {
        $agrupar1 = isset(self::AGRUPAMENTOS[$agrupar1]) ? $agrupar1 : 'projeto';
        $agrupar2 = $agrupar2 !== null && isset(self::AGRUPAMENTOS[$agrupar2]) && $agrupar2 !== $agrupar1 ? $agrupar2 : null;
        $fuso = DB::getPdo()->quote(config('tempos.fuso'));
        $dias = $de->diffInDays($ate) + 1;
        $escala = $dias <= 7 ? 'dia' : ($dias <= 93 ? 'semana' : 'mes');

        // Colunas.
        $colunas = [];
        if ($escala === 'dia') {
            for ($d = $de; $d->lte($ate); $d = $d->addDay()) {
                $colunas[] = ['chave' => $d->toDateString(), 'rotulo' => ucfirst($d->translatedFormat('D')), 'detalhe' => $d->format('d/m'),
                    'de' => $d->toDateString(), 'ate' => $d->toDateString(), 'fimDeSemana' => $d->isWeekend()];
            }
        } elseif ($escala === 'semana') {
            for ($s = $de->startOfWeek(); $s->lte($ate); $s = $s->addWeek()) {
                $inicio = $s->max($de);
                $fim = $s->addDays(6)->min($ate);
                $colunas[] = ['chave' => $s->toDateString(), 'rotulo' => 'Sem. '.$s->isoWeek(), 'detalhe' => ($inicio->month === $fim->month ? $inicio->format('d') : $inicio->format('d/m')).'–'.$fim->format('d/m'),
                    'de' => $inicio->toDateString(), 'ate' => $fim->toDateString(), 'fimDeSemana' => false];
            }
        } else {
            for ($m = $de->startOfMonth(); $m->lte($ate); $m = $m->addMonth()) {
                $colunas[] = ['chave' => $m->format('Y-m'), 'rotulo' => ucfirst($m->translatedFormat('M')), 'detalhe' => $m->format('Y'),
                    'de' => $m->max($de)->toDateString(), 'ate' => $m->endOfMonth()->startOfDay()->min($ate)->toDateString(), 'fimDeSemana' => false];
            }
        }
        $colunaDe = fn (string $dia) => match ($escala) {
            'dia' => $dia,
            'semana' => CarbonImmutable::parse($dia)->startOfWeek()->toDateString(),
            default => substr($dia, 0, 7),
        };
        $vazio = array_fill_keys(array_column($colunas, 'chave'), ['segundos' => 0, 'valor' => 0]);

        $valor = 'round(case when registos_tempo.faturavel and coalesce(pt.faturavel, true) then registos_tempo.duracao_seg * coalesce(pt.taxa_cent, tf.valor_cent, 0) else 0 end / 3600.0)';
        $sub = $this->consulta($quem, $filtros, $de, $ate)
            ->selectRaw('registos_tempo.duracao_seg as seg')
            ->selectRaw($valor.' as valor')
            ->selectRaw("(registos_tempo.inicio at time zone {$fuso})::date::text as dia")
            ->selectRaw($this->expressao($agrupar1).' as g1')
            ->selectRaw(($agrupar2 ? $this->expressao($agrupar2) : "''").' as g2');
        $linhas = DB::query()->fromSub($sub, 'r')
            ->selectRaw('dia, g1, g2, sum(seg) as seg, sum(valor) as valor')
            ->groupBy('dia', 'g1', 'g2')
            ->get()
            ->map(fn ($l) => (object) ['col' => $colunaDe($l->dia), 'g1' => (string) $l->g1, 'g2' => (string) $l->g2, 'seg' => (int) $l->seg, 'valor' => (int) $l->valor]);

        $somar = function (Collection $ls) use ($vazio) {
            $celulas = $vazio;
            foreach ($ls as $l) {
                $celulas[$l->col]['segundos'] += $l->seg;
                $celulas[$l->col]['valor'] += $l->valor;
            }

            return ['celulas' => $celulas, 'segundos' => $ls->sum('seg'), 'valor' => $ls->sum('valor')];
        };

        $nomes = $this->nomes($agrupar1, $linhas->pluck('g1'));
        $nomes2 = $agrupar2 ? $this->nomes($agrupar2, $linhas->pluck('g2')) : [];
        $grupos = $linhas->groupBy('g1')->map(fn (Collection $g, string $chave) => ['chave' => $chave, 'nome' => $nomes[$chave] ?? '—'] + $somar($g) + [
            'filhos' => $agrupar2
                ? $g->groupBy('g2')->map(fn (Collection $f, string $k) => ['chave' => $k, 'nome' => $nomes2[$k] ?? '—'] + $somar($f))->sortByDesc('segundos')->values()->all()
                : [],
        ])->sortByDesc('segundos')->values()->all();

        // Totais por coluna sem duplicar etiquetas.
        $porDia = $this->consulta($quem, $filtros, $de, $ate)
            ->selectRaw("(registos_tempo.inicio at time zone {$fuso})::date::text as dia")
            ->selectRaw('sum(registos_tempo.duracao_seg) as seg')
            ->selectRaw('sum('.$valor.') as valor')
            ->groupByRaw('1')
            ->get()
            ->map(fn ($l) => (object) ['col' => $colunaDe($l->dia), 'seg' => (int) $l->seg, 'valor' => (int) $l->valor]);
        $totais = $somar($porDia);

        return [
            'colunas' => $colunas,
            'linhas' => $grupos,
            'totais' => $totais['celulas'],
            'total' => ['segundos' => $totais['segundos'], 'valor' => $totais['valor']],
            'escala' => $escala,
        ];
    }

    public function opcoes(User $quem): array
    {
        return [
            'membros' => User::comAcessoAosTempos()->orderBy('nome')->pluck('nome', 'id')->all(),
            'clientes' => [0 => 'Sem cliente'] + ClienteTempo::withTrashed()->whereIn('id', ProjetoTempo::withTrashed()->whereNotNull('cliente_id')->select('cliente_id'))->orderByRaw('lower(nome)')->pluck('nome', 'id')->all(),
            'projetos' => [0 => 'Sem projeto'] + ProjetoTempo::visiveisPara($quem)->orderByRaw('arquivado_em is not null, lower(nome)')->pluck('nome', 'id')->all(),
            // Só as etiquetas das horas que a pessoa vê: as dos colegas diziam no que eles andam (notas §52).
            'etiquetas' => DB::table('registos_tempo')->whereNull('deleted_at')
                ->when(! Gate::forUser($quem)->allows('tempos-ver-todos'), fn ($q) => $q->where('tecnico_id', $quem->id))
                ->selectRaw('distinct unnest(etiquetas) as e')->orderBy('e')->pluck('e')->mapWithKeys(fn ($e) => [$e => $e])->all(),
        ];
    }

    /**
     * Totais do período com os filtros (sem duplicar registos com várias etiquetas).
     *
     * @return array{total: int, faturavel: int, valor: int, custo: int, registos: int}
     */
    public function totais(User $quem, array $filtros, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $t = $this->consulta($quem, $filtros, $de, $ate)
            ->selectRaw('count(*) as registos')
            ->selectRaw('coalesce(sum(registos_tempo.duracao_seg), 0) as total')
            ->selectRaw('coalesce(sum(case when registos_tempo.faturavel and coalesce(pt.faturavel, true) then registos_tempo.duracao_seg else 0 end), 0) as faturavel')
            ->selectRaw('coalesce(round(sum(case when registos_tempo.faturavel and coalesce(pt.faturavel, true) then registos_tempo.duracao_seg * coalesce(pt.taxa_cent, tf.valor_cent, 0) else 0 end) / 3600.0), 0) as valor')
            ->selectRaw('coalesce(round(sum(registos_tempo.duracao_seg * coalesce(tc.valor_cent, 0)) / 3600.0), 0) as custo')
            ->first();

        return ['total' => (int) $t->total, 'faturavel' => (int) $t->faturavel, 'valor' => (int) $t->valor, 'custo' => (int) $t->custo, 'registos' => (int) $t->registos];
    }

    /**
     * Registos (um a um) para o relatório Detalhado: ids na ordem pedida, com faturável (fat), valor e
     * custo calculados. Ordem: data | duracao | membro | descricao | valor, "-" à frente = descendente.
     */
    public function registos(User $quem, array $filtros, CarbonImmutable $de, CarbonImmutable $ate, string $ordem = '-data'): QueryBuilder
    {
        $desc = str_starts_with($ordem, '-') ? 'desc' : 'asc';
        $valor = 'round(case when registos_tempo.faturavel and coalesce(pt.faturavel, true) then registos_tempo.duracao_seg * coalesce(pt.taxa_cent, tf.valor_cent, 0) else 0 end / 3600.0)';

        $query = $this->consulta($quem, $filtros, $de, $ate)
            ->select('registos_tempo.id')
            ->selectRaw('(registos_tempo.faturavel and coalesce(pt.faturavel, true)) as fat')
            ->selectRaw($valor.' as valor')
            ->selectRaw('round(registos_tempo.duracao_seg * coalesce(tc.valor_cent, 0) / 3600.0) as custo');

        match (ltrim($ordem, '-')) {
            'duracao' => $query->orderBy('registos_tempo.duracao_seg', $desc),
            'membro' => $query->orderByRaw('(select lower(u.nome) from utilizadores u where u.id = registos_tempo.tecnico_id) '.$desc),
            'descricao' => $query->orderByRaw("lower(coalesce(registos_tempo.descricao, '')) ".$desc),
            'valor' => $query->orderByRaw($valor.' '.$desc),
            default => $query->orderBy('registos_tempo.inicio', $desc),
        };

        return $query->orderBy('registos_tempo.inicio', $desc)->orderBy('registos_tempo.id', $desc);
    }

    /** Registos do período com os filtros, já com projeto e taxas do membro no dia. */
    public function consulta(User $quem, array $filtros, CarbonImmutable $de, CarbonImmutable $ate): QueryBuilder
    {
        $fuso = DB::getPdo()->quote(config('tempos.fuso'));
        $taxa = fn (string $tipo, string $alias) => DB::raw("lateral (
            select t.valor_cent from taxas_membros t
            where t.membro_id = me.id and t.tipo = '{$tipo}' and t.valido_de <= (registos_tempo.inicio at time zone {$fuso})::date
            order by t.valido_de desc limit 1
        ) as {$alias}");

        $membros = $filtros['membros'] ?? null;
        $projetos = $filtros['projetos'] ?? [];
        $descricao = trim((string) ($filtros['descricao'] ?? ''));

        return RegistoTempo::query()
            ->terminados()
            ->noPeriodo($de, $ate)
            ->toBase()
            ->leftJoin('projetos_tempos as pt', 'pt.id', '=', 'registos_tempo.projeto_id')
            ->leftJoin('membros_equipa as me', 'me.utilizador_id', '=', 'registos_tempo.tecnico_id')
            ->leftJoin($taxa('faturavel', 'tf'), DB::raw('true'), '=', DB::raw('true'))
            ->leftJoin($taxa('custo', 'tc'), DB::raw('true'), '=', DB::raw('true'))
            ->when($membros !== null, fn ($q) => $q->whereIn('registos_tempo.tecnico_id', $membros))
            // Cliente = o cliente (dos Tempos) do projeto do registo; 0 = sem cliente (sem projeto ou projeto sem cliente).
            ->when(($filtros['clientes'] ?? []) !== [], fn ($q) => $q->where(function ($w) use ($filtros) {
                $ids = array_values(array_filter($filtros['clientes']));
                $w->whereIn('pt.cliente_id', $ids ?: [-1]);
                if (in_array(0, $filtros['clientes'], true)) {
                    $w->orWhereNull('pt.cliente_id');
                }
            }))
            ->when($projetos !== [], fn ($q) => $q->where(function ($w) use ($projetos) {
                $ids = array_values(array_filter($projetos));
                $w->whereIn('registos_tempo.projeto_id', $ids ?: [-1]);
                if (in_array(0, $projetos, true)) {
                    $w->orWhereNull('registos_tempo.projeto_id');
                }
            }))
            ->when(($filtros['etiquetas'] ?? []) !== [], fn ($q) => $q->whereRaw('registos_tempo.etiquetas && ?::text[]', ['{'.implode(',', array_map(fn ($e) => '"'.addcslashes($e, '"\\').'"', $filtros['etiquetas'])).'}']))
            ->when($descricao !== '', fn ($q) => $q->where('registos_tempo.descricao', 'ilike', '%'.addcslashes($descricao, '%_\\').'%'))
            ->when(($filtros['auditoria'] ?? '') !== '', fn ($q) => match ($filtros['auditoria']) {
                'sem_projeto' => $q->whereNull('registos_tempo.projeto_id'),
                'sem_descricao' => $q->whereRaw("coalesce(trim(registos_tempo.descricao), '') = ''"),
                'sem_etiquetas' => $q->whereRaw('cardinality(registos_tempo.etiquetas) = 0'),
                'longos' => $q->where('registos_tempo.duracao_seg', '>', self::LONGO_SEG),
                default => $q,
            })
            ->when(($filtros['estado'] ?? '') !== '', fn ($q) => match ($filtros['estado']) {
                'faturavel' => $q->whereRaw('registos_tempo.faturavel and coalesce(pt.faturavel, true)'),
                'nao_faturavel' => $q->whereRaw('not (registos_tempo.faturavel and coalesce(pt.faturavel, true))'),
                'faturado' => $q->whereNotNull('registos_tempo.faturado_em'),
                'por_faturar' => $q->whereNull('registos_tempo.faturado_em')->whereRaw('registos_tempo.faturavel and coalesce(pt.faturavel, true)'),
                default => $q,
            });
    }

    private function expressao(string $agrupar): string
    {
        $fuso = DB::getPdo()->quote(config('tempos.fuso'));

        return match ($agrupar) {
            'cliente' => "coalesce(pt.cliente_id::text, '')",
            'membro' => "coalesce(registos_tempo.tecnico_id::text, '')",
            'etiqueta' => "unnest(case when cardinality(registos_tempo.etiquetas) = 0 then array[''::text] else registos_tempo.etiquetas end)",
            'descricao' => "coalesce(nullif(trim(registos_tempo.descricao), ''), '')",
            'dia' => "((registos_tempo.inicio at time zone {$fuso})::date)::text",
            default => "coalesce(registos_tempo.projeto_id::text, '')",
        };
    }

    /** @return array<string, string> */
    private function nomes(string $agrupar, Collection $chaves): array
    {
        $ids = $chaves->filter(fn ($c) => $c !== '')->unique()->values();
        $nomes = match ($agrupar) {
            'cliente' => ClienteTempo::withTrashed()->whereIn('id', $ids)->pluck('nome', 'id')->all(),
            'membro' => User::whereIn('id', $ids)->pluck('nome', 'id')->all(),
            'etiqueta', 'descricao' => $ids->mapWithKeys(fn ($e) => [$e => $e])->all(),
            'dia' => $ids->mapWithKeys(fn ($d) => [$d => ucfirst(CarbonImmutable::parse($d)->translatedFormat('D, d/m/Y'))])->all(),
            default => ProjetoTempo::withTrashed()->whereIn('id', $ids)->pluck('nome', 'id')->all(),
        };

        $nomes = collect($nomes)->mapWithKeys(fn ($n, $k) => [(string) $k => (string) $n])->all();
        $nomes[''] = match ($agrupar) {
            'cliente' => 'Sem cliente',
            'membro' => 'Sem membro',
            'etiqueta' => 'Sem etiqueta',
            'descricao' => 'Sem descrição',
            default => 'Sem projeto',
        };

        return $nomes;
    }
}

<?php

namespace App\Services\Tempos;

use App\Models\AtribuicaoTempo;
use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Relatório Atribuições (como o "Assignments report" do Clockify): por pessoa e projeto, horas agendadas
 * (atribuições, só a parte dentro do período) contra horas registadas nesse projeto, diferença e estado.
 * Agrupa por membro, projeto ou cliente (do projeto), em dois níveis. Registos sem projeto não entram.
 */
class RelatorioAtribuicoes
{
    public const AGRUPAMENTOS = ['membro' => 'Membro', 'projeto' => 'Projeto', 'cliente' => 'Cliente'];

    public const ESTADOS = [
        'por_comecar' => 'Por começar',
        'em_curso' => 'Em curso',
        'cumprida' => 'Cumprida',
        'abaixo' => 'Abaixo do agendado',
        'acima' => 'Acima do agendado',
        'sem_atribuicao' => 'Sem atribuição',
        'sem_tempo' => 'Sem tempo',
    ];

    /**
     * @param  array{membros?: list<int>|null, clientes?: list<int>, projetos?: list<int>}  $filtros  membros null = todos
     * @return array{grupos: list<array<string, mixed>>, agendado: int, registado: int, atribuicoes: Collection<int, AtribuicaoTempo>}
     */
    public function gerar(array $filtros, CarbonImmutable $de, CarbonImmutable $ate, string $agrupar1, ?string $agrupar2, bool $semTempo = false, ?CarbonImmutable $hoje = null): array
    {
        $agrupar1 = isset(self::AGRUPAMENTOS[$agrupar1]) ? $agrupar1 : 'membro';
        $agrupar2 = $agrupar2 !== null && isset(self::AGRUPAMENTOS[$agrupar2]) && $agrupar2 !== $agrupar1 ? $agrupar2 : null;
        $hoje ??= CarbonImmutable::parse(CarbonImmutable::now(config('tempos.fuso'))->toDateString());

        $membros = $filtros['membros'] ?? null;
        $projetos = $filtros['projetos'] ?? [];
        $clientes = $filtros['clientes'] ?? [];
        $filtrarProjetos = fn ($q, string $coluna) => $q
            ->when($projetos !== [], fn ($q) => $q->whereIn($coluna, $projetos))
            ->when($clientes !== [], fn ($q) => $q->whereIn($coluna, ProjetoTempo::withTrashed()->whereIn('cliente_id', $clientes)->select('id')));

        $atribuicoes = AtribuicaoTempo::query()
            ->with(['utilizador:id,nome', 'projeto:id,nome,cor,cliente_id,arquivado_em'])
            ->noPeriodo($de, $ate)
            ->whereNotNull('utilizador_id')->whereNotNull('projeto_id')
            ->when($membros !== null, fn ($q) => $q->whereIn('utilizador_id', $membros))
            ->tap(fn ($q) => $filtrarProjetos($q, 'projeto_id'))
            ->orderBy('de')
            ->get();

        $registado = RegistoTempo::query()->terminados()->noPeriodo($de, $ate)
            ->whereNotNull('projeto_id')
            ->when($membros !== null, fn ($q) => $q->whereIn('tecnico_id', $membros))
            ->tap(fn ($q) => $filtrarProjetos($q, 'projeto_id'))
            ->toBase()
            ->groupBy('tecnico_id', 'projeto_id')
            ->selectRaw('tecnico_id, projeto_id, sum(duracao_seg) as segundos')
            ->get();

        // Pares pessoa × projeto.
        $pares = [];
        $par = function (int $u, int $p) use (&$pares) {
            return $pares[$u.'|'.$p] ??= ['membro' => $u, 'projeto' => $p, 'agendado' => 0, 'registado' => 0, 'de' => null, 'ate' => null];
        };
        foreach ($atribuicoes as $a) {
            $linha = $par($a->utilizador_id, $a->projeto_id);
            $linha['agendado'] += $a->segundosEntre($de, $ate);
            $linha['de'] = $linha['de'] === null || $a->de->lt($linha['de']) ? $a->de : $linha['de'];
            $linha['ate'] = $linha['ate'] === null || $a->ate->gt($linha['ate']) ? $a->ate : $linha['ate'];
            $pares[$a->utilizador_id.'|'.$a->projeto_id] = $linha;
        }
        foreach ($registado as $r) {
            $linha = $par((int) $r->tecnico_id, (int) $r->projeto_id);
            $linha['registado'] += (int) $r->segundos;
            $pares[$r->tecnico_id.'|'.$r->projeto_id] = $linha;
        }

        $nomesMembros = User::whereIn('id', collect($pares)->pluck('membro')->unique())->pluck('nome', 'id');
        $projetosInfo = ProjetoTempo::withTrashed()->whereIn('id', collect($pares)->pluck('projeto')->unique())->get(['id', 'nome', 'cor', 'cliente_id'])->keyBy('id');
        $nomesClientes = ClienteTempo::withTrashed()->whereIn('id', $projetosInfo->pluck('cliente_id')->filter()->unique())->pluck('nome', 'id');

        $pares = collect($pares)->map(fn ($l) => $l + [
            'cliente' => $projetosInfo->get($l['projeto'])?->cliente_id ?? 0,
        ]);

        // Pessoas sem tempo (nem agendado nem registado), a pedido, quando se agrupa por membro.
        $semTempoIds = [];
        if ($semTempo && $agrupar1 === 'membro') {
            $semTempoIds = User::comAcessoAosTempos()
                ->when($membros !== null, fn ($q) => $q->whereIn('id', $membros))
                ->whereNotIn('id', $pares->pluck('membro')->unique())
                ->orderBy('nome')->pluck('nome', 'id')->all();
        }

        $nome = fn (string $agrupar, int $chave) => match ($agrupar) {
            'membro' => $nomesMembros[$chave] ?? '—',
            'projeto' => $projetosInfo->get($chave)?->nome ?? '—',
            default => $chave ? ($nomesClientes[$chave] ?? '—') : 'Sem cliente',
        };
        $cor = fn (string $agrupar, int $chave) => $agrupar === 'projeto' ? ($projetosInfo->get($chave)?->cor ?? null) : null;

        $montar = function (Collection $linhas, string $agrupar, int $chave) use ($nome, $cor, $hoje) {
            $agendado = (int) $linhas->sum('agendado');
            $registado = (int) $linhas->sum('registado');
            $inicios = $linhas->pluck('de')->filter();
            $fins = $linhas->pluck('ate')->filter();

            return [
                'chave' => (string) $chave,
                'nome' => $nome($agrupar, $chave),
                'cor' => $cor($agrupar, $chave),
                'agendado' => $agendado,
                'registado' => $registado,
                'diferenca' => $registado - $agendado,
                'estado' => self::estado($agendado, $registado, $inicios->min(), $fins->max(), $hoje),
            ];
        };

        $grupos = $pares->groupBy($agrupar1)->map(function (Collection $g, $chave) use ($montar, $agrupar1, $agrupar2) {
            $linha = $montar($g, $agrupar1, (int) $chave);
            $linha['filhos'] = $agrupar2
                ? $g->groupBy($agrupar2)->map(fn (Collection $f, $k) => $montar($f, $agrupar2, (int) $k))->values()->all()
                : [];

            return $linha;
        })->values();

        foreach ($semTempoIds as $id => $nomeMembro) {
            $grupos->push(['chave' => (string) $id, 'nome' => $nomeMembro, 'cor' => null, 'agendado' => 0, 'registado' => 0, 'diferenca' => 0, 'estado' => 'sem_tempo', 'filhos' => []]);
        }

        return [
            'grupos' => $grupos->all(),
            'agendado' => (int) $pares->sum('agendado'),
            'registado' => (int) $pares->sum('registado'),
            'atribuicoes' => $atribuicoes,
        ];
    }

    /** Estado de uma linha: comparação do registado com o agendado e o calendário da atribuição. */
    public static function estado(int $agendado, int $registado, ?CarbonImmutable $de, ?CarbonImmutable $ate, CarbonImmutable $hoje): string
    {
        return match (true) {
            $agendado === 0 && $registado === 0 => 'sem_tempo',
            $agendado === 0 => 'sem_atribuicao',
            $registado > $agendado => 'acima',
            $registado === $agendado => 'cumprida',
            $registado === 0 && $de !== null && $de->gt($hoje) => 'por_comecar',
            $ate !== null && $ate->lt($hoje) => 'abaixo',
            default => 'em_curso',
        };
    }
}

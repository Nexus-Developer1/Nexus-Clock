<?php

namespace App\Services\Tempos\Faturacao;

use App\Enums\ModoArredondamento;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Models\ExportacaoFaturacao;
use App\Models\MesTempo;
use App\Models\RegistoTempo;
use App\Models\Tarifa;
use App\Services\Tempos\CalculadorHorasIncluidas;
use App\Services\Tempos\ResolvedorTarifa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * O que se fatura num mês (regra 13): só registos FATURÁVEIS, FECHADOS e AINDA NÃO FATURADOS.
 *
 * - Contrato com horas incluídas → só o EXCEDENTE. Calcula-se sobre as durações ARREDONDADAS segundo
 *   as horas incluídas (arredondamento por registo) e acumula-se no período (mês, trimestre, ano,
 *   total): fatura-se no mês o excedente acumulado até ao fim do mês menos o que já foi faturado
 *   nesse período em exportações de meses anteriores. Assim um trimestre fatura o excedente à medida
 *   que acontece, sem nunca faturar duas vezes. Excedente "não faturável" aparece mas não se exporta.
 * - Contrato sem horas incluídas nesses dias → todas as horas, arredondadas a 15 min para cima.
 * - Registos sem contrato → todas as horas, por cliente, com o mesmo arredondamento.
 *
 * Preço: a tarifa do excedente das horas incluídas, ou a cadeia normal (contrato → cliente → global)
 * no último dia; sem horas incluídas, a tarifa de cada registo (com técnico) — uma linha por preço.
 *
 * Devolve linhas prontas a exportar e um resumo por contrato para a pré-visualização.
 */
class CalculadorFaturacao
{
    public const ARREDONDAMENTO_MIN_OMISSAO = 15;

    public function __construct(
        private readonly CalculadorHorasIncluidas $calculador,
        private readonly ResolvedorTarifa $resolvedor,
    ) {}

    /**
     * @param  bool  $soFechados  false na pré-visualização de um mês ainda aberto
     * @return array{linhas: list<array<string, mixed>>, resumo: list<array<string, mixed>>, registoIds: list<int>, avisos: list<string>}
     */
    public function calcular(CarbonImmutable $mes, bool $soFechados = true): array
    {
        $inicio = MesTempo::inicioDoMes($mes);
        $fim = $inicio->endOfMonth()->startOfDay();
        $rotuloMes = MesTempo::rotulo($inicio);

        $candidatos = RegistoTempo::query()
            ->terminados()
            ->faturaveis()
            ->noPeriodo($inicio, $fim)
            ->whereNull('faturado_em')
            ->when($soFechados, fn ($q) => $q->whereNotNull('fechado_em'))
            ->where('duracao_seg', '>', 0)
            ->orderBy('inicio')
            ->get(['id', 'tecnico_id', 'cliente_id', 'contrato_id', 'inicio', 'duracao_seg']);

        $linhas = [];
        $resumo = [];
        $registoIds = [];
        $avisos = [];

        $contratos = Contrato::withTrashed()->with('cliente')->whereIn('id', $candidatos->pluck('contrato_id')->filter()->unique())->get()->keyBy('id');

        foreach ($candidatos->whereNotNull('contrato_id')->groupBy('contrato_id') as $contratoId => $doContrato) {
            $contrato = $contratos->get($contratoId);
            if (! $contrato) {
                continue;
            }
            $cobertos = collect();

            $conjuntos = ContratoHorasIncluidas::with('tarifaExcedente')
                ->where('contrato_id', $contratoId)
                ->sobrepostasA($inicio, $fim)
                ->orderBy('valido_de')
                ->get();

            foreach ($conjuntos as $horas) {
                $ate = $horas->valido_ate !== null && $horas->valido_ate->lt($fim) ? $horas->valido_ate : $fim;
                $de = $horas->valido_de->gt($inicio) ? $horas->valido_de : $inicio;
                $doConjunto = $doContrato->filter(fn (RegistoTempo $r) => $r->dia()->between($de, $ate));
                $cobertos = $cobertos->merge($doConjunto);

                $periodo = $this->excedenteAte($horas, $ate);
                $jaFaturado = $this->jaFaturado($horas, $periodo['inicio'], $inicio);
                $excedenteMes = max(0, $periodo['excedente'] - $jaFaturado);

                $linha = null;
                if ($excedenteMes > 0 && $doConjunto->isNotEmpty()) {
                    $tarifa = $horas->tarifaExcedente ?? $this->resolvedor->resolver($contrato->id, $contrato->cliente_id, null, $ate);
                    $linha = $this->linha('excedente', $contrato->cliente, $contrato, $horas, $periodo['inicio'],
                        'Horas excedentes contrato '.$contrato->numero.' — '.$rotuloMes, $excedenteMes, $tarifa, $horas->excedente_faturavel);

                    if ($linha['faturavel']) {
                        $linhas[] = $linha;
                        $registoIds = array_merge($registoIds, $doConjunto->pluck('id')->all());
                    }
                }

                $resumo[] = [
                    'contrato' => $contrato,
                    'tipo' => 'incluidas',
                    'periodo' => $horas->periodo->rotulo().' · '.$periodo['inicio']->format('d/m/Y').' – '.$periodo['fim']->format('d/m/Y'),
                    'incluidas' => $periodo['incluidas'],
                    'transportado' => $periodo['transportado'],
                    'consumo' => $periodo['consumo'],
                    'excedente_acumulado' => $periodo['excedente'],
                    'ja_faturado' => $jaFaturado,
                    'a_faturar' => $excedenteMes,
                    'arredondamento' => $horas->arredondamento_min === 0 ? 'sem' : $horas->arredondamento_min.' min '.mb_strtolower($horas->arredondamento_modo->rotulo(), 'UTF-8'),
                    'linha' => $linha,
                ];
            }

            // Dias do contrato sem horas incluídas: todas as horas, por preço.
            $semIncluidas = $doContrato->reject(fn (RegistoTempo $r) => $cobertos->contains('id', $r->id));
            foreach ($this->porTarifa($semIncluidas) as [$tarifa, $segundos, $ids]) {
                $linha = $this->linha('sem_incluidas', $contrato->cliente, $contrato, null, null,
                    'Horas contrato '.$contrato->numero.' — '.$rotuloMes, $segundos, $tarifa, true);
                $linhas[] = $linha;
                $registoIds = array_merge($registoIds, $ids);
                $resumo[] = ['contrato' => $contrato, 'tipo' => 'sem_incluidas', 'periodo' => 'Sem horas incluídas', 'incluidas' => 0, 'transportado' => 0,
                    'consumo' => $segundos, 'excedente_acumulado' => $segundos, 'ja_faturado' => 0, 'a_faturar' => $segundos, 'arredondamento' => '15 min para cima', 'linha' => $linha];
            }
        }

        // Sem contrato: por cliente e preço.
        $clientes = Cliente::withTrashed()->whereIn('id', $candidatos->whereNull('contrato_id')->pluck('cliente_id')->filter()->unique())->get()->keyBy('id');
        foreach ($candidatos->whereNull('contrato_id')->groupBy('cliente_id') as $clienteId => $doCliente) {
            $cliente = $clientes->get($clienteId);
            if (! $cliente) {
                continue;
            }
            foreach ($this->porTarifa($doCliente) as [$tarifa, $segundos, $ids]) {
                $linhas[] = $this->linha('sem_contrato', $cliente, null, null, null, 'Horas sem contrato — '.$rotuloMes, $segundos, $tarifa, true);
                $registoIds = array_merge($registoIds, $ids);
            }
        }

        foreach ($linhas as $l) {
            if ($l['preco_hora_cent'] === null) {
                $avisos[] = 'Sem tarifa para «'.$l['descricao'].'» ('.$l['cliente'].'). Defina a tarifa antes de exportar.';
            }
        }

        usort($linhas, fn ($a, $b) => [mb_strtolower($a['cliente']), $a['contrato'] ?? '', $a['descricao']] <=> [mb_strtolower($b['cliente']), $b['contrato'] ?? '', $b['descricao']]);

        return ['linhas' => $linhas, 'resumo' => $resumo, 'registoIds' => array_values(array_unique($registoIds)), 'avisos' => $avisos];
    }

    /**
     * Excedente ACUMULADO no período das horas incluídas que contém $ate, contado até $ate, sobre
     * durações arredondadas (e transporte calculado com as mesmas durações arredondadas).
     *
     * @return array{inicio: CarbonImmutable, fim: CarbonImmutable, incluidas: int, transportado: int, consumo: int, excedente: int}
     */
    public function excedenteAte(ContratoHorasIncluidas $horas, CarbonImmutable $ate): array
    {
        $periodos = $this->calculador->periodos($horas, $ate);

        $duracoes = RegistoTempo::query()
            ->doContrato($horas->contrato_id)
            ->faturaveis()
            ->terminados()
            ->noPeriodo($periodos[0][0], $ate)
            ->get(['inicio', 'duracao_seg'])
            ->map(fn (RegistoTempo $r) => [$r->dia()->toDateString(), $horas->arredondar($r->duracao_seg)]);

        $comConsumo = array_map(function (array $p) use ($duracoes, $ate) {
            [$ini, $fimP] = $p;
            $limite = $fimP->min($ate)->toDateString();
            $soma = $duracoes->filter(fn ($d) => $d[0] >= $ini->toDateString() && $d[0] <= $limite)->sum(fn ($d) => $d[1]);

            return [$ini, $fimP, (int) $soma, 0];
        }, $periodos);

        $acumulados = CalculadorHorasIncluidas::acumular($horas, $comConsumo);
        $ultimo = $acumulados[array_key_last($acumulados)];

        return [
            'inicio' => $ultimo->inicio,
            'fim' => $ultimo->fim,
            'incluidas' => $ultimo->incluidasSeg,
            'transportado' => $ultimo->transportadoSeg,
            'consumo' => $ultimo->faturavelSeg,
            'excedente' => $ultimo->excedenteSeg,
        ];
    }

    /** Excedente já faturado deste período em exportações em vigor de meses ANTERIORES. */
    private function jaFaturado(ContratoHorasIncluidas $horas, CarbonImmutable $periodoInicio, CarbonImmutable $mes): int
    {
        return (int) ExportacaoFaturacao::emVigor()
            ->where('mes', '<', $mes->toDateString())
            ->where('mes', '>=', MesTempo::inicioDoMes($periodoInicio)->toDateString())
            ->get(['linhas'])
            ->sum(fn (ExportacaoFaturacao $e) => collect($e->linhas)
                ->where('tipo', 'excedente')
                ->where('contrato_horas_incluidas_id', $horas->id)
                ->where('periodo_inicio', $periodoInicio->toDateString())
                ->sum('segundos'));
    }

    /**
     * Agrupa registos pelo preço da tarifa de cada um (cadeia com técnico, no dia do registo),
     * arredondando cada registo a 15 min para cima.
     *
     * @param  Collection<int, RegistoTempo>  $registos
     * @return list<array{0: Tarifa|null, 1: int, 2: list<int>}>
     */
    private function porTarifa(Collection $registos): array
    {
        $grupos = [];
        foreach ($registos as $r) {
            $tarifa = $this->resolvedor->resolver($r->contrato_id, $r->cliente_id, $r->tecnico_id, $r->dia());
            $chave = $tarifa?->id ?? 0;
            $grupos[$chave] ??= [$tarifa, 0, []];
            $grupos[$chave][1] += ModoArredondamento::Cima->aplicar($r->duracao_seg, self::ARREDONDAMENTO_MIN_OMISSAO);
            $grupos[$chave][2][] = $r->id;
        }

        return array_values($grupos);
    }

    /** @return array<string, mixed> */
    private function linha(string $tipo, ?Cliente $cliente, ?Contrato $contrato, ?ContratoHorasIncluidas $horas, ?CarbonImmutable $periodoInicio,
        string $descricao, int $segundos, ?Tarifa $tarifa, bool $faturavel): array
    {
        return [
            'tipo' => $tipo,
            'cliente_id' => $cliente?->id,
            'cliente' => $cliente?->nome ?? '—',
            'nif' => $cliente?->nif,
            'id_erp' => $cliente?->id_erp,
            'contrato_id' => $contrato?->id,
            'contrato' => $contrato?->numero,
            'contrato_horas_incluidas_id' => $horas?->id,
            'periodo_inicio' => $periodoInicio?->toDateString(),
            'descricao' => $descricao,
            'segundos' => $segundos,
            'tarifa_id' => $tarifa?->id,
            'preco_hora_cent' => $tarifa?->preco_hora_cent,
            'valor_cent' => $tarifa ? (int) round($segundos * $tarifa->preco_hora_cent / 3600) : 0,
            'faturavel' => $faturavel,
        ];
    }
}

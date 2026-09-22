<?php

namespace App\Services\Tempos;

use App\Enums\PeriodoHorasIncluidas;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Models\RegistoTempo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Consumo, transporte (rollover) e excedente das horas incluídas de um contrato.
 *
 * Regras (docs/modulo-tempos.md §2):
 *  8. Consumo = soma de `duracao_seg` dos registos FATURÁVEIS do contrato no período, sem
 *     arredondamento. O arredondamento só existe em consumoParaFaturacao().
 *  9. Excedente = max(0, consumo − incluídas − transportado).
 * 10. Só transita se `transita`; o transporte recomeça do zero em cada conjunto de horas
 *     incluídas (não passa de uma validade para a seguinte).
 *
 * Períodos: mês, trimestre e ano CIVIS, cortados pela validade (ex.: validade a partir de 15/03,
 * mensal → 15/03–31/03, 01/04–30/04…). "total" = a validade inteira.
 *
 * Calcula a partir dos registos (fonte de verdade). Para listagens e relatórios longos usa-se a
 * view materializada `contrato_consumo_periodo`; este cálculo é o do detalhe de um contrato.
 */
class CalculadorHorasIncluidas
{
    /** Horas incluídas ativas num contrato num dia (só pode haver uma). */
    public function ativaEm(Contrato|int $contrato, CarbonInterface|string $dia): ?ContratoHorasIncluidas
    {
        return ContratoHorasIncluidas::query()
            ->where('contrato_id', $contrato instanceof Contrato ? $contrato->id : $contrato)
            ->ativasEm($dia)
            ->orderByDesc('valido_de')
            ->first();
    }

    /**
     * Períodos de um conjunto de horas incluídas, desde o início da validade até ao período que
     * contém $ate (ou o fim da validade, se for antes).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function periodos(ContratoHorasIncluidas $horas, CarbonInterface|string $ate): array
    {
        $ate = CarbonImmutable::parse($ate instanceof CarbonInterface ? $ate->toDateString() : $ate);
        $validoDe = $horas->valido_de;
        $validoAte = $horas->valido_ate;

        if ($horas->periodo === PeriodoHorasIncluidas::Total) {
            return [[$validoDe, $validoAte ?? $ate]];
        }

        $meses = $horas->periodo->meses();
        $cursor = match ($horas->periodo) {
            PeriodoHorasIncluidas::Mensal => $validoDe->startOfMonth(),
            PeriodoHorasIncluidas::Trimestral => $validoDe->startOfQuarter(),
            default => $validoDe->startOfYear(),
        };
        $limite = $validoAte !== null && $validoAte->lt($ate) ? $validoAte : $ate;

        $periodos = [];
        while ($cursor->lte($limite)) {
            $seguinte = $cursor->addMonthsNoOverflow($meses);
            $fim = $seguinte->subDay();
            $periodos[] = [
                $cursor->max($validoDe),
                $validoAte !== null ? $fim->min($validoAte) : $fim,
            ];
            $cursor = $seguinte;
        }

        return $periodos;
    }

    /**
     * Consumo por período das horas incluídas de um contrato que tocam em [$de, $ate]. O
     * transporte é acumulado desde o início de cada validade, mesmo que $de seja posterior.
     *
     * @return list<PeriodoConsumo>
     */
    public function resumo(Contrato|int $contrato, CarbonInterface|string $de, CarbonInterface|string $ate): array
    {
        $contratoId = $contrato instanceof Contrato ? $contrato->id : $contrato;
        $de = CarbonImmutable::parse($de instanceof CarbonInterface ? $de->toDateString() : $de);

        $conjuntos = ContratoHorasIncluidas::query()
            ->where('contrato_id', $contratoId)
            ->sobrepostasA($de, $ate)
            ->orderBy('valido_de')
            ->get();

        $resultado = [];

        foreach ($conjuntos as $horas) {
            $periodos = $this->periodos($horas, $ate);
            if ($periodos === []) {
                continue;
            }

            $porDia = $this->consumoPorDia($contratoId, $periodos[0][0], end($periodos)[1]);

            $comConsumo = array_map(function (array $periodo) use ($porDia) {
                [$inicio, $fim] = $periodo;
                $doPeriodo = $porDia->filter(fn ($linha, string $dia) => $dia >= $inicio->toDateString() && $dia <= $fim->toDateString());

                return [$inicio, $fim, (int) $doPeriodo->sum('faturavel_seg'), (int) $doPeriodo->sum('nao_faturavel_seg')];
            }, $periodos);

            foreach (self::acumular($horas, $comConsumo) as $periodo) {
                if ($periodo->fim->gte($de)) {
                    $resultado[] = $periodo;
                }
            }
        }

        return $resultado;
    }

    /**
     * Regras 9 e 10 aplicadas a uma sequência de períodos de UM conjunto de horas incluídas, pela
     * ordem e desde o início da validade: transporte (só se transita) e excedente. Serve o cálculo a
     * partir dos registos (resumo) e o dos relatórios a partir da view materializada.
     *
     * @param  iterable<array{0: CarbonImmutable, 1: CarbonImmutable, 2: int, 3: int}>  $periodos  [início, fim, faturável, não faturável]
     * @return list<PeriodoConsumo>
     */
    public static function acumular(ContratoHorasIncluidas $horas, iterable $periodos): array
    {
        $resultado = [];
        $transportado = 0;
        $incluidas = $horas->incluidasSeg();

        foreach ($periodos as [$inicio, $fim, $faturavel, $naoFaturavel]) {
            $excedente = max(0, $faturavel - $incluidas - $transportado);
            $sobra = $horas->transita ? max(0, $incluidas + $transportado - $faturavel) : 0;

            $resultado[] = new PeriodoConsumo($horas, $inicio, $fim, $incluidas, $transportado, $faturavel, $naoFaturavel, $excedente, $sobra);
            $transportado = $sobra;
        }

        return $resultado;
    }

    /** Período de horas incluídas que contém o dia (com transporte e excedente), ou null. */
    public function periodoDe(Contrato|int $contrato, CarbonInterface|string $dia): ?PeriodoConsumo
    {
        $dia = CarbonImmutable::parse($dia instanceof CarbonInterface ? $dia->toDateString() : $dia);

        foreach ($this->resumo($contrato, $dia, $dia) as $periodo) {
            if ($dia->between($periodo->inicio, $periodo->fim)) {
                return $periodo;
            }
        }

        return null;
    }

    /**
     * Consumo bruto de um contrato entre dois dias, com ou sem horas incluídas (regra 8).
     *
     * @return array{faturavel_seg: int, nao_faturavel_seg: int}
     */
    public function consumo(Contrato|int $contrato, CarbonInterface|string $de, CarbonInterface|string $ate): array
    {
        $porDia = $this->consumoPorDia($contrato instanceof Contrato ? $contrato->id : $contrato, $de, $ate);

        return [
            'faturavel_seg' => (int) $porDia->sum('faturavel_seg'),
            'nao_faturavel_seg' => (int) $porDia->sum('nao_faturavel_seg'),
        ];
    }

    /**
     * Consumo faturável para FATURAÇÃO: cada registo arredondado segundo as horas incluídas
     * (minutos e modo) antes de somar. Só aqui se arredonda.
     */
    public function consumoParaFaturacao(ContratoHorasIncluidas $horas, CarbonInterface|string $de, CarbonInterface|string $ate): int
    {
        return (int) RegistoTempo::query()
            ->doContrato($horas->contrato_id)
            ->faturaveis()
            ->terminados()
            ->noPeriodo($de, $ate)
            ->pluck('duracao_seg')
            ->sum(fn (int $segundos) => $horas->arredondar($segundos));
    }

    /** @return Collection<string, object{faturavel_seg: int, nao_faturavel_seg: int}> chave = 'Y-m-d' */
    private function consumoPorDia(int $contratoId, CarbonInterface|string $de, CarbonInterface|string $ate): Collection
    {
        $fuso = config('tempos.fuso');

        return RegistoTempo::query()
            ->toBase()
            ->where('contrato_id', $contratoId)
            ->whereNull('deleted_at')
            ->whereNotNull('duracao_seg')
            ->where('inicio', '>=', RegistoTempo::inicioDoDia($de))
            ->where('inicio', '<', RegistoTempo::inicioDoDia(CarbonImmutable::parse($ate instanceof CarbonInterface ? $ate->toDateString() : $ate)->addDay()))
            ->selectRaw('(inicio at time zone ?)::date as dia', [$fuso])
            ->selectRaw('coalesce(sum(duracao_seg) filter (where faturavel), 0) as faturavel_seg')
            ->selectRaw('coalesce(sum(duracao_seg) filter (where not faturavel), 0) as nao_faturavel_seg')
            // Pelo alias: com o fuso como parâmetro, o PostgreSQL não vê a expressão do SELECT e a
            // do GROUP BY como a mesma ($1 ≠ $4).
            ->groupBy('dia')
            ->get()
            ->keyBy(fn ($linha) => (string) $linha->dia);
    }
}

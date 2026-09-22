<?php

namespace App\Services\Tempos\Relatorios;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Período de um relatório: mês, trimestre ou ano civis, ou um intervalo livre. Datas locais
 * (Europe/Lisbon), ambas incluídas. Construído a partir do que vem no URL — valores inválidos caem
 * no mês corrente, nunca rebentam.
 *
 * Referência no URL: mês `2026-09`, trimestre `2026-T3`, ano `2026`; personalizado usa `de`/`ate`.
 */
final readonly class PeriodoRelatorio
{
    public const TIPOS = [
        'mes' => 'Mês',
        'trimestre' => 'Trimestre',
        'ano' => 'Ano',
        'personalizado' => 'Personalizado',
    ];

    private function __construct(
        public string $tipo,
        public CarbonImmutable $de,
        public CarbonImmutable $ate,
    ) {}

    public static function criar(?string $tipo, ?string $referencia = null, ?string $de = null, ?string $ate = null): self
    {
        $tipo = isset(self::TIPOS[$tipo]) ? $tipo : 'mes';
        $hoje = CarbonImmutable::now(config('tempos.fuso'))->startOfDay();
        $hoje = CarbonImmutable::parse($hoje->toDateString());

        try {
            return match ($tipo) {
                'mes' => self::doMes(preg_match('/^(\d{4})-(\d{2})$/', (string) $referencia, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12
                    ? CarbonImmutable::create((int) $m[1], (int) $m[2], 1) : $hoje),
                'trimestre' => self::doTrimestre(preg_match('/^(\d{4})-T([1-4])$/', (string) $referencia, $m)
                    ? CarbonImmutable::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1) : $hoje),
                'ano' => self::doAno(preg_match('/^\d{4}$/', (string) $referencia) ? CarbonImmutable::create((int) $referencia, 1, 1) : $hoje),
                default => self::personalizado($de, $ate, $hoje),
            };
        } catch (Throwable) {
            return self::doMes($hoje);
        }
    }

    public static function doMes(CarbonImmutable $dia): self
    {
        return new self('mes', $dia->startOfMonth(), $dia->endOfMonth()->startOfDay());
    }

    public static function doTrimestre(CarbonImmutable $dia): self
    {
        return new self('trimestre', $dia->startOfQuarter(), $dia->endOfQuarter()->startOfDay());
    }

    public static function doAno(CarbonImmutable $dia): self
    {
        return new self('ano', $dia->startOfYear(), $dia->endOfYear()->startOfDay());
    }

    private static function personalizado(?string $de, ?string $ate, CarbonImmutable $hoje): self
    {
        $valida = fn (?string $d) => $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? CarbonImmutable::parse($d) : null;
        $inicio = $valida($de) ?? $hoje->startOfMonth();
        $fim = $valida($ate) ?? $hoje;

        if ($fim->lt($inicio)) {
            [$inicio, $fim] = [$fim, $inicio];
        }

        return new self('personalizado', $inicio, $fim);
    }

    /** Referência para o URL (vazia no personalizado). */
    public function referencia(): string
    {
        return match ($this->tipo) {
            'mes' => $this->de->format('Y-m'),
            'trimestre' => $this->de->format('Y').'-T'.$this->de->quarter,
            'ano' => $this->de->format('Y'),
            default => '',
        };
    }

    public function anterior(): self
    {
        return match ($this->tipo) {
            'mes' => self::doMes($this->de->subMonthNoOverflow()),
            'trimestre' => self::doTrimestre($this->de->subMonthsNoOverflow(3)),
            'ano' => self::doAno($this->de->subYear()),
            default => new self('personalizado', $this->de->subDays($this->dias()), $this->de->subDay()),
        };
    }

    public function seguinte(): self
    {
        return match ($this->tipo) {
            'mes' => self::doMes($this->de->addMonthNoOverflow()),
            'trimestre' => self::doTrimestre($this->de->addMonthsNoOverflow(3)),
            'ano' => self::doAno($this->de->addYear()),
            default => new self('personalizado', $this->ate->addDay(), $this->ate->addDays($this->dias())),
        };
    }

    /** Número de dias do período (ambos incluídos). */
    public function dias(): int
    {
        return (int) $this->de->diffInDays($this->ate) + 1;
    }

    public function rotulo(): string
    {
        return match ($this->tipo) {
            'mes' => $this->de->translatedFormat('F').' de '.$this->de->year,
            'trimestre' => $this->de->quarter.'.º trimestre de '.$this->de->year,
            'ano' => (string) $this->de->year,
            default => $this->de->format('d/m/Y').' a '.$this->ate->format('d/m/Y'),
        };
    }

    /** Parte do período no query string (para links e exportações). @return array<string, string> */
    public function paraQuery(): array
    {
        return $this->tipo === 'personalizado'
            ? ['periodo' => 'personalizado', 'de' => $this->de->toDateString(), 'ate' => $this->ate->toDateString()]
            : ['periodo' => $this->tipo, 'ref' => $this->referencia()];
    }

    public function sobrepoe(CarbonImmutable $inicio, CarbonImmutable $fim): bool
    {
        return $inicio->lte($this->ate) && $fim->gte($this->de);
    }
}

<?php

namespace App\Services\Tempos\Faturacao;

use App\Models\MesTempo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * "Este dia está num mês fechado?" — perguntado muitas vezes por pedido (policy dos registos, folha
 * de horas, gestão de tarifas). Lê os meses fechados uma vez por pedido (registado como `scoped`).
 */
class MesesFechados
{
    /** @var Collection<string, MesTempo>|null chave 'Y-m' */
    private ?Collection $fechados = null;

    public function estaFechado(CarbonInterface|string $dia): bool
    {
        return $this->fechados()->has(MesTempo::inicioDoMes($dia)->format('Y-m'));
    }

    /** Meses fechados que tocam em [$de, $ate] ($ate null = sem fim), por ordem. @return list<CarbonImmutable> */
    public function tocados(CarbonInterface|string $de, CarbonInterface|string|null $ate): array
    {
        $inicio = MesTempo::inicioDoMes($de);
        $fim = $ate === null ? null : MesTempo::inicioDoMes($ate);

        return $this->fechados()
            ->map(fn (MesTempo $m) => $m->mes)
            ->filter(fn (CarbonImmutable $mes) => $mes->gte($inicio) && ($fim === null || $mes->lte($fim)))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Pedaços de [$de, $ate] que caem em meses fechados, como texto comparável ("2026-08-01..2026-08-31").
     * Serve para saber se mudar uma validade mexe nos dias de um mês fechado.
     *
     * @return list<string>
     */
    public function intersecoes(CarbonInterface $de, ?CarbonInterface $ate): array
    {
        return array_map(function (CarbonImmutable $mes) use ($de, $ate) {
            $fimMes = $mes->endOfMonth()->startOfDay();
            $inicio = $mes->max(CarbonImmutable::parse($de->toDateString()));
            $fim = $ate === null ? $fimMes : $fimMes->min(CarbonImmutable::parse($ate->toDateString()));

            return $inicio->toDateString().'..'.$fim->toDateString();
        }, $this->tocados($de, $ate));
    }

    /** "agosto de 2026, setembro de 2026" dos meses fechados que tocam em [$de, $ate]. */
    public function rotulos(CarbonInterface $de, ?CarbonInterface $ate): string
    {
        return implode(', ', array_map(fn (CarbonImmutable $m) => MesTempo::rotulo($m), $this->tocados($de, $ate)));
    }

    public function esquecer(): void
    {
        $this->fechados = null;
    }

    /** @return Collection<string, MesTempo> */
    private function fechados(): Collection
    {
        return $this->fechados ??= MesTempo::where('estado', MesTempo::FECHADO)->get()
            ->keyBy(fn (MesTempo $m) => $m->mes->format('Y-m'));
    }
}

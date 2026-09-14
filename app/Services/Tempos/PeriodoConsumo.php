<?php

namespace App\Services\Tempos;

use App\Models\ContratoHorasIncluidas;
use Carbon\CarbonImmutable;

// Um período de horas incluídas de um contrato, com o consumo apurado. Tudo em segundos e SEM
// arredondamento (é o consumo que o técnico e o gestor veem; o arredondamento é da faturação).
final readonly class PeriodoConsumo
{
    public function __construct(
        public ContratoHorasIncluidas $horasIncluidas,
        public CarbonImmutable $inicio,
        public CarbonImmutable $fim,
        public int $incluidasSeg,
        public int $transportadoSeg,      // sobra do período anterior (só se transita)
        public int $faturavelSeg,         // consumo: registos faturáveis do contrato no período
        public int $naoFaturavelSeg,
        public int $excedenteSeg,         // max(0, consumo − incluídas − transportado)
        public int $sobraSeg,             // passa ao período seguinte (0 se não transita)
    ) {}

    /** Horas ainda disponíveis no período (incluídas + transportadas − consumo), nunca negativo. */
    public function disponivelSeg(): int
    {
        return max(0, $this->incluidasSeg + $this->transportadoSeg - $this->faturavelSeg);
    }
}

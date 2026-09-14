<?php

namespace App\Services\Tempos;

use App\Enums\AmbitoTarifa;
use App\Models\RegistoTempo;
use App\Models\Tarifa;
use Carbon\CarbonInterface;

/**
 * Escolhe a tarifa de um registo. Ordem ESTRITA (regra 11): contrato → cliente → técnico →
 * global; a primeira que exista e esteja válida no dia do registo. Tarifa expirada ou ainda
 * não em vigor não conta. Se houver mais do que uma válida no mesmo âmbito (não devia — a
 * gestão de tarifas impede sobreposições), vence a que começou mais tarde.
 *
 * Guarda o resultado por âmbito e dia enquanto dura o pedido (registado como `scoped`), para
 * uma listagem de centenas de registos não fazer centenas de consultas.
 */
class ResolvedorTarifa
{
    /** @var array<string, Tarifa|null> */
    private array $memoria = [];

    public function paraRegisto(RegistoTempo $registo): ?Tarifa
    {
        return $this->resolver($registo->contrato_id, $registo->cliente_id, $registo->tecnico_id, $registo->dia());
    }

    public function resolver(?int $contratoId, ?int $clienteId, ?int $tecnicoId, CarbonInterface|string $dia): ?Tarifa
    {
        $data = $dia instanceof CarbonInterface ? $dia->toDateString() : $dia;

        $cadeia = [
            [AmbitoTarifa::Contrato, $contratoId],
            [AmbitoTarifa::Cliente, $clienteId],
            [AmbitoTarifa::Tecnico, $tecnicoId],
            [null, null],
        ];

        foreach ($cadeia as [$ambito, $id]) {
            if ($ambito !== null && $id === null) {
                continue;
            }

            if ($tarifa = $this->doAmbito($ambito, $id, $data)) {
                return $tarifa;
            }
        }

        return null;
    }

    public function esquecer(): void
    {
        $this->memoria = [];
    }

    private function doAmbito(?AmbitoTarifa $ambito, ?int $id, string $data): ?Tarifa
    {
        $chave = ($ambito?->value ?? 'global').':'.($id ?? '-').':'.$data;

        if (! array_key_exists($chave, $this->memoria)) {
            $this->memoria[$chave] = Tarifa::query()
                ->doAmbito($ambito, $id)
                ->validasEm($data)
                ->orderByDesc('valido_de')
                ->orderByDesc('id')
                ->first();
        }

        return $this->memoria[$chave];
    }
}

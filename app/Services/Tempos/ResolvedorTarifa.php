<?php

namespace App\Services\Tempos;

use App\Enums\AmbitoTarifa;
use App\Models\RegistoTempo;
use App\Models\Tarifa;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Escolhe a tarifa de um registo. Ordem ESTRITA (regra 11): contrato → cliente → técnico →
 * global; a primeira que exista e esteja válida no dia do registo. Tarifa expirada ou ainda
 * não em vigor não conta. Se houver mais do que uma válida no mesmo âmbito (não devia — a
 * gestão de tarifas impede sobreposições), vence a que começou mais tarde.
 *
 * - resolver(): tarifa de VENDA (preço/hora).
 * - resolverCusto(): a primeira da mesma cadeia que tenha CUSTO preenchido (o custo é opcional).
 *
 * As tarifas (tabela pequena) são lidas uma vez por pedido (registado como `scoped`) e resolvidas
 * em memória: o relatório de margem resolve dezenas de milhares de combinações sem consultas.
 */
class ResolvedorTarifa
{
    /** @var Collection<int, Tarifa>|null */
    private ?Collection $todas = null;

    /** @var array<string, Tarifa|null> */
    private array $memoria = [];

    public function paraRegisto(RegistoTempo $registo): ?Tarifa
    {
        return $this->resolver($registo->contrato_id, $registo->cliente_id, $registo->tecnico_id, $registo->dia());
    }

    public function resolver(?int $contratoId, ?int $clienteId, ?int $tecnicoId, CarbonInterface|string $dia): ?Tarifa
    {
        return $this->percorrer($contratoId, $clienteId, $tecnicoId, $dia, false);
    }

    public function resolverCusto(?int $contratoId, ?int $clienteId, ?int $tecnicoId, CarbonInterface|string $dia): ?Tarifa
    {
        return $this->percorrer($contratoId, $clienteId, $tecnicoId, $dia, true);
    }

    /** Volta a ler as tarifas (depois de uma tarifa ser criada, alterada ou apagada no mesmo pedido). */
    public function esquecer(): void
    {
        $this->todas = null;
        $this->memoria = [];
    }

    private function percorrer(?int $contratoId, ?int $clienteId, ?int $tecnicoId, CarbonInterface|string $dia, bool $comCusto): ?Tarifa
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

            if ($tarifa = $this->doAmbito($ambito, $id, $data, $comCusto)) {
                return $tarifa;
            }
        }

        return null;
    }

    private function doAmbito(?AmbitoTarifa $ambito, ?int $id, string $data, bool $comCusto): ?Tarifa
    {
        $chave = ($ambito?->value ?? 'global').':'.($id ?? '-').':'.$data.($comCusto ? ':custo' : '');

        if (! array_key_exists($chave, $this->memoria)) {
            $this->memoria[$chave] = $this->todas()->first(fn (Tarifa $t) => $t->ambito_tipo === $ambito
                && ($ambito === null || (int) $t->ambito_id === $id)
                && $t->valido_de->toDateString() <= $data
                && ($t->valido_ate === null || $t->valido_ate->toDateString() >= $data)
                && (! $comCusto || $t->custo_hora_cent !== null));
        }

        return $this->memoria[$chave];
    }

    /** @return Collection<int, Tarifa> mais recente primeiro (valido_de, depois id) */
    private function todas(): Collection
    {
        return $this->todas ??= Tarifa::query()->orderByDesc('valido_de')->orderByDesc('id')->get();
    }
}

<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

/**
 * Filtros com «Incluir / Excluir» (como o «Doesn't contain» do Clockify): `excluir` lista os filtros
 * em que o que está marcado sai, em vez de ser o único que fica. O interruptor está no menu do
 * `x-filtro-multiplo` (:inverso). O componente diz quais os filtros que se podem inverter.
 */
trait FiltrosInversos
{
    /** @var list<string> */
    #[Url(as: 'excluir')]
    public array $excluir = [];

    public function alternarExclusao(string $filtro): void
    {
        if (! in_array($filtro, $this->filtrosInversiveis(), true)) {
            return;
        }

        $this->excluir = in_array($filtro, $this->excluir, true)
            ? array_values(array_diff($this->excluir, [$filtro]))
            : [...$this->excluir, $filtro];

        $this->exclusoesMudaram();
    }

    /** @return list<string> */
    abstract protected function filtrosInversiveis(): array;

    /** Chamado depois de inverter um filtro (para recalcular, voltar à 1.ª página…). */
    protected function exclusoesMudaram(): void {}

    protected function excluido(string $filtro): bool
    {
        return in_array($filtro, $this->excluir, true);
    }

    protected function normalizarExclusoes(): void
    {
        $this->excluir = array_values(array_intersect($this->filtrosInversiveis(), array_map('strval', (array) $this->excluir)));
    }
}

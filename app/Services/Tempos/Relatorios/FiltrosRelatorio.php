<?php

namespace App\Services\Tempos\Relatorios;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\RegistoTempo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtros comuns aos relatórios: período, cliente, contrato, técnico, faturável e etiqueta. Lidos do
 * query string (a mesma forma nas páginas e nas exportações CSV/PDF), para um link partilhado dar
 * sempre o mesmo relatório.
 */
final readonly class FiltrosRelatorio
{
    public function __construct(
        public PeriodoRelatorio $periodo,
        public ?int $clienteId = null,
        public ?int $contratoId = null,
        public ?int $tecnicoId = null,
        public ?bool $faturavel = null,
        public ?string $etiqueta = null,
    ) {}

    /** @param array<string, mixed> $query */
    public static function doQuery(array $query): self
    {
        $inteiro = fn (string $chave) => isset($query[$chave]) && ctype_digit((string) $query[$chave]) ? (int) $query[$chave] : null;

        return new self(
            PeriodoRelatorio::criar($query['periodo'] ?? null, $query['ref'] ?? null, $query['de'] ?? null, $query['ate'] ?? null),
            $inteiro('cliente'),
            $inteiro('contrato'),
            $inteiro('tecnico'),
            match ($query['faturavel'] ?? '') {
                'sim' => true,
                'nao' => false,
                default => null,
            },
            filled($query['etiqueta'] ?? null) ? trim((string) $query['etiqueta']) : null,
        );
    }

    /** Técnico imposto (quem não vê os tempos de todos só vê os seus). */
    public function soDoTecnico(int $tecnicoId): self
    {
        return new self($this->periodo, $this->clienteId, $this->contratoId, $tecnicoId, $this->faturavel, $this->etiqueta);
    }

    public function comContrato(?int $contratoId): self
    {
        return new self($this->periodo, $this->clienteId, $contratoId, $this->tecnicoId, $this->faturavel, $this->etiqueta);
    }

    public function comCliente(?int $clienteId): self
    {
        return new self($this->periodo, $clienteId, $this->contratoId, $this->tecnicoId, $this->faturavel, $this->etiqueta);
    }

    /** Registos terminados que passam nos filtros. */
    public function aplicar(Builder $registos): Builder
    {
        return $registos
            ->terminados()
            ->noPeriodo($this->periodo->de, $this->periodo->ate)
            ->when($this->clienteId, fn (Builder $q) => $q->where('cliente_id', $this->clienteId))
            ->when($this->contratoId, fn (Builder $q) => $q->where('contrato_id', $this->contratoId))
            ->when($this->tecnicoId, fn (Builder $q) => $q->where('tecnico_id', $this->tecnicoId))
            ->when($this->faturavel !== null, fn (Builder $q) => $q->where('faturavel', $this->faturavel))
            ->when($this->etiqueta !== null, fn (Builder $q) => $q->whereRaw('? = any(etiquetas)', [$this->etiqueta]));
    }

    public function registos(): Builder
    {
        return $this->aplicar(RegistoTempo::query());
    }

    /** @return array<string, string|int> */
    public function paraQuery(): array
    {
        return array_filter($this->periodo->paraQuery() + [
            'cliente' => $this->clienteId,
            'contrato' => $this->contratoId,
            'tecnico' => $this->tecnicoId,
            'faturavel' => match ($this->faturavel) {
                true => 'sim',
                false => 'nao',
                null => null,
            },
            'etiqueta' => $this->etiqueta,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Filtros ativos em texto, para o cabeçalho dos PDF.
     *
     * @return list<string>
     */
    public function descricao(): array
    {
        return array_values(array_filter([
            'Período: '.$this->periodo->rotulo(),
            $this->clienteId ? 'Cliente: '.(Cliente::withTrashed()->find($this->clienteId)?->nome ?? '—') : null,
            $this->contratoId ? 'Contrato: '.(Contrato::withTrashed()->find($this->contratoId)?->numero ?? '—') : null,
            $this->tecnicoId ? 'Técnico: '.(User::find($this->tecnicoId)?->nome ?? '—') : null,
            $this->faturavel === null ? null : ($this->faturavel ? 'Só faturável' : 'Só não faturável'),
            $this->etiqueta ? 'Etiqueta: '.$this->etiqueta : null,
        ]));
    }
}

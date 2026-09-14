<?php

namespace App\Models;

use App\Enums\ModoArredondamento;
use App\Enums\PeriodoHorasIncluidas;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Horas incluídas num contrato por período, com validade (histórico quando as condições mudam).
// Só uma ativa por contrato numa dada data. Consumo, transporte e excedente: CalculadorHorasIncluidas.
class ContratoHorasIncluidas extends Model
{
    use SoftDeletes;

    protected $table = 'contrato_horas_incluidas';

    /** @var list<string> */
    protected $fillable = [
        'contrato_id',
        'periodo',
        'horas_incluidas',
        'transita',
        'excedente_faturavel',
        'tarifa_excedente_id',
        'arredondamento_min',
        'arredondamento_modo',
        'valido_de',
        'valido_ate',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'transita' => false,
        'excedente_faturavel' => true,
        'arredondamento_min' => 15,
        'arredondamento_modo' => 'cima',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'periodo' => PeriodoHorasIncluidas::class,
            'horas_incluidas' => 'decimal:2',
            'transita' => 'boolean',
            'excedente_faturavel' => 'boolean',
            'arredondamento_min' => 'integer',
            'arredondamento_modo' => ModoArredondamento::class,
            'valido_de' => 'immutable_date',
            'valido_ate' => 'immutable_date',
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function tarifaExcedente(): BelongsTo
    {
        return $this->belongsTo(Tarifa::class, 'tarifa_excedente_id');
    }

    public function incluidasSeg(): int
    {
        return (int) round((float) $this->horas_incluidas * 3600);
    }

    /** Arredondamento de faturação desta configuração aplicado a uma duração. */
    public function arredondar(int $segundos): int
    {
        return $this->arredondamento_modo->aplicar($segundos, $this->arredondamento_min);
    }

    public function scopeAtivasEm(Builder $query, CarbonInterface|string $dia): void
    {
        $data = $dia instanceof CarbonInterface ? $dia->toDateString() : $dia;

        $query->where('valido_de', '<=', $data)
            ->where(fn (Builder $q) => $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', $data));
    }

    /** Sobrepõem-se ao intervalo [$de, $ate] ($ate null = sem fim). */
    public function scopeSobrepostasA(Builder $query, CarbonInterface|string $de, CarbonInterface|string|null $ate): void
    {
        $dataDe = $de instanceof CarbonInterface ? $de->toDateString() : $de;
        $dataAte = $ate instanceof CarbonInterface ? $ate->toDateString() : $ate;

        $query->where(fn (Builder $q) => $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', $dataDe));
        if ($dataAte !== null) {
            $query->where('valido_de', '<=', $dataAte);
        }
    }
}

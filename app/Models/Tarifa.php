<?php

namespace App\Models;

use App\Enums\AmbitoTarifa;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Tarifa horária (cêntimos/hora): preço de venda e custo interno. Âmbito contrato, cliente ou
// técnico; sem âmbito = global. A escolha para um registo vive no ResolvedorTarifa.
class Tarifa extends Model
{
    use SoftDeletes;

    protected $table = 'tarifas';

    /** @var list<string> */
    protected $fillable = ['ambito_tipo', 'ambito_id', 'preco_hora_cent', 'custo_hora_cent', 'valido_de', 'valido_ate'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ambito_tipo' => AmbitoTarifa::class,
            'preco_hora_cent' => 'integer',
            'custo_hora_cent' => 'integer',
            'valido_de' => 'immutable_date',
            'valido_ate' => 'immutable_date',
        ];
    }

    /** Válidas num dia: começaram até esse dia e não acabaram antes dele. */
    public function scopeValidasEm(Builder $query, CarbonInterface|string $dia): void
    {
        $data = $dia instanceof CarbonInterface ? $dia->toDateString() : $dia;

        $query->where('valido_de', '<=', $data)
            ->where(fn (Builder $q) => $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', $data));
    }

    public function scopeDoAmbito(Builder $query, ?AmbitoTarifa $ambito, ?int $id = null): void
    {
        $ambito === null
            ? $query->whereNull('ambito_tipo')->whereNull('ambito_id')
            : $query->where('ambito_tipo', $ambito->value)->where('ambito_id', $id);
    }
}

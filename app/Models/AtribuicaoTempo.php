<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Atribuição de uma pessoa a um projeto (relatório Atribuições). Escrever através de
// Services\Tempos\GestorAtribuicoes.
class AtribuicaoTempo extends Model
{
    protected $table = 'atribuicoes_tempos';

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'de' => 'immutable_date',
            'ate' => 'immutable_date',
            'horas_dia_seg' => 'integer',
            'fins_de_semana' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilizador_id');
    }

    /** @return BelongsTo<ProjetoTempo, $this> */
    public function projeto(): BelongsTo
    {
        return $this->belongsTo(ProjetoTempo::class, 'projeto_id')->withTrashed();
    }

    /** Atribuições que tocam o período. */
    public function scopeNoPeriodo(Builder $query, CarbonImmutable $de, CarbonImmutable $ate): void
    {
        $query->where('de', '<=', $ate->toDateString())->where('ate', '>=', $de->toDateString());
    }

    /** Segundos agendados dentro do período (dias úteis, ou todos se incluir fins de semana). */
    public function segundosEntre(CarbonImmutable $de, CarbonImmutable $ate): int
    {
        return $this->diasEntre($de, $ate) * $this->horas_dia_seg;
    }

    public function diasEntre(CarbonImmutable $de, CarbonImmutable $ate): int
    {
        $inicio = $this->de->max($de->startOfDay());
        $fim = $this->ate->min($ate->startOfDay());
        $dias = 0;
        for ($d = $inicio; $d->lte($fim); $d = $d->addDay()) {
            if ($this->fins_de_semana || ! $d->isWeekend()) {
                $dias++;
            }
        }

        return $dias;
    }
}

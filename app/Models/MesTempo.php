<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Estado de um mês para a faturação (aberto / fechado). Sem linha = aberto.
// Perguntas "este dia está num mês fechado?": Services\Tempos\Faturacao\MesesFechados.
class MesTempo extends Model
{
    protected $table = 'meses_tempo';

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const ABERTO = 'aberto';

    public const FECHADO = 'fechado';

    /** @var list<string> */
    protected $fillable = ['mes', 'estado', 'fechado_em', 'fechado_por', 'reaberto_em', 'reaberto_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mes' => 'immutable_date',
            'fechado_em' => 'immutable_datetime',
            'reaberto_em' => 'immutable_datetime',
        ];
    }

    public function fechadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechado_por');
    }

    public function reabertoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reaberto_por');
    }

    public function estaFechado(): bool
    {
        return $this->estado === self::FECHADO;
    }

    /** Dia 1 do mês de um dia. */
    public static function inicioDoMes(CarbonInterface|string $dia): CarbonImmutable
    {
        return CarbonImmutable::parse($dia instanceof CarbonInterface ? $dia->toDateString() : $dia)->startOfMonth();
    }

    /** "agosto de 2026". */
    public static function rotulo(CarbonInterface $mes): string
    {
        return $mes->translatedFormat('F').' de '.$mes->year;
    }
}

<?php

namespace App\Models;

use App\Enums\EstadoSemanaTempo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Estado da semana de um técnico na timesheet. Sem linha = rascunho.
class SemanaTempo extends Model
{
    protected $table = 'semanas_tempo';

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = ['tecnico_id', 'semana_inicio', 'estado', 'submetida_em', 'reaberta_por'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado' => 'rascunho',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'semana_inicio' => 'immutable_date',
            'estado' => EstadoSemanaTempo::class,
            'submetida_em' => 'immutable_datetime',
        ];
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }

    public function reabertaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reaberta_por');
    }

    /** Segunda-feira da semana de um dia (data local). */
    public static function segundaDe(CarbonInterface|string $dia): CarbonImmutable
    {
        return CarbonImmutable::parse($dia instanceof CarbonInterface ? $dia->toDateString() : $dia)
            ->startOfWeek(CarbonInterface::MONDAY);
    }

    /** Estado da semana de um técnico que contém o dia. Sem linha = rascunho. */
    public static function estadoDe(int $tecnicoId, CarbonInterface|string $dia): EstadoSemanaTempo
    {
        return static::query()
            ->where('tecnico_id', $tecnicoId)
            ->where('semana_inicio', self::segundaDe($dia)->toDateString())
            ->value('estado') ?? EstadoSemanaTempo::Rascunho;
    }
}

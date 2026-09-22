<?php

namespace App\Models;

use App\Casts\ListaTextoPostgres;
use App\Enums\OrigemRegistoTempo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Registo de tempo de um técnico. Duração em segundos (fonte de verdade para relatórios), nunca
// arredondada. O dia a que pertence é a data local (config tempos.fuso) de `inicio`.
// Escrever SEMPRE através de Services\Tempos\GravadorRegistos (autorização + regras de negócio).
class RegistoTempo extends Model
{
    use SoftDeletes;

    protected $table = 'registos_tempo';

    // Colunas timestamptz: com a sessão da BD em UTC, grava-se o instante com o desvio explícito.
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'tecnico_id',
        'cliente_id',
        'contrato_id',
        'projeto_id',
        'intervencao_id',
        'inicio',
        'fim',
        'duracao_seg',
        'faturavel',
        'descricao',
        'etiquetas',
        'origem',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'faturavel' => true,
        'origem' => 'timesheet',
        'etiquetas' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inicio' => 'immutable_datetime',
            'fim' => 'immutable_datetime',
            'duracao_seg' => 'integer',
            'faturavel' => 'boolean',
            'etiquetas' => ListaTextoPostgres::class,
            'origem' => OrigemRegistoTempo::class,
            'submetido_em' => 'immutable_datetime',
            'fechado_em' => 'immutable_datetime',
            'faturado_em' => 'immutable_datetime',
        ];
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    /** @return BelongsTo<ProjetoTempo, $this> */
    public function projeto(): BelongsTo
    {
        return $this->belongsTo(ProjetoTempo::class, 'projeto_id')->withTrashed();
    }

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function alteradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alterado_por');
    }

    // --- Datas no fuso da empresa ---

    /** Instante (UTC) da meia-noite local de um dia — o `inicio` de um registo da timesheet. */
    public static function inicioDoDia(CarbonInterface|string $dia): CarbonImmutable
    {
        $local = $dia instanceof CarbonInterface ? $dia->toDateString() : $dia;

        return CarbonImmutable::parse($local, config('tempos.fuso'))->startOfDay()->utc();
    }

    /** Dia (data local) a que o registo pertence. */
    public function dia(): CarbonImmutable
    {
        return $this->inicio->setTimezone(config('tempos.fuso'))->startOfDay();
    }

    /** Segunda-feira (data local) da semana do registo. */
    public function semanaInicio(): CarbonImmutable
    {
        return $this->dia()->startOfWeek(CarbonInterface::MONDAY);
    }

    public function cronometroACorrer(): bool
    {
        return $this->fim === null;
    }

    // --- Scopes ---

    public function scopeDoTecnico(Builder $query, User|int $tecnico): void
    {
        $query->where('tecnico_id', $tecnico instanceof User ? $tecnico->id : $tecnico);
    }

    public function scopeDoContrato(Builder $query, Contrato|int $contrato): void
    {
        $query->where('contrato_id', $contrato instanceof Contrato ? $contrato->id : $contrato);
    }

    /** Registos cujo dia local está entre $de e $ate (inclusive). Usa o índice sobre `inicio`. */
    public function scopeNoPeriodo(Builder $query, CarbonInterface|string $de, CarbonInterface|string $ate): void
    {
        $dataAte = $ate instanceof CarbonInterface ? $ate->toDateString() : $ate;

        $query->where('inicio', '>=', self::inicioDoDia($de))
            ->where('inicio', '<', self::inicioDoDia(CarbonImmutable::parse($dataAte)->addDay()));
    }

    public function scopeFaturaveis(Builder $query): void
    {
        $query->where('faturavel', true);
    }

    /** Ainda não fechados no fecho mensal. */
    public function scopePorFechar(Builder $query): void
    {
        $query->whereNull('fechado_em');
    }

    /** Com duração conhecida (exclui cronómetros a correr). */
    public function scopeTerminados(Builder $query): void
    {
        $query->whereNotNull('duracao_seg');
    }
}

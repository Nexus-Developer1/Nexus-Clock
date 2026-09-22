<?php

namespace App\Models;

use App\Casts\ListaInteirosPostgres;
use App\Enums\PapelEquipa;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Membro da equipa: pleno (pessoa da suite com acesso aos Tempos) ou limitado (sem conta, criado aqui).
// Escrever através de Services\Tempos\GestorEquipa.
class MembroEquipa extends Model
{
    use SoftDeletes;

    protected $table = 'membros_equipa';

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = ['utilizador_id', 'limitado', 'nome', 'email', 'papel'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'papel' => 'membro',
        'limitado' => false,
        'inicio_semana' => 1,
        'dias_trabalho' => '{1,2,3,4,5}',
    ];

    public const DIAS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'limitado' => 'boolean',
            'papel' => PapelEquipa::class,
            'inicio_semana' => 'integer',
            'dias_trabalho' => ListaInteirosPostgres::class,
            'capacidade_diaria_seg' => 'integer',
        ];
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilizador_id');
    }

    /** Gestor de equipa atribuído (outro membro, com o papel de gestor de equipa). */
    public function gestor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'gestor_id');
    }

    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(GrupoEquipa::class, 'grupo_membro', 'membro_id', 'grupo_id')->orderByRaw('lower(nome)');
    }

    public function taxas(): HasMany
    {
        return $this->hasMany(TaxaMembro::class, 'membro_id');
    }

    public function nomeVisivel(): string
    {
        return $this->limitado ? (string) $this->nome : ($this->utilizador?->nome ?? 'Conta removida');
    }

    public function emailVisivel(): ?string
    {
        return $this->limitado ? $this->email : $this->utilizador?->email;
    }

    /** "Seg–Sex", "Seg, Qua, Sex" ou "Todos os dias". */
    public function rotuloDiasTrabalho(): string
    {
        $d = $this->dias_trabalho;
        if ($d === []) {
            return 'Nenhum';
        }
        if (count($d) === 7) {
            return 'Todos os dias';
        }
        $curto = fn (int $n) => mb_substr(self::DIAS[$n], 0, 3);
        if (count($d) > 2 && $d === range($d[0], end($d))) {
            return $curto($d[0]).'–'.$curto(end($d));
        }

        return implode(', ', array_map($curto, $d));
    }

    /** Taxa em vigor num dia (cêntimos, ou null sem taxa). */
    public function taxaEm(string $tipo, CarbonInterface $dia): ?int
    {
        return $this->taxas
            ->where('tipo', $tipo)
            ->filter(fn (TaxaMembro $t) => $t->valido_de->toDateString() <= $dia->toDateString())
            ->sortByDesc(fn (TaxaMembro $t) => $t->valido_de->toDateString())
            ->first()?->valor_cent;
    }

    public function scopePlenos(Builder $query): void
    {
        $query->where('limitado', false);
    }

    public function scopeLimitados(Builder $query): void
    {
        $query->where('limitado', true);
    }

    /** Plenos cuja pessoa ainda tem acesso aos Tempos (o acesso é dado e tirado no portal). */
    public function scopeComAcesso(Builder $query): void
    {
        $query->plenos()->whereIn('utilizador_id', User::comAcessoAosTempos()->select('utilizadores.id'));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Projeto dos Tempos (página Projetos). Escrever através de Services\Tempos\GestorProjetos.
class ProjetoTempo extends Model
{
    use SoftDeletes;

    protected $table = 'projetos_tempos';

    protected $dateFormat = 'Y-m-d H:i:sP';

    // Cores à escolha: as cinco primeiras são as do Painel.
    public const CORES = ['#16a34a', '#2a78d6', '#eb6834', '#7c3aed', '#eda100', '#db2777', '#0891b2', '#65a30d', '#dc2626', '#64748b'];

    /** @var list<string> */
    protected $fillable = ['nome', 'cliente_id', 'cor', 'publico', 'faturavel', 'taxa_cent', 'estimativa_seg', 'nota'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cor' => '#16a34a',
        'publico' => true,
        'faturavel' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'publico' => 'boolean',
            'faturavel' => 'boolean',
            'taxa_cent' => 'integer',
            'estimativa_seg' => 'integer',
            'arquivado_em' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClienteTempo, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(ClienteTempo::class, 'cliente_id')->withTrashed();
    }

    /** @return BelongsToMany<MembroEquipa, $this> */
    public function membros(): BelongsToMany
    {
        return $this->belongsToMany(MembroEquipa::class, 'projeto_membro', 'projeto_id', 'membro_id');
    }

    public function estaArquivado(): bool
    {
        return $this->arquivado_em !== null;
    }

    public function scopeAtivos(Builder $query): void
    {
        $query->whereNull('arquivado_em');
    }

    public function scopeArquivados(Builder $query): void
    {
        $query->whereNotNull('arquivado_em');
    }

    /** Projetos que a pessoa pode ver: públicos e, se privados, só administradores e membros. */
    public function scopeVisiveisPara(Builder $query, User $utilizador): void
    {
        if ($utilizador->ehAdminTempos()) {
            return;
        }

        $query->where(fn ($q) => $q->where('publico', true)->orWhereExists(fn ($e) => $e->selectRaw('1')
            ->from('projeto_membro')
            ->join('membros_equipa', 'membros_equipa.id', '=', 'projeto_membro.membro_id')
            ->whereColumn('projeto_membro.projeto_id', 'projetos_tempos.id')
            ->where('membros_equipa.utilizador_id', $utilizador->id)));
    }
}

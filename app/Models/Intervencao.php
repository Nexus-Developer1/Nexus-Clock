<?php

namespace App\Models;

use App\Models\Concerns\TabelaDaNexusInfra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Intervenção — tabela da Nexus Infra. Só leitura aqui. Não tem cliente próprio: o cliente é o
// do local do equipamento (equipamento → local → cliente), e pode não existir.
class Intervencao extends Model
{
    use SoftDeletes, TabelaDaNexusInfra;

    protected $table = 'intervencoes';

    // Estado em que a Nexus Infra dá a intervenção por terminada.
    public const ESTADO_CONCLUIDA = 'concluida';

    /** @var list<string> */
    protected $fillable = ['equipamento_id', 'tecnico_id', 'contrato_id', 'tipo', 'estado', 'data_inicio', 'data_fim', 'descricao_problema'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data_inicio' => 'datetime',
            'data_fim' => 'datetime',
        ];
    }

    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    // Cliente da intervenção, pelo local do equipamento. Null se o equipamento não tiver local.
    public function clienteId(): ?int
    {
        return $this->equipamento?->local?->cliente_id;
    }

    public function estaConcluida(): bool
    {
        return $this->estado === self::ESTADO_CONCLUIDA;
    }
}

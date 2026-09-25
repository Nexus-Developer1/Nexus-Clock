<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Despesa dos Tempos (relatório Despesas). Não confundir com as despesas da Nexus Infra.
// Escrever através de Services\Tempos\GestorDespesas.
class DespesaTempo extends Model
{
    use SoftDeletes;

    protected $table = 'despesas_tempos';

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const ESTADOS = ['pendente' => 'Pendente', 'aprovada' => 'Aprovada', 'rejeitada' => 'Rejeitada'];

    /** Disco e pasta dos recibos (privados; só se descarregam pela aplicação). */
    public const DISCO = 'local';

    public const PASTA_RECIBOS = 'recibos-despesas';

    /** @var array<string, mixed> */
    protected $attributes = [
        'faturavel' => false,
        'estado' => 'pendente',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'immutable_date',
            'valor_cent' => 'integer',
            'faturavel' => 'boolean',
            'decidido_em' => 'immutable_datetime',
        ];
    }

    /**
     * Referência à vista: «SUP-12». Os números das despesas do IFE e do Suporte repetem-se (tabelas
     * diferentes); com o prefixo, quem aprova as duas não confunde a nº 12 de uma com a da outra.
     */
    public function referencia(): string
    {
        return 'SUP-'.$this->id;
    }

    /**
     * Impressão digital do que se aprova: muda se mudar o valor, a data, o recibo, o projeto, a
     * categoria, a nota ou o estado. O botão «Aprovar» leva a que estava no ecrã; se a despesa mudou
     * entretanto, não se aprova às cegas (notas §52).
     */
    public function versao(): string
    {
        return substr(hash('sha256', (string) json_encode([
            $this->utilizador_id, $this->data?->toDateString(), (int) $this->valor_cent, (bool) $this->faturavel,
            $this->projeto_id, $this->categoria_id, (string) $this->nota, $this->recibo_caminho, $this->estado,
        ])), 0, 16);
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

    /** @return BelongsTo<CategoriaDespesaTempo, $this> */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaDespesaTempo::class, 'categoria_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }
}

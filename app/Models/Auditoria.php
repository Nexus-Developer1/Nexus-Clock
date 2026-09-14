<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Registo de auditoria — tabela da Nexus Infra, APPEND-ONLY: aqui só se insere (via
// Auditor::registar). Sem updated_at de propósito; nunca criar caminhos de update/delete.
class Auditoria extends Model
{
    protected $table = 'auditoria';

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['user_id', 'email', 'acao', 'entidade_tipo', 'entidade_id', 'detalhe', 'ip'];

    public function getConnectionName(): ?string
    {
        return config('database.suite');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'detalhe' => 'array',
            'criado_em' => 'datetime',
        ];
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

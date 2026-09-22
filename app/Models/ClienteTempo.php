<?php

namespace App\Models;

use App\Casts\ListaTextoPostgres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Cliente da lista própria dos Tempos (página Clientes). Não confundir com App\Models\Cliente, que
// é o cliente da Nexus Infra (só leitura). Escrever através de Services\Tempos\GestorClientes.
class ClienteTempo extends Model
{
    use SoftDeletes;

    protected $table = 'clientes_tempos';

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const MOEDAS = ['EUR', 'USD', 'GBP', 'CHF', 'BRL', 'AOA'];

    public const MAXIMO_CC = 3;

    /** @var list<string> */
    protected $fillable = ['nome', 'email', 'emails_cc', 'morada', 'nota', 'moeda'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'moeda' => 'EUR',
        'emails_cc' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'emails_cc' => ListaTextoPostgres::class,
            'arquivado_em' => 'immutable_datetime',
        ];
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
}

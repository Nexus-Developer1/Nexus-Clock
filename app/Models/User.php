<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * As pessoas vêm do portal (tabela `utilizadores`, partilhada por toda a suite e pertencente
 * à Nexus Infra). Esta aplicação não as cria nem lhes mexe: só as lê.
 *
 * O que cada pessoa pode fazer AQUI — administrador ou técnico — vem da linha de acesso a esta
 * aplicação no portal (`acessos.papel`). Um só sítio decide: o portal.
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'utilizadores';

    public const PAPEIS = [
        'admin' => 'Administrador',
        'tecnico' => 'Técnico',
    ];

    /** A tabela vive na base da suite (ver config('database.suite')). */
    public function getConnectionName(): ?string
    {
        return config('database.suite');
    }

    /** @var list<string> */
    protected $fillable = ['nome', 'email', 'password', 'papel', 'ativo'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ativo' => 'boolean',
            'password_alterada_em' => 'datetime',
        ];
    }

    /**
     * A linha de acesso desta pessoa a esta aplicação, lida uma só vez por pedido. Null para quem
     * o portal não deu acesso — o middleware trava-a antes, mas o valor por omissão tem de ser o
     * mais restrito à mesma.
     */
    public function acessoAEstaAplicacao(): ?object
    {
        if (! $this->acessoLido) {
            $this->acessoLido = true;
            $this->acessoEmMemoria = DB::connection(config('database.suite'))
                ->table('acessos')
                ->join('aplicacoes', 'aplicacoes.id', '=', 'acessos.aplicacao_id')
                ->where('aplicacoes.chave', config('app.chave'))
                ->where('aplicacoes.activa', true)
                ->where('acessos.utilizador_id', $this->id)
                ->select('acessos.papel', 'acessos.contexto')
                ->first();
        }

        return $this->acessoEmMemoria;
    }

    private bool $acessoLido = false;

    private ?object $acessoEmMemoria = null;

    /** Esquece o acesso lido (útil quando o acesso muda a meio do mesmo pedido, ex.: testes). */
    public function esquecerAcesso(): void
    {
        $this->acessoLido = false;
        $this->acessoEmMemoria = null;
    }

    public function temAcesso(): bool
    {
        return $this->acessoAEstaAplicacao() !== null;
    }

    /**
     * Papel nesta aplicação. Um valor que não reconheçamos vale sempre técnico, nunca mais do
     * que isso: lixo na base de dados não pode dar poderes a ninguém.
     */
    public function papelTempos(): string
    {
        $papel = $this->acessoAEstaAplicacao()?->papel;

        return isset(self::PAPEIS[$papel]) ? $papel : 'tecnico';
    }

    public function ehAdminTempos(): bool
    {
        return $this->papelTempos() === 'admin';
    }

    /** Permissão explícita para reabrir meses e mexer em registos fechados (config tempos.pode_reabrir). */
    public function podeReabrirTempos(): bool
    {
        return $this->ehAdminTempos()
            && in_array(strtolower((string) $this->email), config('tempos.pode_reabrir'), true);
    }
}

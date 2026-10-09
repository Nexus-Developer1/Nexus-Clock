<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    // Os mesmos do formulário de utilizadores do portal (notas §76 e §77). 'admin' só visualiza o
    // rendimento da equipa (vê tudo, não altera nada); 'admin_tecnico' gere e regista horas; 'tecnico'
    // regista horas.
    public const PAPEIS = [
        'admin' => 'Administrador',
        'admin_tecnico' => 'Administrador e técnico',
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

    /**
     * Pessoas ativas com acesso a esta aplicação no portal (opcionalmente só com um papel).
     */
    public function scopeComAcessoAosTempos(Builder $query, ?string $papel = null): void
    {
        $query->where('ativo', true)->whereExists(function ($q) use ($papel) {
            $q->from('acessos')
                ->join('aplicacoes', 'aplicacoes.id', '=', 'acessos.aplicacao_id')
                ->whereColumn('acessos.utilizador_id', 'utilizadores.id')
                ->where('aplicacoes.chave', config('app.chave'))
                ->where('aplicacoes.activa', true)
                ->when($papel !== null, fn ($q) => $q->where('acessos.papel', $papel));
        });
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

    /** Vê como administrador: as horas e os valores de toda a equipa (Administrador e Administrador e técnico). */
    public function ehAdminTempos(): bool
    {
        return in_array($this->papelTempos(), ['admin', 'admin_tecnico'], true);
    }

    /** Gere o Suporte (altera horas dos outros, equipa, projetos, clientes, tarifas, despesas, fecho): só Administrador e técnico. */
    public function podeGerirTempos(): bool
    {
        return $this->papelTempos() === 'admin_tecnico';
    }

    /**
     * Administrador só para visualizar o rendimento da equipa (notas §77): vê tudo o que um
     * administrador vê, mas não altera nada — nem regista horas nem lança despesas.
     */
    public function soVisualiza(): bool
    {
        return $this->papelTempos() === 'admin';
    }

    /**
     * Regista horas (Técnico, ou Administrador e técnico). Quem é só Administrador gere tudo, mas não
     * conta como alguém da equipa que regista horas: fica fora das presenças, dos lembretes, da
     * atividade da equipa, das atribuições, do filtro Equipa e da escolha de pessoa (notas §76).
     */
    public function registaHoras(): bool
    {
        return $this->papelTempos() !== 'admin';
    }

    /** Pessoas com acesso que registam horas: todas menos as que são só Administrador. */
    public function scopeQueRegistamHoras(Builder $query): void
    {
        $query->comAcessoAosTempos()->whereNotExists(function ($q) {
            $q->from('acessos')
                ->join('aplicacoes', 'aplicacoes.id', '=', 'acessos.aplicacao_id')
                ->whereColumn('acessos.utilizador_id', 'utilizadores.id')
                ->where('aplicacoes.chave', config('app.chave'))
                ->where('acessos.papel', 'admin');
        });
    }

    /** Permissão explícita para reabrir meses e mexer em registos fechados (config tempos.pode_reabrir). */
    public function podeReabrirTempos(): bool
    {
        return $this->podeGerirTempos()
            && in_array(strtolower((string) $this->email), config('tempos.pode_reabrir'), true);
    }
}

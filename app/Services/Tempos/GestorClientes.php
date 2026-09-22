<?php

namespace App\Services\Tempos;

use App\Models\ClienteTempo;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Clientes dos Tempos: criar, alterar, arquivar, restaurar e apagar. Só quem gere os clientes
 * (`tempos-gerir-clientes`). Nome obrigatório e único (sem distinguir maiúsculas), email válido, até 3
 * emails em cópia, moeda da lista. Só se apaga um cliente arquivado. Tudo fica na auditoria.
 */
class GestorClientes
{
    public function criar(User $autor, string $nome): ClienteTempo
    {
        Gate::forUser($autor)->authorize('tempos-gerir-clientes');

        $cliente = new ClienteTempo;
        $this->preencher($cliente, ['nome' => $nome]);
        $cliente->criado_por = $autor->id;
        $cliente->alterado_por = $autor->id;
        $cliente->save();

        Auditor::registar('tempo_cliente_criado', $cliente, ['nome' => $cliente->nome]);

        return $cliente;
    }

    /**
     * @param  array{nome?: string, email?: string|null, emails_cc?: list<string>|string|null, morada?: string|null, nota?: string|null, moeda?: string}  $dados
     */
    public function atualizar(User $autor, ClienteTempo $cliente, array $dados): ClienteTempo
    {
        Gate::forUser($autor)->authorize('tempos-gerir-clientes');

        $this->preencher($cliente, $dados);
        $alteracoes = array_keys($cliente->getDirty());
        $cliente->alterado_por = $autor->id;
        $cliente->save();

        if ($alteracoes !== []) {
            Auditor::registar('tempo_cliente_alterado', $cliente, ['nome' => $cliente->nome, 'campos' => $alteracoes]);
        }

        return $cliente;
    }

    /** @param list<int> $ids */
    public function arquivar(User $autor, array $ids): int
    {
        return $this->emMassa($autor, $ids, 'arquivar', function (ClienteTempo $c) use ($autor) {
            if (! $c->estaArquivado()) {
                $c->forceFill(['arquivado_em' => now(), 'alterado_por' => $autor->id])->save();
            }
        });
    }

    /** @param list<int> $ids */
    public function restaurar(User $autor, array $ids): int
    {
        return $this->emMassa($autor, $ids, 'restaurar', function (ClienteTempo $c) use ($autor) {
            // O nome é único também entre os arquivados, por isso restaurar nunca colide com outro ativo.
            $c->forceFill(['arquivado_em' => null, 'alterado_por' => $autor->id])->save();
        });
    }

    /** @param list<int> $ids */
    public function apagar(User $autor, array $ids): int
    {
        return $this->emMassa($autor, $ids, 'apagar', function (ClienteTempo $c) use ($autor) {
            if (! $c->estaArquivado()) {
                throw ValidationException::withMessages(['clientes' => 'Só se apaga um cliente arquivado: arquive primeiro «'.$c->nome.'». Nada foi apagado.']);
            }
            $c->forceFill(['alterado_por' => $autor->id])->save();
            $c->delete();
        });
    }

    /** @param array<string, mixed> $dados */
    private function preencher(ClienteTempo $cliente, array $dados): void
    {
        $erros = [];

        if (array_key_exists('nome', $dados)) {
            $nome = trim(preg_replace('/\s+/u', ' ', (string) $dados['nome']));
            if ($nome === '') {
                $erros['nome'] = 'Indique o nome do cliente.';
            } elseif (mb_strlen($nome) > 255) {
                $erros['nome'] = 'O nome não pode passar de 255 caracteres.';
            } elseif (ClienteTempo::whereRaw('lower(nome) = lower(?)', [$nome])->when($cliente->exists, fn ($q) => $q->whereKeyNot($cliente->id))->exists()) {
                $erros['nome'] = 'Já existe um cliente chamado «'.$nome.'».';
            }
            $cliente->nome = $nome;
        }

        if (array_key_exists('email', $dados)) {
            $email = trim((string) $dados['email']);
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erros['email'] = 'Email inválido.';
            }
            $cliente->email = $email ?: null;
        }

        if (array_key_exists('emails_cc', $dados)) {
            $cc = is_array($dados['emails_cc']) ? $dados['emails_cc'] : preg_split('/[\s,;]+/', (string) $dados['emails_cc']);
            $cc = array_values(array_unique(array_filter(array_map('trim', $cc))));
            if (count($cc) > ClienteTempo::MAXIMO_CC) {
                $erros['emails_cc'] = 'No máximo '.ClienteTempo::MAXIMO_CC.' emails em cópia.';
            } elseif ($invalidos = array_filter($cc, fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
                $erros['emails_cc'] = 'Email inválido: '.implode(', ', $invalidos).'.';
            }
            $cliente->emails_cc = $cc;
        }

        foreach (['morada', 'nota'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $cliente->{$campo} = trim((string) $dados[$campo]) ?: null;
            }
        }

        if (array_key_exists('moeda', $dados)) {
            if (! in_array($dados['moeda'], ClienteTempo::MOEDAS, true)) {
                $erros['moeda'] = 'Escolha uma moeda da lista.';
            }
            $cliente->moeda = (string) $dados['moeda'];
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }

    /**
     * Aplica uma ação a vários clientes, tudo ou nada.
     *
     * @param  list<int>  $ids
     */
    private function emMassa(User $autor, array $ids, string $acao, callable $aplicar): int
    {
        Gate::forUser($autor)->authorize('tempos-gerir-clientes');

        $clientes = ClienteTempo::whereKey(array_map('intval', $ids))->orderBy('nome')->get();
        if ($clientes->isEmpty()) {
            throw ValidationException::withMessages(['clientes' => 'Selecione pelo menos um cliente.']);
        }

        DB::transaction(fn () => $clientes->each($aplicar));

        foreach ($clientes as $c) {
            Auditor::registar('tempo_cliente_'.match ($acao) {
                'arquivar' => 'arquivado',
                'restaurar' => 'restaurado',
                default => 'apagado',
            }, $c, ['nome' => $c->nome]);
        }

        return $clientes->count();
    }
}

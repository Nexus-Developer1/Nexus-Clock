<?php

namespace App\Services\Tempos;

use App\Models\ClienteTempo;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Auditor;
use App\Support\Dinheiro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Projetos dos Tempos: criar, alterar, arquivar, restaurar e apagar (só quem gere os projetos,
 * `tempos-gerir-projetos`), e favoritos (cada pessoa, nos projetos que vê). Nome obrigatório e único
 * dentro do mesmo cliente; cliente ativo da página Clientes; cor da lista; taxa ≥ 0; estimativa > 0.
 * Só se apaga um projeto arquivado. Tudo (menos favoritos) fica na auditoria.
 */
class GestorProjetos
{
    /** @param array<string, mixed> $dados */
    public function criar(User $autor, array $dados): ProjetoTempo
    {
        Gate::forUser($autor)->authorize('tempos-gerir-projetos');

        $projeto = new ProjetoTempo;
        $membros = $this->preencher($projeto, $dados + ['nome' => '']);
        $projeto->criado_por = $autor->id;
        $projeto->alterado_por = $autor->id;

        DB::transaction(function () use ($projeto, $membros) {
            $projeto->save();
            if ($membros !== null) {
                $projeto->membros()->sync($membros);
            }
        });

        Auditor::registar('tempo_projeto_criado', $projeto, ['nome' => $projeto->nome]);

        return $projeto;
    }

    /**
     * @param  array{nome?: string, cliente_id?: int|string|null, cor?: string, publico?: bool, membros?: list<int|string>, faturavel?: bool, taxa?: string|null, estimativa?: string|null, nota?: string|null}  $dados
     */
    public function atualizar(User $autor, ProjetoTempo $projeto, array $dados): ProjetoTempo
    {
        Gate::forUser($autor)->authorize('tempos-gerir-projetos');

        $membros = $this->preencher($projeto, $dados);
        $alteracoes = array_keys($projeto->getDirty());
        $projeto->alterado_por = $autor->id;

        DB::transaction(function () use ($projeto, $membros, &$alteracoes) {
            $projeto->save();
            if ($membros !== null && $projeto->membros()->sync($membros) !== ['attached' => [], 'detached' => [], 'updated' => []]) {
                $alteracoes[] = 'membros';
            }
        });

        if ($alteracoes !== []) {
            Auditor::registar('tempo_projeto_alterado', $projeto, ['nome' => $projeto->nome, 'campos' => $alteracoes]);
        }

        return $projeto;
    }

    /** @param list<int> $ids */
    public function arquivar(User $autor, array $ids): int
    {
        return $this->emMassa($autor, $ids, 'arquivado', function (ProjetoTempo $p) use ($autor) {
            if (! $p->estaArquivado()) {
                $p->forceFill(['arquivado_em' => now(), 'alterado_por' => $autor->id])->save();
            }
        });
    }

    /** @param list<int> $ids */
    public function restaurar(User $autor, array $ids): int
    {
        return $this->emMassa($autor, $ids, 'restaurado', function (ProjetoTempo $p) use ($autor) {
            $p->forceFill(['arquivado_em' => null, 'alterado_por' => $autor->id])->save();
        });
    }

    /** @param list<int> $ids */
    public function apagar(User $autor, array $ids): int
    {
        return $this->emMassa($autor, $ids, 'apagado', function (ProjetoTempo $p) use ($autor) {
            if (! $p->estaArquivado()) {
                throw ValidationException::withMessages(['projetos' => 'Só se apaga um projeto arquivado: arquive primeiro «'.$p->nome.'». Nada foi apagado.']);
            }
            $p->forceFill(['alterado_por' => $autor->id])->save();
            $p->delete();
        });
    }

    /** Marca ou desmarca o projeto como favorito de quem está a ver. Devolve o novo estado. */
    public function alternarFavorito(User $autor, ProjetoTempo $projeto): bool
    {
        if (! ProjetoTempo::visiveisPara($autor)->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages(['projetos' => 'Projeto não encontrado.']);
        }

        $favoritos = DB::table('projeto_favoritos')->where(['projeto_id' => $projeto->id, 'utilizador_id' => $autor->id]);
        if ($favoritos->exists()) {
            $favoritos->delete();

            return false;
        }

        DB::table('projeto_favoritos')->insert(['projeto_id' => $projeto->id, 'utilizador_id' => $autor->id]);

        return true;
    }

    /** Lê "120", "7,5" ou "7:30" como horas. Vazio = null. */
    public static function lerHoras(?string $texto): ?int
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return null;
        }
        if (preg_match('/^(\d{1,5}):([0-5]\d)$/', $texto, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60;
        }
        if (preg_match('/^\d{1,5}([.,]\d{1,2})?$/', $texto)) {
            return (int) round((float) str_replace(',', '.', $texto) * 3600);
        }

        throw ValidationException::withMessages(['estimativa' => 'Não percebi a estimativa «'.$texto.'». Escreva as horas, por exemplo 120 ou 7:30.']);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return list<int>|null ids dos membros a sincronizar (null = não mexer)
     */
    private function preencher(ProjetoTempo $projeto, array $dados): ?array
    {
        $erros = [];

        if (array_key_exists('cliente_id', $dados)) {
            $clienteId = $dados['cliente_id'] === null || $dados['cliente_id'] === '' ? null : (int) $dados['cliente_id'];
            if ($clienteId !== null && $clienteId !== $projeto->getOriginal('cliente_id') && ! ClienteTempo::ativos()->whereKey($clienteId)->exists()) {
                $erros['cliente_id'] = 'Escolha um cliente ativo.';
            }
            $projeto->cliente_id = $clienteId;
        }

        if (array_key_exists('nome', $dados)) {
            $nome = trim(preg_replace('/\s+/u', ' ', (string) $dados['nome']));
            if ($nome === '') {
                $erros['nome'] = 'Indique o nome do projeto.';
            } elseif (mb_strlen($nome) > 255) {
                $erros['nome'] = 'O nome não pode passar de 255 caracteres.';
            }
            $projeto->nome = $nome;
        }

        if (! isset($erros['nome']) && ($projeto->isDirty('nome') || $projeto->isDirty('cliente_id'))) {
            $repetido = ProjetoTempo::whereRaw('lower(nome) = lower(?)', [$projeto->nome])
                ->whereRaw('coalesce(cliente_id, 0) = ?', [(int) $projeto->cliente_id])
                ->when($projeto->exists, fn ($q) => $q->whereKeyNot($projeto->id))
                ->exists();
            if ($repetido) {
                $erros['nome'] = $projeto->cliente_id ? 'Este cliente já tem um projeto chamado «'.$projeto->nome.'».' : 'Já existe um projeto sem cliente chamado «'.$projeto->nome.'».';
            }
        }

        if (array_key_exists('cor', $dados)) {
            if (! in_array($dados['cor'], ProjetoTempo::CORES, true)) {
                $erros['cor'] = 'Escolha uma cor da lista.';
            }
            $projeto->cor = (string) $dados['cor'];
        }

        foreach (['publico', 'faturavel'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $projeto->{$campo} = (bool) $dados[$campo];
            }
        }

        if (array_key_exists('taxa', $dados)) {
            try {
                $projeto->taxa_cent = Dinheiro::paraCentimos($dados['taxa'] === null ? null : (string) $dados['taxa']);
            } catch (\InvalidArgumentException $e) {
                $erros['taxa'] = $e->getMessage();
            }
        }

        if (array_key_exists('estimativa', $dados)) {
            try {
                $projeto->estimativa_seg = self::lerHoras($dados['estimativa'] === null ? null : (string) $dados['estimativa']);
                if ($projeto->estimativa_seg === 0) {
                    $erros['estimativa'] = 'A estimativa tem de ser maior do que zero.';
                }
            } catch (ValidationException $e) {
                $erros['estimativa'] = $e->errors()['estimativa'][0];
            }
        }

        if (array_key_exists('nota', $dados)) {
            $projeto->nota = trim((string) $dados['nota']) ?: null;
        }

        $membros = null;
        if (array_key_exists('membros', $dados)) {
            $membros = array_values(array_unique(array_map('intval', (array) $dados['membros'])));
            if (count($membros) !== MembroEquipa::whereKey($membros)->count()) {
                $erros['membros'] = 'Escolha membros da equipa.';
            }
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        return $membros;
    }

    /**
     * Aplica uma ação a vários projetos, tudo ou nada.
     *
     * @param  list<int>  $ids
     */
    private function emMassa(User $autor, array $ids, string $acao, callable $aplicar): int
    {
        Gate::forUser($autor)->authorize('tempos-gerir-projetos');

        $projetos = ProjetoTempo::whereKey(array_map('intval', $ids))->orderBy('nome')->get();
        if ($projetos->isEmpty()) {
            throw ValidationException::withMessages(['projetos' => 'Selecione pelo menos um projeto.']);
        }

        DB::transaction(fn () => $projetos->each($aplicar));

        foreach ($projetos as $p) {
            Auditor::registar('tempo_projeto_'.$acao, $p, ['nome' => $p->nome]);
        }

        return $projetos->count();
    }
}

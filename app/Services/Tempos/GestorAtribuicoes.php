<?php

namespace App\Services\Tempos;

use App\Models\AtribuicaoTempo;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Atribuições: criar, alterar e apagar (quem gere a equipa, `tempos-gerir-equipa`). Uma pessoa com acesso
 * aos Tempos, um projeto ativo, duas datas (no máximo um ano) e as horas por dia (0:01 a 24:00). Tudo
 * fica na auditoria.
 */
class GestorAtribuicoes
{
    /** @param array{utilizador_id?: int|string, projeto_id?: int|string, de?: string, ate?: string, horas_dia?: string, fins_de_semana?: bool, nota?: string|null} $dados */
    public function criar(User $autor, array $dados): AtribuicaoTempo
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $a = new AtribuicaoTempo;
        $this->preencher($a, $dados + ['utilizador_id' => null, 'projeto_id' => null, 'de' => '', 'ate' => '', 'horas_dia' => '']);
        $a->criado_por = $autor->id;
        $a->alterado_por = $autor->id;
        $a->save();

        Auditor::registar('tempo_atribuicao_criada', $a, $this->resumo($a));

        return $a;
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(User $autor, AtribuicaoTempo $a, array $dados): AtribuicaoTempo
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $this->preencher($a, $dados);
        $campos = array_keys($a->getDirty());
        $a->alterado_por = $autor->id;
        $a->save();

        if ($campos !== []) {
            Auditor::registar('tempo_atribuicao_alterada', $a, $this->resumo($a) + ['campos' => $campos]);
        }

        return $a;
    }

    public function apagar(User $autor, AtribuicaoTempo $a): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $a->delete();
        Auditor::registar('tempo_atribuicao_apagada', $a, $this->resumo($a));
    }

    /** @param array<string, mixed> $dados */
    private function preencher(AtribuicaoTempo $a, array $dados): void
    {
        $erros = [];

        if (array_key_exists('utilizador_id', $dados)) {
            $id = (int) $dados['utilizador_id'];
            if (! $id || ! User::comAcessoAosTempos()->whereKey($id)->exists()) {
                $erros['utilizador_id'] = 'Escolha um membro da equipa.';
            }
            $a->utilizador_id = $id ?: null;
        }

        if (array_key_exists('projeto_id', $dados)) {
            $id = (int) $dados['projeto_id'];
            $projeto = $id ? ProjetoTempo::find($id) : null;
            if (! $projeto) {
                $erros['projeto_id'] = 'Escolha um projeto.';
            } elseif ($projeto->estaArquivado() && $id !== (int) $a->getOriginal('projeto_id')) {
                $erros['projeto_id'] = 'O projeto «'.$projeto->nome.'» está arquivado.';
            }
            $a->projeto_id = $id ?: null;
        }

        $data = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && ($d = CarbonImmutable::createFromFormat('!Y-m-d', $v)) && $d->toDateString() === $v ? $d : null;
        foreach (['de', 'ate'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $d = $data($dados[$campo]);
                if (! $d) {
                    $erros[$campo] = $campo === 'de' ? 'Indique a data de início.' : 'Indique a data de fim.';
                } else {
                    $a->{$campo} = $d;
                }
            }
        }
        if (! isset($erros['de']) && ! isset($erros['ate']) && $a->de && $a->ate) {
            if ($a->ate->lt($a->de)) {
                $erros['ate'] = 'A data de fim não pode ser antes da de início.';
            } elseif ($a->de->diffInDays($a->ate) > 366) {
                $erros['ate'] = 'No máximo um ano.';
            }
        }

        if (array_key_exists('horas_dia', $dados)) {
            try {
                $segundos = GestorProjetos::lerHoras((string) $dados['horas_dia']);
            } catch (ValidationException) {
                $segundos = null;
            }
            if (! $segundos || $segundos < 60 || $segundos > 86400) {
                $erros['horas_dia'] = 'Indique as horas por dia (ex.: 4 ou 7:30), até 24.';
            } else {
                $a->horas_dia_seg = $segundos;
            }
        }

        if (array_key_exists('fins_de_semana', $dados)) {
            $a->fins_de_semana = (bool) $dados['fins_de_semana'];
        }
        if (array_key_exists('nota', $dados)) {
            $a->nota = trim((string) $dados['nota']) ?: null;
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }

    /** @return array<string, mixed> */
    private function resumo(AtribuicaoTempo $a): array
    {
        return [
            'utilizador_id' => $a->utilizador_id,
            'projeto_id' => $a->projeto_id,
            'de' => $a->de?->toDateString(),
            'ate' => $a->ate?->toDateString(),
            'horas_dia_seg' => $a->horas_dia_seg,
        ];
    }
}

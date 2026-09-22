<?php

namespace App\Policies;

use App\Models\RegistoTempo;
use App\Models\SemanaTempo;
use App\Models\User;
use App\Services\Tempos\Faturacao\MesesFechados;
use Illuminate\Support\Facades\Gate;

/**
 * Quem pode ver e mexer em registos de tempo (docs/modulo-tempos.md §2, regras 1–4).
 *
 * Por ordem, para criar, alterar ou apagar:
 *   4. Faturado → ninguém (só se anula, por admin, com registo na auditoria).
 *   3. Fechado no fecho mensal → só admin com permissão explícita de reabrir.
 *   1. De outro técnico → só admin.
 *   2. Semana submetida → o técnico não mexe; o admin sim. Aprovada → ninguém, até ser reaberta.
 *      Rejeitada e reaberta voltam a ser editáveis.
 *
 * Numa alteração verifica-se o registo como ESTAVA e como FICA: mudar um registo para outro dia
 * ou outro técnico não pode servir para o tirar de uma semana submetida, nem para o meter numa.
 */
class RegistoTempoPolicy
{
    public function viewAny(User $utilizador): bool
    {
        return true; // cada um vê os seus; a listagem filtra quem não tem tempos-ver-todos
    }

    public function view(User $utilizador, RegistoTempo $registo): bool
    {
        return (int) $registo->tecnico_id === $utilizador->id
            || Gate::forUser($utilizador)->allows('tempos-ver-todos');
    }

    public function create(User $utilizador, RegistoTempo $registo): bool
    {
        return $this->podeMexer($utilizador, $registo);
    }

    public function update(User $utilizador, RegistoTempo $registo): bool
    {
        return $this->podeMexer($utilizador, $registo)
            && ($registo->isDirty() === false || $this->podeMexer($utilizador, $this->comoEstava($registo)));
    }

    public function delete(User $utilizador, RegistoTempo $registo): bool
    {
        return $this->podeMexer($utilizador, $this->comoEstava($registo));
    }

    // Regra 4: um registo faturado nunca se edita; só se anula, por admin.
    public function anular(User $utilizador, RegistoTempo $registo): bool
    {
        return $registo->faturado_em !== null && $utilizador->ehAdminTempos();
    }

    private function podeMexer(User $utilizador, RegistoTempo $registo): bool
    {
        if ($registo->faturado_em !== null) {
            return false;
        }

        // Regras 3 e 12: fechado no fecho mensal — o registo, ou o mês do seu dia (vale também para
        // registos novos ou mudados para um mês fechado).
        if ($registo->fechado_em !== null || ($registo->inicio !== null && app(MesesFechados::class)->estaFechado($registo->dia()))) {
            return Gate::forUser($utilizador)->allows('tempos-reabrir');
        }

        $podeTodos = Gate::forUser($utilizador)->allows('tempos-editar-todos');

        if ((int) $registo->tecnico_id !== $utilizador->id && ! $podeTodos) {
            return false;
        }

        $estado = SemanaTempo::estadoDe((int) $registo->tecnico_id, $registo->dia());

        // Aprovada fecha a semana a todos; para a corrigir, reabre-se.
        return $podeTodos ? ! $estado->bloqueiaTodos() : ! $estado->bloqueiaTecnico();
    }

    private function comoEstava(RegistoTempo $registo): RegistoTempo
    {
        return (new RegistoTempo)->setRawAttributes($registo->getRawOriginal(), true);
    }
}

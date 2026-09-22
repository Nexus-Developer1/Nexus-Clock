<?php

namespace App\Services\Tempos;

use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edição em massa de registos (ideia tirada do Clockify): mudar faturável, descrição, etiquetas ou
 * cliente/contrato/intervenção ou projeto de vários registos de uma vez, ou apagá-los.
 *
 * Cada registo passa pelo GravadorRegistos (autorização e regras de negócio) e **ou vão todos ou não
 * vai nenhum**: basta um estar numa semana entregue, num mês fechado ou já faturado para nada mudar,
 * com a mensagem a dizer qual. Fica na auditoria.
 */
class EdicaoEmMassa
{
    public const MAXIMO = 500;

    public function __construct(private readonly GravadorRegistos $gravador) {}

    /**
     * @param  list<int>  $ids
     * @param  array{faturavel?: bool|null, descricao?: string|null, etiquetas_acrescentar?: list<string>,
     *     etiquetas_retirar?: list<string>, projeto?: int|null, ligacao?: array{cliente_id: int|null, contrato_id: int|null, intervencao_id: int|null}|null}  $alteracoes
     * @return int registos alterados
     */
    public function aplicar(User $autor, array $ids, array $alteracoes): int
    {
        $alteracoes = $this->normalizar($alteracoes);
        if ($alteracoes === []) {
            throw ValidationException::withMessages(['massa' => 'Escolha pelo menos uma alteração.']);
        }

        $registos = $this->registos($ids);

        DB::transaction(function () use ($autor, $registos, $alteracoes) {
            foreach ($registos as $registo) {
                $dados = [];

                if (array_key_exists('faturavel', $alteracoes)) {
                    $dados['faturavel'] = $alteracoes['faturavel'];
                }
                if (array_key_exists('descricao', $alteracoes)) {
                    $dados['descricao'] = $alteracoes['descricao'];
                }
                if (array_key_exists('projeto', $alteracoes)) {
                    $dados['projeto_id'] = $alteracoes['projeto'];
                }
                if (isset($alteracoes['etiquetas_acrescentar']) || isset($alteracoes['etiquetas_retirar'])) {
                    $etiquetas = array_merge($registo->etiquetas, $alteracoes['etiquetas_acrescentar'] ?? []);
                    $etiquetas = array_diff($etiquetas, $alteracoes['etiquetas_retirar'] ?? []);
                    $dados['etiquetas'] = array_values(array_unique($etiquetas));
                }
                if (isset($alteracoes['ligacao'])) {
                    $dados += $alteracoes['ligacao'];
                }

                $this->porRegisto($registo, fn () => $this->gravador->atualizar($autor, $registo, $dados));
            }
        });

        Auditor::registar('tempo_registos_editados_em_massa', null, [
            'registos' => $registos->pluck('id')->all(),
            'alteracoes' => $alteracoes,
        ]);

        return $registos->count();
    }

    /**
     * @param  list<int>  $ids
     * @return int registos apagados
     */
    public function apagar(User $autor, array $ids): int
    {
        $registos = $this->registos($ids);

        DB::transaction(function () use ($autor, $registos) {
            foreach ($registos as $registo) {
                $this->porRegisto($registo, fn () => $this->gravador->apagar($autor, $registo));
            }
        });

        Auditor::registar('tempo_registos_apagados_em_massa', null, [
            'registos' => $registos->map(fn (RegistoTempo $r) => [
                'id' => $r->id, 'tecnico_id' => $r->tecnico_id, 'cliente_id' => $r->cliente_id,
                'dia' => $r->dia()->toDateString(), 'duracao_seg' => $r->duracao_seg,
            ])->all(),
        ]);

        return $registos->count();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, RegistoTempo>
     */
    private function registos(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw ValidationException::withMessages(['massa' => 'Selecione pelo menos um registo.']);
        }
        if (count($ids) > self::MAXIMO) {
            throw ValidationException::withMessages(['massa' => 'No máximo '.self::MAXIMO.' registos de cada vez.']);
        }

        return RegistoTempo::with('cliente')->whereKey($ids)->orderBy('inicio')->get();
    }

    /** Normaliza e deixa só o que é para mudar. */
    private function normalizar(array $alteracoes): array
    {
        $limpas = [];

        if (array_key_exists('faturavel', $alteracoes) && $alteracoes['faturavel'] !== null) {
            $limpas['faturavel'] = (bool) $alteracoes['faturavel'];
        }
        if (array_key_exists('descricao', $alteracoes) && $alteracoes['descricao'] !== null) {
            $limpas['descricao'] = trim((string) $alteracoes['descricao']) ?: null;
        }
        // Projeto: id, ou 0 para tirar o projeto.
        if (array_key_exists('projeto', $alteracoes) && $alteracoes['projeto'] !== null && $alteracoes['projeto'] !== '') {
            $limpas['projeto'] = ((int) $alteracoes['projeto']) ?: null;
        }
        foreach (['etiquetas_acrescentar', 'etiquetas_retirar'] as $campo) {
            $etiquetas = array_values(array_filter(array_map('trim', $alteracoes[$campo] ?? [])));
            if ($etiquetas !== []) {
                $limpas[$campo] = $etiquetas;
            }
        }
        if (! empty($alteracoes['ligacao']['cliente_id'])) {
            $limpas['ligacao'] = [
                'cliente_id' => (int) $alteracoes['ligacao']['cliente_id'],
                'contrato_id' => ($alteracoes['ligacao']['contrato_id'] ?? null) ?: null,
                'intervencao_id' => ($alteracoes['ligacao']['intervencao_id'] ?? null) ?: null,
            ];
        }

        return $limpas;
    }

    // Um erro num registo desfaz tudo e diz qual foi.
    private function porRegisto(RegistoTempo $registo, callable $acao): void
    {
        $qual = 'O registo de '.$registo->dia()->format('d/m').' ('.($registo->cliente?->nome ?? 'sem cliente').')';

        try {
            $acao();
        } catch (AuthorizationException) {
            throw ValidationException::withMessages(['massa' => $qual.' não pode ser alterado: está numa semana entregue, num mês fechado, já foi faturado, ou é de outro técnico. Nada foi alterado.']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['massa' => $qual.': '.collect($e->errors())->flatten()->first().' Nada foi alterado.']);
        }
    }
}

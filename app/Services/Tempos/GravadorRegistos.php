<?php

namespace App\Services\Tempos;

use App\Enums\OrigemRegistoTempo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Intervencao;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Auditor;
use App\Services\Tempos\Faturacao\MesesFechados;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ponto ÚNICO de escrita dos registos de tempo: autoriza (RegistoTempoPolicy), valida as regras
 * de negócio e grava. A timesheet, o cronómetro e qualquer importação passam por aqui.
 *
 * Dados aceites (criar/atualizar):
 *   tecnico_id      por omissão, quem grava
 *   projeto_id      projeto dos Tempos ativo (opcional)
 *   cliente_id      cliente da Nexus Infra (opcional; a interface dos Tempos já não o usa — notas §30)
 *   contrato_id     do mesmo cliente (opcional)
 *   intervencao_id  do mesmo cliente (e do mesmo contrato, se houver contrato) (opcional)
 *   dia + duracao_seg     registo da timesheet: inicio = meia-noite local do dia
 *   inicio [+ fim]        registo com horas reais; sem fim = cronómetro a correr
 *   faturavel, descricao, etiquetas, origem
 *
 * Erros de regra saem como ValidationException (com a mensagem a mostrar, por campo).
 */
class GravadorRegistos
{
    /** @param array<string, mixed> $dados */
    public function criar(User $autor, array $dados): RegistoTempo
    {
        $registo = new RegistoTempo;
        $this->preencher($registo, $dados + ['tecnico_id' => $autor->id]);

        Gate::forUser($autor)->authorize('create', $registo);
        $this->validar($registo);

        $registo->criado_por = $autor->id;
        $registo->alterado_por = $autor->id;
        $this->gravar($registo);

        return $registo;
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(User $autor, RegistoTempo $registo, array $dados): RegistoTempo
    {
        $this->preencher($registo, $dados);

        Gate::forUser($autor)->authorize('update', $registo);
        $this->validar($registo);

        $registo->alterado_por = $autor->id;
        $this->gravar($registo);

        return $registo;
    }

    public function apagar(User $autor, RegistoTempo $registo): void
    {
        Gate::forUser($autor)->authorize('delete', $registo);

        $registo->alterado_por = $autor->id;
        $registo->saveQuietly();
        $registo->delete();
    }

    /**
     * Anula um registo JÁ FATURADO (regra 4): só admin, com motivo, e fica na auditoria. O registo
     * sai dos consumos (soft delete) mas o rasto de que foi faturado mantém-se.
     */
    public function anular(User $autor, RegistoTempo $registo, string $motivo): void
    {
        Gate::forUser($autor)->authorize('anular', $registo);

        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Indique o motivo da anulação.']);
        }

        DB::transaction(function () use ($autor, $registo) {
            $registo->alterado_por = $autor->id;
            $registo->saveQuietly();
            $registo->delete();
        });

        Auditor::registar('tempo_registo_anulado', $registo, [
            'motivo' => trim($motivo),
            'tecnico_id' => $registo->tecnico_id,
            'cliente_id' => $registo->cliente_id,
            'contrato_id' => $registo->contrato_id,
            'dia' => $registo->dia()->toDateString(),
            'duracao_seg' => $registo->duracao_seg,
            'faturado_em' => $registo->faturado_em?->toIso8601String(),
        ]);
    }

    /**
     * Avisos que não bloqueiam a gravação, para a UI mostrar ao lado do registo.
     *
     * @return list<string>
     */
    public function avisos(RegistoTempo $registo): array
    {
        $avisos = [];

        // Regra 7 (decisão de 2026-09-14): intervenção concluída AVISA, não bloqueia — a timesheet
        // é preenchida ao fim da semana, quando a intervenção normalmente já foi concluída.
        if ($registo->intervencao_id && Intervencao::find($registo->intervencao_id)?->estaConcluida()) {
            $avisos[] = 'A intervenção já está concluída na Nexus Infra.';
        }

        return $avisos;
    }

    /** @param array<string, mixed> $dados */
    private function preencher(RegistoTempo $registo, array $dados): void
    {
        $registo->fill(collect($dados)->only([
            'tecnico_id', 'cliente_id', 'contrato_id', 'projeto_id', 'intervencao_id',
            'faturavel', 'descricao', 'etiquetas', 'origem',
        ])->all());

        if (array_key_exists('dia', $dados)) {
            // Timesheet: meia-noite local; fim = início + duração (não se partem registos à meia-noite).
            $inicio = RegistoTempo::inicioDoDia((string) $dados['dia']);
            $duracao = $dados['duracao_seg'] ?? $registo->duracao_seg ?? 0;
            $registo->inicio = $inicio;
            $registo->duracao_seg = (int) $duracao;
            $registo->fim = $inicio->addSeconds((int) $duracao);
            $registo->origem ??= OrigemRegistoTempo::Timesheet;

            return;
        }

        if (array_key_exists('inicio', $dados)) {
            $registo->inicio = CarbonImmutable::parse($dados['inicio'])->utc();
        }

        if (array_key_exists('fim', $dados)) {
            $registo->fim = $dados['fim'] === null ? null : CarbonImmutable::parse($dados['fim'])->utc();
            $registo->duracao_seg = $registo->fim === null ? null : (int) $registo->inicio->diffInSeconds($registo->fim, false);
        } elseif (array_key_exists('duracao_seg', $dados) && $registo->inicio !== null) {
            $registo->duracao_seg = (int) $dados['duracao_seg'];
            $registo->fim = $registo->inicio->addSeconds((int) $dados['duracao_seg']);
        }
    }

    private function validar(RegistoTempo $registo): void
    {
        $erros = [];

        if (! $registo->tecnico_id || ! User::whereKey($registo->tecnico_id)->exists()) {
            $erros['tecnico_id'] = 'Indique o técnico.';
        }

        // O cliente da Nexus Infra é opcional (notas §30); se vier, tem de existir e contrato e
        // intervenção têm de bater certo com ele. Sem cliente não há contrato nem intervenção.
        $cliente = $registo->cliente_id ? Cliente::find($registo->cliente_id) : null;
        if ($registo->cliente_id && ! $cliente) {
            $erros['cliente_id'] = 'O cliente não existe.';
        } elseif (! $cliente && ($registo->contrato_id || $registo->intervencao_id)) {
            $erros['cliente_id'] = 'Indique o cliente do contrato ou da intervenção.';
        }

        if ($registo->inicio === null) {
            $erros['inicio'] = 'Indique o dia.';
        }

        if ($registo->duracao_seg !== null && ($registo->duracao_seg < 0 || $registo->duracao_seg > LeitorDuracao::MAXIMO_SEG)) {
            $erros['duracao_seg'] = $registo->duracao_seg < 0
                ? 'O fim não pode ser antes do início.'
                : 'Uma duração não pode passar de 24 horas.';
        }

        if ($cliente) {
            $erros += $this->errosDeLigacao($cliente->id, $registo->contrato_id, $registo->intervencao_id);
        }

        // Projeto: tem de existir e, ao escolhê-lo, estar ativo (registos antigos de um projeto entretanto
        // arquivado continuam editáveis).
        if ($registo->projeto_id && $registo->isDirty('projeto_id')) {
            $projeto = ProjetoTempo::find($registo->projeto_id);
            if (! $projeto) {
                $erros['projeto_id'] = 'O projeto não existe.';
            } elseif ($projeto->estaArquivado()) {
                $erros['projeto_id'] = 'O projeto «'.$projeto->nome.'» está arquivado.';
            }
        }

        // Regra 5: um só cronómetro a correr por técnico (a base de dados também o garante).
        if ($registo->fim === null && $registo->tecnico_id && $this->outroCronometroACorrer($registo)) {
            $erros['fim'] = 'Já tem um cronómetro a correr. Pare-o antes de iniciar outro.';
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }

    /**
     * Regra 6: contrato e intervenção têm de ser do cliente (e a intervenção, do contrato).
     * Público porque a folha de horas valida a combinação ao acrescentar uma linha, antes de haver
     * registos.
     *
     * @return array<string, string> erros por campo (vazio = coerente)
     */
    public function errosDeLigacao(int $clienteId, ?int $contratoId, ?int $intervencaoId): array
    {
        $erros = [];

        if ($contratoId) {
            $contrato = Contrato::find($contratoId);
            if (! $contrato) {
                $erros['contrato_id'] = 'O contrato não existe.';
            } elseif ((int) $contrato->cliente_id !== $clienteId) {
                $erros['contrato_id'] = 'O contrato '.$contrato->numero.' não é deste cliente.';
            }
        }

        if ($intervencaoId) {
            $intervencao = Intervencao::with('equipamento.local')->find($intervencaoId);
            $clienteDaIntervencao = $intervencao?->clienteId();

            if (! $intervencao) {
                $erros['intervencao_id'] = 'A intervenção não existe.';
            } elseif ($clienteDaIntervencao === null) {
                $erros['intervencao_id'] = 'A intervenção não tem cliente: o equipamento ainda não está associado a um local na Nexus Infra.';
            } elseif ($clienteDaIntervencao !== $clienteId) {
                $erros['intervencao_id'] = 'A intervenção não é deste cliente.';
            } elseif ($contratoId && (int) $intervencao->contrato_id !== $contratoId) {
                $erros['intervencao_id'] = 'A intervenção não pertence a este contrato.';
            }
        }

        return $erros;
    }

    private function outroCronometroACorrer(RegistoTempo $registo): bool
    {
        return RegistoTempo::query()
            ->doTecnico((int) $registo->tecnico_id)
            ->whereNull('fim')
            ->when($registo->exists, fn ($q) => $q->whereKeyNot($registo->getKey()))
            ->exists();
    }

    private function gravar(RegistoTempo $registo): void
    {
        // Gravado (com permissão de reabrir) num mês fechado: nasce fechado, para entrar na faturação.
        if ($registo->fechado_em === null && app(MesesFechados::class)->estaFechado($registo->dia())) {
            $registo->fechado_em = now();
        }

        try {
            // Savepoint: se o índice único rebentar dentro de uma transação exterior, esta sobrevive.
            DB::transaction(fn () => $registo->save());
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'registos_tempo_um_cronometro_por_tecnico')) {
                throw ValidationException::withMessages(['fim' => 'Já tem um cronómetro a correr. Pare-o antes de iniciar outro.']);
            }

            throw $e;
        }
    }
}

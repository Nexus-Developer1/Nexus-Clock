<?php

namespace App\Services\Tempos;

use App\Enums\OrigemRegistoTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cronómetro (Fase 6): um registo com `inicio` à hora real e `fim` nulo enquanto corre. O estado vive
 * no servidor (serve entre separadores e dispositivos) e tudo passa pelo GravadorRegistos — regra 5,
 * um só a correr por técnico, validada aqui e garantida pela base de dados.
 *
 * Cada um só mexe no seu cronómetro. Ao parar, o registo fica no dia em que começou (não se partem
 * registos à meia-noite) e aparece na folha de horas somado aos outros desse dia.
 */
class Cronometro
{
    // Abaixo disto, parar descarta o registo (arranque sem querer).
    public const MINIMO_SEG = 60;

    public function __construct(private readonly GravadorRegistos $gravador) {}

    public function aCorrer(User $tecnico): ?RegistoTempo
    {
        return RegistoTempo::query()->doTecnico($tecnico)->whereNull('fim')
            ->with(['cliente', 'contrato', 'intervencao.equipamento'])->first();
    }

    /**
     * Inicia o cronómetro. Se já houver um a correr, para-o primeiro (troca de tarefa).
     *
     * @param  array{cliente_id?: int|null, contrato_id?: int|null, projeto_id?: int|null, intervencao_id?: int|null, descricao?: string|null, faturavel?: bool, etiquetas?: list<string>}  $dados
     */
    public function iniciar(User $autor, array $dados): RegistoTempo
    {
        $registo = DB::transaction(function () use ($autor, $dados) {
            if ($this->aCorrer($autor)) {
                $this->parar($autor);
            }

            return $this->gravador->criar($autor, [
                'tecnico_id' => $autor->id,
                'cliente_id' => $dados['cliente_id'] ?? null,
                'contrato_id' => $dados['contrato_id'] ?? null,
                'projeto_id' => $dados['projeto_id'] ?? null,
                'intervencao_id' => $dados['intervencao_id'] ?? null,
                'descricao' => ($dados['descricao'] ?? null) ?: null,
                'faturavel' => $dados['faturavel'] ?? true,
                'etiquetas' => $dados['etiquetas'] ?? [],
                'inicio' => CarbonImmutable::now(),
                'fim' => null,
                'origem' => OrigemRegistoTempo::Cronometro,
            ]);
        });

        return $registo;
    }

    // --- Pausa (notas §67) ---
    // Um só registo, com a pausa descontada: pausar marca `pausado_em` (o relógio para); retomar soma esse
    // tempo a `pausa_seg` e o relógio continua de onde estava; parar grava (fim − início) − pausas. O
    // cronómetro em pausa continua a ser «o que está a correr» (fim nulo): começar outra coisa para-o.

    /** O cronómetro de quem está a ver, se estiver em pausa. */
    public function emPausa(User $tecnico): ?RegistoTempo
    {
        $registo = $this->aCorrer($tecnico);

        return $registo?->pausado_em ? $registo : null;
    }

    public function pausar(User $autor): RegistoTempo
    {
        $registo = $this->aCorrer($autor)
            ?? throw ValidationException::withMessages(['cronometro' => 'Não há nenhum cronómetro a correr.']);
        if ($registo->pausado_em) {
            throw ValidationException::withMessages(['cronometro' => 'O cronómetro já está em pausa.']);
        }

        return $this->gravador->atualizar($autor, $registo, ['pausado_em' => CarbonImmutable::now()]);
    }

    public function retomar(User $autor): RegistoTempo
    {
        $registo = $this->emPausa($autor)
            ?? throw ValidationException::withMessages(['cronometro' => 'Não há nada em pausa.']);
        $pausa = (int) $registo->pausado_em->diffInSeconds(CarbonImmutable::now(), true);

        return $this->gravador->atualizar($autor, $registo, ['pausado_em' => null, 'pausa_seg' => (int) $registo->pausa_seg + $pausa]);
    }

    /** Tempo trabalhado de um cronómetro a correr (ou em pausa), sem as pausas. */
    public static function trabalhado(RegistoTempo $registo): int
    {
        $ate = $registo->pausado_em ?? CarbonImmutable::now();

        return max(0, (int) $registo->inicio->diffInSeconds($ate, true) - (int) $registo->pausa_seg);
    }

    /** Recomeça o trabalho de um registo anterior (mesmo cliente, contrato, projeto, intervenção e atributos). */
    public function continuar(User $autor, RegistoTempo $modelo): RegistoTempo
    {
        if ((int) $modelo->tecnico_id !== $autor->id) {
            throw new AuthorizationException('Só pode continuar os seus registos.');
        }

        return $this->iniciar($autor, [
            'cliente_id' => $modelo->cliente_id,
            'contrato_id' => $modelo->contrato_id,
            'projeto_id' => $modelo->projeto_id && ! ProjetoTempo::find($modelo->projeto_id)?->estaArquivado() ? $modelo->projeto_id : null,
            'intervencao_id' => $modelo->intervencao_id,
            'descricao' => $modelo->descricao,
            'faturavel' => $modelo->faturavel,
            'etiquetas' => $modelo->etiquetas,
        ]);
    }

    /**
     * Para o cronómetro a correr.
     *
     * @return RegistoTempo|null o registo gravado, ou null se durou menos de um minuto (descartado)
     */
    public function parar(User $autor): ?RegistoTempo
    {
        $registo = $this->aCorrer($autor)
            ?? throw ValidationException::withMessages(['cronometro' => 'Não há nenhum cronómetro a correr.']);

        // Em pausa, acaba na hora da pausa; a duração desconta as pausas (notas §67).
        $fim = $registo->pausado_em ?? CarbonImmutable::now();
        $segundos = self::trabalhado($registo);

        if ($segundos < self::MINIMO_SEG) {
            $this->gravador->apagar($autor, $registo);

            return null;
        }

        if ($segundos > LeitorDuracao::MAXIMO_SEG) {
            throw ValidationException::withMessages(['cronometro' => 'O cronómetro está a correr há mais de 24 horas. Indique nos Registos a hora a que terminou, ou descarte-o.']);
        }

        return $this->gravador->atualizar($autor, $registo, ['pausado_em' => null, 'fim' => $fim]);
    }

    /** Apaga o cronómetro a correr sem gravar horas. */
    public function descartar(User $autor): void
    {
        $registo = $this->aCorrer($autor)
            ?? throw ValidationException::withMessages(['cronometro' => 'Não há nenhum cronómetro a correr.']);

        $this->gravador->apagar($autor, $registo);
    }
}

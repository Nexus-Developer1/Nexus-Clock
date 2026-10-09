<?php

namespace App\Services\Tempos;

use App\Enums\OrigemRegistoTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $agora = CarbonImmutable::now();
        $segundos = (int) $registo->inicio->diffInSeconds($agora, true);

        if ($segundos < self::MINIMO_SEG) {
            $this->gravador->apagar($autor, $registo);

            return null;
        }

        if ($segundos > LeitorDuracao::MAXIMO_SEG) {
            throw ValidationException::withMessages(['cronometro' => 'O cronómetro está a correr há mais de 24 horas. Indique nos Registos a hora a que terminou, ou descarte-o.']);
        }

        return $this->gravador->atualizar($autor, $registo, ['fim' => $agora]);
    }

    /** Apaga o cronómetro a correr sem gravar horas. */
    public function descartar(User $autor): void
    {
        $registo = $this->aCorrer($autor)
            ?? throw ValidationException::withMessages(['cronometro' => 'Não há nenhum cronómetro a correr.']);

        $this->gravador->apagar($autor, $registo);
    }

    /**
     * Hora a que um cronómetro esquecido para sozinho (notas §78): às config('tempos.parar_cronometro_as')
     * (19:00) do dia em que começou; se começou a essa hora ou depois, às 23:59:59 desse dia. Null se a
     * paragem automática estiver desligada.
     */
    public static function limite(RegistoTempo $registo): ?CarbonImmutable
    {
        $hora = trim((string) config('tempos.parar_cronometro_as'));
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora)) {
            return null;
        }

        $fuso = config('tempos.fuso');
        $inicio = $registo->inicio->setTimezone($fuso);
        $corte = CarbonImmutable::parse($inicio->toDateString().' '.$hora, $fuso);

        return ($inicio->lt($corte) ? $corte : $inicio->setTime(23, 59, 59))->utc();
    }

    /**
     * Para os cronómetros que passaram do limite (corre de 5 em 5 minutos): grava o fim no limite, não
     * na hora em que isto corre. Com menos de um minuto, descarta, como ao parar à mão. Passa pelo
     * GravadorRegistos em nome do dono; o que ele recusar (mês fechado, semana entregue…) fica a correr
     * e vai para o log. Sem email, a pedido.
     *
     * @return int quantos parou (ou descartou)
     */
    public function pararEsquecidos(?CarbonImmutable $agora = null): int
    {
        $agora ??= CarbonImmutable::now();
        $parados = 0;

        RegistoTempo::query()->whereNull('fim')->with('tecnico')->orderBy('id')->get()
            ->each(function (RegistoTempo $registo) use ($agora, &$parados) {
                $limite = self::limite($registo);
                $dono = $registo->tecnico;
                if (! $limite || $agora->lt($limite) || ! $dono) {
                    return;
                }

                try {
                    $segundos = (int) $registo->inicio->diffInSeconds($limite, true);
                    $segundos < self::MINIMO_SEG
                        ? $this->gravador->apagar($dono, $registo)
                        : $this->gravador->atualizar($dono, $registo, ['fim' => $limite]);
                } catch (AuthorizationException|ValidationException $e) {
                    Log::warning('Cronómetro esquecido não parou sozinho.', ['registo' => $registo->id, 'erro' => $e->getMessage()]);

                    return;
                }

                Auditor::registar('tempo_cronometro_parado_sozinho', $registo, [
                    'tecnico_id' => $registo->tecnico_id,
                    'inicio' => $registo->inicio->toIso8601String(),
                    'fim' => $limite->toIso8601String(),
                    'descartado' => $segundos < self::MINIMO_SEG,
                ]);
                $parados++;
            });

        return $parados;
    }
}

<?php

namespace App\Services\Tempos;

use App\Enums\OrigemRegistoTempo;
use App\Models\MembroEquipa;
use App\Models\RegistoTempo;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Relatório Presenças (como o "Attendance report" do Clockify): uma linha por pessoa e por dia, com a
 * primeira entrada e a última saída (só dos registos com horas reais), capacidade, trabalho, horas
 * extra, horas em falta, saldo e pausas.
 *
 * Capacidade = capacidade diária da pessoa (página Equipa, ou `tempos.capacidade_diaria_horas`) nos
 * seus dias de trabalho; 0 nos outros. Pausas = tempo entre a primeira entrada e a última saída que
 * não está em registos com horas. Só registos terminados. Não há ausências nos Tempos.
 */
class Presencas
{
    public const SITUACOES = [
        'com_registos' => 'Com registos',
        'sem_registos' => 'Sem registos',
        'extra' => 'Com horas extra',
        'em_falta' => 'Com horas em falta',
    ];

    /**
     * @param  list<int>|null  $membros  null = todos (quem vê a equipa)
     * @return list<array{tecnico_id: int, nome: string, dia: CarbonImmutable, inicio: ?CarbonImmutable, fim: ?CarbonImmutable, capacidade: int, trabalho: int, extra: int, em_falta: int, saldo: int, pausa: int}>
     */
    public function linhas(?array $membros, CarbonImmutable $de, CarbonImmutable $ate, string $situacao = ''): array
    {
        $pessoas = User::comAcessoAosTempos()
            ->when($membros !== null, fn ($q) => $q->whereIn('id', $membros))
            ->orderBy('nome')->get(['id', 'nome']);
        $equipa = MembroEquipa::whereIn('utilizador_id', $pessoas->modelKeys())->get(['utilizador_id', 'capacidade_diaria_seg', 'dias_trabalho'])->keyBy('utilizador_id');
        $padrao = (int) round(config('tempos.capacidade_diaria_horas') * 3600);
        $fuso = config('tempos.fuso');

        // Registos do período, agregados por pessoa e dia.
        $dias = [];
        RegistoTempo::query()->terminados()->noPeriodo($de, $ate)
            ->whereIn('tecnico_id', $pessoas->modelKeys())
            ->orderBy('inicio')
            ->get(['id', 'tecnico_id', 'inicio', 'fim', 'duracao_seg', 'origem'])
            ->each(function (RegistoTempo $r) use (&$dias, $fuso) {
                $chave = $r->tecnico_id.'|'.$r->dia()->toDateString();
                $d = $dias[$chave] ?? ['trabalho' => 0, 'inicio' => null, 'fim' => null, 'comHoras' => 0];
                $d['trabalho'] += (int) $r->duracao_seg;

                $horasReais = $r->origem === OrigemRegistoTempo::Cronometro || ! $r->inicio->equalTo(RegistoTempo::inicioDoDia($r->dia()));
                if ($horasReais) {
                    $inicio = $r->inicio->setTimezone($fuso);
                    $fim = $r->fim->setTimezone($fuso);
                    $d['inicio'] = $d['inicio'] === null || $inicio->lt($d['inicio']) ? $inicio : $d['inicio'];
                    $d['fim'] = $d['fim'] === null || $fim->gt($d['fim']) ? $fim : $d['fim'];
                    $d['comHoras'] += (int) $r->duracao_seg;
                }
                $dias[$chave] = $d;
            });

        $linhas = [];
        foreach ($pessoas as $p) {
            $membro = $equipa[$p->id] ?? null;
            $capacidadeDia = $membro?->capacidade_diaria_seg ?? $padrao;
            $diasTrabalho = $membro?->dias_trabalho ?? [1, 2, 3, 4, 5];

            for ($dia = $de; $dia->lte($ate); $dia = $dia->addDay()) {
                $d = $dias[$p->id.'|'.$dia->toDateString()] ?? ['trabalho' => 0, 'inicio' => null, 'fim' => null, 'comHoras' => 0];
                $capacidade = in_array($dia->dayOfWeekIso, $diasTrabalho, true) ? $capacidadeDia : 0;
                $saldo = $d['trabalho'] - $capacidade;

                $linhas[] = [
                    'tecnico_id' => $p->id,
                    'nome' => $p->nome,
                    'dia' => $dia,
                    'inicio' => $d['inicio'],
                    'fim' => $d['fim'],
                    'capacidade' => $capacidade,
                    'trabalho' => $d['trabalho'],
                    'extra' => max(0, $saldo),
                    'em_falta' => max(0, -$saldo),
                    'saldo' => $saldo,
                    'pausa' => $d['inicio'] ? max(0, (int) $d['inicio']->diffInSeconds($d['fim']) - $d['comHoras']) : 0,
                ];
            }
        }

        return array_values(array_filter($linhas, fn ($l) => match ($situacao) {
            'com_registos' => $l['trabalho'] > 0,
            'sem_registos' => $l['trabalho'] === 0 && $l['capacidade'] > 0,
            'extra' => $l['extra'] > 0,
            'em_falta' => $l['em_falta'] > 0,
            default => true,
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     * @return array{capacidade: int, trabalho: int, extra: int, em_falta: int, saldo: int, pausa: int}
     */
    public static function somar(array $linhas): array
    {
        $total = array_fill_keys(['capacidade', 'trabalho', 'extra', 'em_falta', 'saldo', 'pausa'], 0);
        foreach ($linhas as $l) {
            foreach ($total as $campo => $valor) {
                $total[$campo] = $valor + $l[$campo];
            }
        }

        return $total;
    }

    /** Saldo com sinal: "−1:30:00", "+0:15:00", "0:00:00". */
    public static function saldo(int $segundos): string
    {
        return ($segundos > 0 ? '+' : ($segundos < 0 ? '−' : '')).PainelTempos::hms(abs($segundos));
    }
}

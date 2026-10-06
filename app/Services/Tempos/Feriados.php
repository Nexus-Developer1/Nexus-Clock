<?php

namespace App\Services\Tempos;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Feriados de Portugal, iguais aos da agenda da Nexus Infra (`App\Services\Agenda\FeriadosPortugal`,
 * notas §72): os treze nacionais obrigatórios — dez em data fixa e três que andam com a Páscoa — e o
 * São João (24 de junho), municipal mas pedido pela equipa. São calculados, por isso valem para
 * qualquer ano sem ninguém os acrescentar.
 *
 * O Carnaval não é feriado obrigatório (é tolerância de ponto, decidida ano a ano): vê-se no
 * calendário com outro aspeto, mas não impede registos nem tira horas a ninguém.
 */
class Feriados
{
    /** Em data fixa: 'm-d' => nome. */
    private const FIXOS = [
        '01-01' => 'Ano Novo',
        '04-25' => 'Dia da Liberdade',
        '05-01' => 'Dia do Trabalhador',
        '06-10' => 'Dia de Portugal',
        '06-24' => 'São João',
        '08-15' => 'Assunção de Nossa Senhora',
        '10-05' => 'Implantação da República',
        '11-01' => 'Todos os Santos',
        '12-01' => 'Restauração da Independência',
        '12-08' => 'Imaculada Conceição',
        '12-25' => 'Natal',
    ];

    /** @var array<int, array<string, array{nome: string, tolerancia: bool}>> */
    private static array $porAno = [];

    /**
     * Todos os de um ano: 'Y-m-d' => ['nome' => …, 'tolerancia' => bool].
     *
     * @return array<string, array{nome: string, tolerancia: bool}>
     */
    public function doAno(int $ano): array
    {
        if (isset(self::$porAno[$ano])) {
            return self::$porAno[$ano];
        }

        $feriados = [];
        foreach (self::FIXOS as $md => $nome) {
            $feriados[$ano.'-'.$md] = ['nome' => $nome, 'tolerancia' => false];
        }

        $pascoa = self::pascoa($ano);
        $feriados[$pascoa->subDays(2)->toDateString()] = ['nome' => 'Sexta-feira Santa', 'tolerancia' => false];
        $feriados[$pascoa->toDateString()] = ['nome' => 'Domingo de Páscoa', 'tolerancia' => false];
        $feriados[$pascoa->addDays(60)->toDateString()] = ['nome' => 'Corpo de Deus', 'tolerancia' => false];
        $feriados[$pascoa->subDays(47)->toDateString()] ??= ['nome' => 'Carnaval (tolerância)', 'tolerancia' => true];

        ksort($feriados);

        return self::$porAno[$ano] = $feriados;
    }

    /** Nome do feriado nesse dia, ou null. Por omissão ignora as tolerâncias de ponto. */
    public function nome(CarbonInterface|string $dia, bool $incluirTolerancia = false): ?string
    {
        $data = is_string($dia) ? $dia : $dia->toDateString();
        $f = $this->doAno((int) substr($data, 0, 4))[$data] ?? null;

        return $f && ($incluirTolerancia || ! $f['tolerancia']) ? $f['nome'] : null;
    }

    public function eFeriado(CarbonInterface|string $dia): bool
    {
        return $this->nome($dia) !== null;
    }

    /** Quantos feriados caem de segunda a sexta entre os dois dias (inclusive). */
    public function emDiasUteis(CarbonInterface $de, CarbonInterface $ate): int
    {
        $n = 0;
        for ($dia = CarbonImmutable::parse($de->toDateString()); $dia->lte($ate); $dia = $dia->addDay()) {
            if (! $dia->isWeekend() && $this->eFeriado($dia)) {
                $n++;
            }
        }

        return $n;
    }

    /** Domingo de Páscoa (gregoriano), pelo algoritmo de Meeus/Jones/Butcher — sem a extensão `calendar`. */
    public static function pascoa(int $ano): CarbonImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($ano, $mes, $dia);
    }
}

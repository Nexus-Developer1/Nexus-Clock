<?php

namespace Tests\Unit;

use App\Enums\ModoArredondamento;
use App\Services\Tempos\LeitorDuracao;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Durações escritas à mão nas células da timesheet (1:30, 1,5, 90m, 1h30) e o arredondamento de
// faturação (para cima, ao mais próximo, para baixo).
class LeitorDuracaoTest extends TestCase
{
    /** @return array<string, array{0: string, 1: int|null}> */
    public static function aceites(): array
    {
        return [
            'horas:minutos' => ['1:30', 5400],
            'zero horas' => ['0:45', 2700],
            'duas casas' => ['10:05', 36300],
            'decimal com ponto' => ['1.5', 5400],
            'decimal com vírgula' => ['1,5', 5400],
            'quarto de hora' => ['0,25', 900],
            'sem zero à esquerda' => [',5', 1800],
            'inteiro = horas' => ['2', 7200],
            'minutos' => ['90m', 5400],
            'minutos por extenso' => ['45 min', 2700],
            'horas e minutos' => ['1h30', 5400],
            'horas e minutos com m' => ['1h30m', 5400],
            'só horas' => ['2h', 7200],
            'com espaços e maiúsculas' => ['  1H 05 ', 3900],
            'dia inteiro' => ['24:00', 86400],
            'decimal que não dá minutos certos' => ['1,333', 4799],
            'vazio' => ['', null],
            'só espaços' => ['   ', null],
        ];
    }

    #[DataProvider('aceites')]
    public function test_le_duracoes_aceites(string $texto, ?int $segundos): void
    {
        $this->assertSame($segundos, LeitorDuracao::ler($texto));
    }

    /** @return array<string, array{0: string}> */
    public static function recusadas(): array
    {
        return [
            'texto' => ['uma hora'],
            'minutos acima de 59' => ['1:75'],
            'negativo' => ['-1'],
            'mais de 24 horas' => ['25'],
            'mais de 24 horas em minutos' => ['1441m'],
            'separador sem casas' => ['1.'],
            'dois separadores' => ['1:30:00'],
        ];
    }

    #[DataProvider('recusadas')]
    public function test_recusa_o_que_nao_percebe(string $texto): void
    {
        $this->expectException(InvalidArgumentException::class);
        LeitorDuracao::ler($texto);
    }

    public function test_mensagem_de_erro_em_portugues(): void
    {
        try {
            LeitorDuracao::ler('abc');
            $this->fail('Devia ter recusado.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Não percebi a duração «abc». Use, por exemplo, 1:30, 1,5, 90m ou 1h30.', $e->getMessage());
        }
    }

    public function test_formata_como_na_timesheet(): void
    {
        $this->assertSame('1:30', LeitorDuracao::formatar(5400));
        $this->assertSame('0:05', LeitorDuracao::formatar(300));
        $this->assertSame('1:21', LeitorDuracao::formatar(4859)); // 1:20:59 → ao minuto mais próximo
        $this->assertSame('', LeitorDuracao::formatar(null));
    }

    public function test_arredondamento_de_faturacao(): void
    {
        $quinze = 15;

        $this->assertSame(900, ModoArredondamento::Cima->aplicar(1, $quinze));
        $this->assertSame(900, ModoArredondamento::Cima->aplicar(900, $quinze));
        $this->assertSame(1800, ModoArredondamento::Cima->aplicar(901, $quinze));

        $this->assertSame(0, ModoArredondamento::Proximo->aplicar(449, $quinze));
        $this->assertSame(900, ModoArredondamento::Proximo->aplicar(450, $quinze)); // metade exata sobe
        $this->assertSame(900, ModoArredondamento::Proximo->aplicar(1349, $quinze));

        $this->assertSame(0, ModoArredondamento::Baixo->aplicar(899, $quinze));
        $this->assertSame(900, ModoArredondamento::Baixo->aplicar(1799, $quinze));

        $this->assertSame(1234, ModoArredondamento::Cima->aplicar(1234, 0)); // 0 min = sem arredondamento
        $this->assertSame(0, ModoArredondamento::Cima->aplicar(0, $quinze));
    }
}

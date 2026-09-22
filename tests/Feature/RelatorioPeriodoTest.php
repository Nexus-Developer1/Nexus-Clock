<?php

namespace Tests\Feature;

use App\Services\Tempos\Relatorios\FiltrosRelatorio;
use App\Services\Tempos\Relatorios\PeriodoRelatorio;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Períodos dos relatórios (mês, trimestre, ano, personalizado), navegação e leitura do URL.
class RelatorioPeriodoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 10:00:00');
    }

    private function intervalo(PeriodoRelatorio $p): string
    {
        return $p->de->toDateString().'→'.$p->ate->toDateString();
    }

    public function test_mes_trimestre_e_ano_a_partir_da_referencia(): void
    {
        $this->assertSame('2026-02-01→2026-02-28', $this->intervalo(PeriodoRelatorio::criar('mes', '2026-02')));
        $this->assertSame('2026-07-01→2026-09-30', $this->intervalo(PeriodoRelatorio::criar('trimestre', '2026-T3')));
        $this->assertSame('2025-01-01→2025-12-31', $this->intervalo(PeriodoRelatorio::criar('ano', '2025')));
        $this->assertSame('2026-03-05→2026-03-20', $this->intervalo(PeriodoRelatorio::criar('personalizado', null, '2026-03-05', '2026-03-20')));
    }

    public function test_sem_referencia_ou_invalido_usa_o_corrente(): void
    {
        $this->assertSame('2026-09-01→2026-09-30', $this->intervalo(PeriodoRelatorio::criar(null)));
        $this->assertSame('2026-09-01→2026-09-30', $this->intervalo(PeriodoRelatorio::criar('mes', '2026-13')));
        $this->assertSame('2026-07-01→2026-09-30', $this->intervalo(PeriodoRelatorio::criar('trimestre', 'lixo')));
        $this->assertSame('2026-09-01→2026-09-30', $this->intervalo(PeriodoRelatorio::criar('semestre', '2026')));
        // Personalizado com as datas trocadas: corrige a ordem.
        $this->assertSame('2026-03-05→2026-03-20', $this->intervalo(PeriodoRelatorio::criar('personalizado', null, '2026-03-20', '2026-03-05')));
    }

    public function test_anterior_e_seguinte(): void
    {
        $this->assertSame('2026-01-01→2026-01-31', $this->intervalo(PeriodoRelatorio::criar('mes', '2026-03')->anterior()->anterior()));
        $this->assertSame('2027-01-01→2027-03-31', $this->intervalo(PeriodoRelatorio::criar('trimestre', '2026-T4')->seguinte()));
        $this->assertSame('2025-01-01→2025-12-31', $this->intervalo(PeriodoRelatorio::criar('ano', '2026')->anterior()));
        $this->assertSame('2026-03-11→2026-03-20', $this->intervalo(PeriodoRelatorio::criar('personalizado', null, '2026-03-01', '2026-03-10')->seguinte()));
    }

    public function test_rotulos_em_portugues(): void
    {
        $this->assertSame('setembro de 2026', PeriodoRelatorio::criar('mes', '2026-09')->rotulo());
        $this->assertSame('3.º trimestre de 2026', PeriodoRelatorio::criar('trimestre', '2026-T3')->rotulo());
        $this->assertSame('2026', PeriodoRelatorio::criar('ano', '2026')->rotulo());
        $this->assertSame('05/03/2026 a 20/03/2026', PeriodoRelatorio::criar('personalizado', null, '2026-03-05', '2026-03-20')->rotulo());
    }

    public function test_filtros_vao_e_voltam_do_url(): void
    {
        $query = ['periodo' => 'trimestre', 'ref' => '2026-T2', 'cliente' => '4', 'contrato' => '7', 'tecnico' => '9', 'faturavel' => 'nao', 'etiqueta' => 'remoto'];

        $filtros = FiltrosRelatorio::doQuery($query);

        $this->assertSame([4, 7, 9, false, 'remoto'], [$filtros->clienteId, $filtros->contratoId, $filtros->tecnicoId, $filtros->faturavel, $filtros->etiqueta]);
        $this->assertSame(['periodo' => 'trimestre', 'ref' => '2026-T2', 'cliente' => 4, 'contrato' => 7, 'tecnico' => 9, 'faturavel' => 'nao', 'etiqueta' => 'remoto'], $filtros->paraQuery());
        $this->assertNull(FiltrosRelatorio::doQuery(['tecnico' => '1; drop table'])->tecnicoId);
    }
}

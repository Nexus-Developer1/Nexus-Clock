<?php

namespace Tests\Feature;

use App\Jobs\AtualizarConsumoContratos;
use App\Models\Cliente;
use App\Models\ConsumoContratoPeriodo;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\CalculadorHorasIncluidas;
use App\Services\Tempos\PeriodoConsumo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Regras 8–10 (docs/modulo-tempos.md §2): consumo sem arredondamento, excedente, transporte de
// horas (só se transita e só dentro da validade), mudança de horas incluídas a meio do ano, e a
// view materializada a bater certo com o cálculo.
class TempoConsumoContratoTest extends TestCase
{
    use RefreshDatabase;

    private const HORA = 3600;

    private CalculadorHorasIncluidas $calculador;

    private User $tecnico;

    private Cliente $cliente;

    private Contrato $contrato;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculador = app(CalculadorHorasIncluidas::class);
        $this->tecnico = $this->tecnico();
        $this->cliente = $this->cliente();
        $this->contrato = $this->contrato($this->cliente);
    }

    // --- Regra 8: consumo = faturáveis do contrato no período, sem arredondar ---

    public function test_regra8_consumo_soma_so_faturaveis_do_contrato_sem_arredondar(): void
    {
        $horas = $this->horasIncluidas('mensal', 10, '2026-01-01');
        $outroContrato = $this->contrato($this->cliente);

        $this->horas('2026-01-05', 3 * self::HORA + 7 * 60 + 13);                      // conta
        $this->horas('2026-01-06', 50 * 60 + 1);                                       // conta
        $this->horas('2026-01-07', 2 * self::HORA, ['faturavel' => false]);             // não faturável
        $this->horas('2026-01-08', 5 * self::HORA, ['contrato_id' => $outroContrato->id]); // outro contrato
        $this->horas('2026-01-09', 4 * self::HORA)->delete();                           // apagado
        $this->horas('2026-02-01', 1 * self::HORA);                                     // outro período
        RegistoTempo::create(['tecnico_id' => $this->tecnico->id, 'cliente_id' => $this->cliente->id, 'contrato_id' => $this->contrato->id, 'inicio' => '2026-01-10 09:00:00+00']); // cronómetro a correr

        $janeiro = $this->periodo('2026-01-15');

        $this->assertSame(3 * self::HORA + 7 * 60 + 13 + 50 * 60 + 1, $janeiro->faturavelSeg);
        $this->assertSame(2 * self::HORA, $janeiro->naoFaturavelSeg);
        $this->assertSame(['faturavel_seg' => 14234, 'nao_faturavel_seg' => 7200], $this->calculador->consumo($this->contrato, '2026-01-01', '2026-01-31'));

        // O arredondamento só existe para faturação: 3:07:13 → 3:15 e 0:50:01 → 1:00 (15 min para cima).
        $this->assertSame(4 * self::HORA + 15 * 60, $this->calculador->consumoParaFaturacao($horas, '2026-01-01', '2026-01-31'));
    }

    public function test_regra8_dia_do_registo_e_o_de_lisboa(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-07-01');

        // 31/07 às 23:30 UTC = 01/08 às 00:30 em Lisboa → conta em agosto.
        RegistoTempo::create(['tecnico_id' => $this->tecnico->id, 'cliente_id' => $this->cliente->id, 'contrato_id' => $this->contrato->id,
            'inicio' => '2026-07-31 23:30:00+00', 'fim' => '2026-08-01 00:30:00+00', 'duracao_seg' => self::HORA]);

        $this->assertSame(0, $this->periodo('2026-07-15')->faturavelSeg);
        $this->assertSame(self::HORA, $this->periodo('2026-08-15')->faturavelSeg);
    }

    // --- Regra 9: excedente = max(0, consumo − incluídas − transportado) ---

    public function test_regra9_excedente_sem_transporte(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01');
        $this->horas('2026-01-10', 12 * self::HORA);
        $this->horas('2026-02-10', 4 * self::HORA);

        $janeiro = $this->periodo('2026-01-01');
        $fevereiro = $this->periodo('2026-02-01');

        $this->assertSame(2 * self::HORA, $janeiro->excedenteSeg);
        $this->assertSame(0, $janeiro->sobraSeg);
        $this->assertSame(0, $fevereiro->excedenteSeg);
        $this->assertSame(0, $fevereiro->transportadoSeg); // não transita: as 6h que sobraram perdem-se
        $this->assertSame(6 * self::HORA, $fevereiro->disponivelSeg());
    }

    // --- Regra 10: transporte só se transita, e só dentro da validade ---

    public function test_regra10_horas_nao_usadas_transitam_e_abatem_ao_excedente(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01', null, ['transita' => true]);
        $this->horas('2026-01-10', 6 * self::HORA);  // sobram 4h
        $this->horas('2026-02-10', 13 * self::HORA); // 10 + 4 disponíveis → sem excedente, sobra 1h
        $this->horas('2026-03-10', 12 * self::HORA); // 10 + 1 disponíveis → 1h de excedente

        [$jan, $fev, $mar] = $this->calculador->resumo($this->contrato, '2026-01-01', '2026-03-31');

        $this->assertSame([0, 4 * self::HORA, 0], [$jan->transportadoSeg, $jan->sobraSeg, $jan->excedenteSeg]);
        $this->assertSame([4 * self::HORA, 1 * self::HORA, 0], [$fev->transportadoSeg, $fev->sobraSeg, $fev->excedenteSeg]);
        $this->assertSame([1 * self::HORA, 0, 1 * self::HORA], [$mar->transportadoSeg, $mar->sobraSeg, $mar->excedenteSeg]);
    }

    public function test_regra10_transporte_acumula_ao_longo_de_varios_periodos(): void
    {
        $this->horasIncluidas('mensal', 5, '2026-01-01', null, ['transita' => true]);
        $this->horas('2026-04-10', 18 * self::HORA); // jan–mar sem consumo: 15h transportadas + 5h do mês

        $abril = $this->periodo('2026-04-01');

        $this->assertSame(15 * self::HORA, $abril->transportadoSeg);
        $this->assertSame(0, $abril->excedenteSeg);
        $this->assertSame(2 * self::HORA, $abril->sobraSeg);
    }

    public function test_regra10_transporte_nao_passa_para_novas_horas_incluidas(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01', '2026-06-30', ['transita' => true]); // junho acaba com 60h por usar
        $this->horasIncluidas('mensal', 8, '2026-07-01', null, ['transita' => true]);
        $this->horas('2026-07-10', 12 * self::HORA);

        $junho = $this->periodo('2026-06-01');
        $julho = $this->periodo('2026-07-01');

        $this->assertSame(60 * self::HORA, $junho->sobraSeg);
        $this->assertSame(0, $julho->transportadoSeg);
        $this->assertSame(4 * self::HORA, $julho->excedenteSeg);
    }

    public function test_regra10_resumo_a_meio_do_ano_ja_traz_o_transporte_desde_o_inicio(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01', null, ['transita' => true]);
        $this->horas('2026-01-10', 7 * self::HORA); // sobram 3h em janeiro

        $resumo = $this->calculador->resumo($this->contrato, '2026-02-01', '2026-02-28');

        $this->assertCount(1, $resumo);
        $this->assertSame(3 * self::HORA, $resumo[0]->transportadoSeg);
    }

    // --- Mudança de horas incluídas a meio do ano / a meio do mês ---

    public function test_mudanca_a_meio_do_mes_parte_o_periodo_pela_validade(): void
    {
        $antigas = $this->horasIncluidas('mensal', 10, '2026-01-01', '2026-03-15');
        $novas = $this->horasIncluidas('mensal', 20, '2026-03-16');
        $this->horas('2026-03-10', 11 * self::HORA);
        $this->horas('2026-03-20', 15 * self::HORA);

        $resumo = $this->calculador->resumo($this->contrato, '2026-03-01', '2026-03-31');

        $this->assertCount(2, $resumo);
        [$primeiraMetade, $segundaMetade] = $resumo;

        $this->assertSame([$antigas->id, '2026-03-01', '2026-03-15'], [$primeiraMetade->horasIncluidas->id, $primeiraMetade->inicio->toDateString(), $primeiraMetade->fim->toDateString()]);
        $this->assertSame(1 * self::HORA, $primeiraMetade->excedenteSeg);
        $this->assertSame([$novas->id, '2026-03-16', '2026-03-31'], [$segundaMetade->horasIncluidas->id, $segundaMetade->inicio->toDateString(), $segundaMetade->fim->toDateString()]);
        $this->assertSame(0, $segundaMetade->excedenteSeg);
        $this->assertSame($novas->id, $this->calculador->ativaEm($this->contrato, '2026-03-16')->id);
        $this->assertSame($antigas->id, $this->calculador->ativaEm($this->contrato, '2026-03-15')->id);
    }

    public function test_periodos_trimestral_anual_e_total_seguem_o_calendario_cortados_pela_validade(): void
    {
        $datas = fn (array $periodos) => array_map(fn ($p) => $p[0]->toDateString().'→'.$p[1]->toDateString(), $periodos);

        $trimestral = $this->horasIncluidas('trimestral', 30, '2026-02-10', '2026-09-30');
        $this->assertSame(['2026-02-10→2026-03-31', '2026-04-01→2026-06-30', '2026-07-01→2026-09-30'], $datas($this->calculador->periodos($trimestral, '2026-12-31')));

        $anual = $this->horasIncluidas('anual', 100, '2025-06-01', null, [], $this->contrato($this->cliente));
        $this->assertSame(['2025-06-01→2025-12-31', '2026-01-01→2026-12-31'], $datas($this->calculador->periodos($anual, '2026-09-14')));

        $total = $this->horasIncluidas('total', 50, '2026-01-01', '2027-12-31', [], $this->contrato($this->cliente));
        $this->assertSame(['2026-01-01→2027-12-31'], $datas($this->calculador->periodos($total, '2026-09-14')));

        // Mensal em aberto: o último período é o mês inteiro que contém a data pedida.
        $mensal = $this->horasIncluidas('mensal', 10, '2026-08-20', null, [], $this->contrato($this->cliente));
        $this->assertSame(['2026-08-20→2026-08-31', '2026-09-01→2026-09-30'], $datas($this->calculador->periodos($mensal, '2026-09-14')));
    }

    // --- View materializada ---

    public function test_view_materializada_bate_certo_com_o_calculo_e_inclui_meses_sem_horas_incluidas(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-03-16', '2026-05-31');
        $this->horas('2026-03-10', 2 * self::HORA);                        // antes da validade → linha sem horas incluídas
        $this->horas('2026-03-20', 11 * self::HORA);
        $this->horas('2026-04-02', 3 * self::HORA, ['faturavel' => false]);
        $this->horas('2026-04-03', 4 * self::HORA);

        (new AtualizarConsumoContratos)->handle();

        $linhas = ConsumoContratoPeriodo::where('contrato_id', $this->contrato->id)->orderBy('periodo_inicio')->orderBy('periodo_fim')->get()
            ->map(fn ($l) => [$l->periodo_inicio->toDateString(), $l->periodo_fim->toDateString(), $l->contrato_horas_incluidas_id !== null, $l->incluidas_seg, $l->faturavel_seg, $l->nao_faturavel_seg, $l->excedente_sem_transporte_seg])
            ->all();

        $this->assertSame([
            ['2026-03-01', '2026-03-31', false, 0, 2 * self::HORA, 0, 2 * self::HORA],
            ['2026-03-16', '2026-03-31', true, 10 * self::HORA, 11 * self::HORA, 0, 1 * self::HORA],
            ['2026-04-01', '2026-04-30', true, 10 * self::HORA, 4 * self::HORA, 3 * self::HORA, 0],
            ['2026-05-01', '2026-05-31', true, 10 * self::HORA, 0, 0, 0],
        ], $linhas);

        foreach ($this->calculador->resumo($this->contrato, '2026-03-01', '2026-05-31') as $periodo) {
            $linha = ConsumoContratoPeriodo::where('contrato_id', $this->contrato->id)->where('periodo_inicio', $periodo->inicio->toDateString())->whereNotNull('contrato_horas_incluidas_id')->sole();
            $this->assertSame($periodo->faturavelSeg, $linha->faturavel_seg);
            $this->assertSame($periodo->naoFaturavelSeg, $linha->nao_faturavel_seg);
        }
    }

    public function test_view_so_muda_quando_e_refrescada(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01', '2026-01-31');
        (new AtualizarConsumoContratos)->handle();
        $this->horas('2026-01-10', self::HORA);

        $this->assertSame(0, ConsumoContratoPeriodo::where('contrato_id', $this->contrato->id)->value('faturavel_seg'));

        AtualizarConsumoContratos::dispatchSync();

        $this->assertSame(self::HORA, ConsumoContratoPeriodo::where('contrato_id', $this->contrato->id)->value('faturavel_seg'));
    }

    public function test_refrescamento_esta_agendado_de_noite(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('tempos-consumo-contratos');
    }

    // --- auxiliares ---

    /** @param array<string, mixed> $extra */
    private function horasIncluidas(string $periodo, float $horas, string $de, ?string $ate = null, array $extra = [], ?Contrato $contrato = null): ContratoHorasIncluidas
    {
        return ContratoHorasIncluidas::create(array_merge([
            'contrato_id' => ($contrato ?? $this->contrato)->id,
            'periodo' => $periodo,
            'horas_incluidas' => $horas,
            'valido_de' => $de,
            'valido_ate' => $ate,
        ], $extra));
    }

    /** @param array<string, mixed> $extra */
    private function horas(string $dia, int $segundos, array $extra = []): RegistoTempo
    {
        return $this->registo($this->tecnico, $this->cliente, $dia, $segundos, array_merge(['contrato_id' => $this->contrato->id], $extra));
    }

    private function periodo(string $dia): PeriodoConsumo
    {
        return $this->calculador->periodoDe($this->contrato, $dia) ?? $this->fail("Sem período de horas incluídas em {$dia}.");
    }
}

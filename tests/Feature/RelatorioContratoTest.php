<?php

namespace Tests\Feature;

use App\Jobs\AtualizarConsumoContratos;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Models\User;
use App\Services\Tempos\CalculadorHorasIncluidas;
use App\Services\Tempos\Relatorios\FiltrosRelatorio;
use App\Services\Tempos\Relatorios\PeriodoRelatorio;
use App\Services\Tempos\Relatorios\RelatorioContrato;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 3 — relatório de consumo por contrato. Regras 8–10 lidas da view materializada, com
// transporte de horas e mudança das horas incluídas a meio do ano; detalhe por técnico e por
// intervenção com filtros; frescura da view; acesso.
class RelatorioContratoTest extends TestCase
{
    use RefreshDatabase;

    private const H = 3600;

    private User $ana;

    private User $bruno;

    private Cliente $cliente;

    private Contrato $contrato;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->bruno = $this->tecnico();
        $this->bruno->update(['nome' => 'Bruno Costa']);
        $this->cliente = $this->cliente('Hospital Exemplo');
        $this->contrato = $this->contrato($this->cliente, 'CT-2026-001');
    }

    // --- Regras 8–10 a partir da view ---

    public function test_regra10_transporte_ao_longo_dos_meses_lido_da_view(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01', null, true);
        $this->horas($this->ana, '2026-01-12', 6 * self::H);  // sobram 4
        $this->horas($this->bruno, '2026-02-10', 13 * self::H); // 10+4 → sem excedente, sobra 1
        $this->horas($this->ana, '2026-03-05', 12 * self::H);   // 10+1 → excedente 1

        $marco = $this->gerar('mes', '2026-03')['periodos'];

        $this->assertCount(1, $marco);
        $this->assertSame([10 * self::H, 1 * self::H, 12 * self::H, 1 * self::H, 0], [$marco[0]['incluidas'], $marco[0]['transportado'], $marco[0]['faturavel'], $marco[0]['excedente'], $marco[0]['disponivel']]);
        $this->assertSame(109, $marco[0]['percentagem']); // 12 de 11 disponíveis
    }

    public function test_regra8_consumo_do_periodo_sem_arredondar_e_nao_faturavel_a_parte(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01');
        $this->horas($this->ana, '2026-04-02', 3 * self::H + 7 * 60 + 13);
        $this->horas($this->ana, '2026-04-03', 2 * self::H, ['faturavel' => false]);

        $abril = $this->gerar('mes', '2026-04')['periodos'][0];

        $this->assertSame(3 * self::H + 7 * 60 + 13, $abril['faturavel']);
        $this->assertSame(2 * self::H, $abril['nao_faturavel']);
        $this->assertSame(0, $abril['excedente']);
    }

    public function test_mudanca_de_horas_incluidas_a_meio_do_ano_recomeca_o_transporte(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01', '2026-06-30', true);
        // Com fim à vista: a view gera períodos em aberto só até à data de HOJE da base de dados.
        $this->horasIncluidas('mensal', 20, '2026-07-01', '2026-12-31', true);
        $this->horas($this->ana, '2026-06-10', 2 * self::H);   // junho: sobram 8 (+ o que vem de trás)
        $this->horas($this->ana, '2026-07-10', 15 * self::H);  // julho: 25 h com 20 incluídas, sem transporte → excedente 5
        $this->horas($this->bruno, '2026-07-10', 10 * self::H);

        $ano = $this->gerar('ano', '2026');
        $julho = collect($ano['periodos'])->first(fn ($p) => $p['inicio']->toDateString() === '2026-07-01');
        $junho = collect($ano['periodos'])->first(fn ($p) => $p['inicio']->toDateString() === '2026-06-01');

        $this->assertCount(12, $ano['periodos']);
        $this->assertSame(58 * self::H, $junho['sobra']);   // 5 meses sem consumo (50) + junho 8
        $this->assertSame(0, $julho['transportado']);
        $this->assertSame(5 * self::H, $julho['excedente']);
        $this->assertSame(5 * self::H, $ano['totais']['excedente']);
        $this->assertSame(180 * self::H, $ano['totais']['incluidas']); // 6×10 + 6×20
    }

    public function test_trimestral_no_relatorio_mensal_mostra_o_trimestre_inteiro(): void
    {
        $this->horasIncluidas('trimestral', 30, '2026-01-01');
        $this->horas($this->ana, '2026-04-10', 20 * self::H);
        $this->horas($this->ana, '2026-05-10', 15 * self::H);

        $maio = $this->gerar('mes', '2026-05')['periodos'];

        $this->assertCount(1, $maio);
        $this->assertSame('2026-04-01→2026-06-30', $maio[0]['inicio']->toDateString().'→'.$maio[0]['fim']->toDateString());
        $this->assertSame(5 * self::H, $maio[0]['excedente']);
    }

    public function test_horas_fora_das_horas_incluidas_sao_todas_excedente(): void
    {
        $this->horas($this->ana, '2026-05-10', 4 * self::H);
        $this->horas($this->ana, '2026-05-11', 1 * self::H, ['faturavel' => false]);

        $maio = $this->gerar('mes', '2026-05');

        $this->assertSame('sem_incluidas', $maio['periodos'][0]['tipo']);
        $this->assertSame(4 * self::H, $maio['totais']['excedente']);
        $this->assertNull($maio['totais']['percentagem']);
    }

    public function test_view_e_calculo_direto_dao_o_mesmo_num_ano_com_mudancas(): void
    {
        $this->horasIncluidas('mensal', 8, '2026-01-01', '2026-04-15', true);
        $this->horasIncluidas('trimestral', 30, '2026-04-16', '2026-09-30', true);
        $this->horasIncluidas('mensal', 12, '2026-10-01', null, false);
        mt_srand(7);
        for ($d = CarbonImmutable::parse('2026-01-01'); $d->lte(CarbonImmutable::parse('2026-12-15')); $d = $d->addDays(3)) {
            $this->horas(mt_rand(0, 1) ? $this->ana : $this->bruno, $d->toDateString(), mt_rand(1, 16) * 15 * 60, ['faturavel' => mt_rand(1, 5) > 1]);
        }

        $daView = $this->gerar('ano', '2026')['periodos'];
        $diretos = app(CalculadorHorasIncluidas::class)->resumo($this->contrato, '2026-01-01', '2026-12-31');

        $this->assertSame(
            array_map(fn ($p) => [$p->inicio->toDateString(), $p->fim->toDateString(), $p->incluidasSeg, $p->transportadoSeg, $p->faturavelSeg, $p->naoFaturavelSeg, $p->excedenteSeg], $diretos),
            array_map(fn ($p) => [$p['inicio']->toDateString(), $p['fim']->toDateString(), $p['incluidas'], $p['transportado'], $p['faturavel'], $p['nao_faturavel'], $p['excedente']], $daView),
        );
        $this->assertSame('2026-12-31', end($daView)['fim']->toDateString()); // mês em curso inteiro, não cortado em "hoje"
    }

    public function test_periodos_do_relatorio_nao_consultam_os_registos(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01');
        $this->horas($this->ana, '2026-03-10', self::H);
        (new AtualizarConsumoContratos)->handle();

        DB::enableQueryLog();
        app(RelatorioContrato::class)->periodos($this->contrato, PeriodoRelatorio::criar('ano', '2026'));
        $consultas = collect(DB::getQueryLog())->pluck('query');

        $this->assertTrue($consultas->contains(fn ($q) => str_contains($q, 'contrato_consumo_periodo')));
        $this->assertFalse($consultas->contains(fn ($q) => str_contains($q, 'registos_tempo')));
    }

    // --- Detalhe com filtros ---

    public function test_detalhe_por_tecnico_e_intervencao_respeita_os_filtros(): void
    {
        $this->horasIncluidas('mensal', 10, '2026-01-01');
        $intervencao = $this->intervencao($this->cliente, $this->contrato);
        $this->horas($this->ana, '2026-03-02', 2 * self::H, ['intervencao_id' => $intervencao->id, 'etiquetas' => ['deslocação']]);
        $this->horas($this->ana, '2026-03-03', 1 * self::H, ['faturavel' => false]);
        $this->horas($this->bruno, '2026-03-04', 4 * self::H, ['intervencao_id' => $intervencao->id]);

        $tudo = $this->gerar('mes', '2026-03');
        $this->assertSame([['Bruno Costa', 4 * self::H], ['Ana Martins', 3 * self::H]], array_map(fn ($l) => [$l['nome'], $l['total']], $tudo['porTecnico']));
        $this->assertSame([6 * self::H, 1 * self::H], array_column($tudo['porIntervencao'], 'total'));
        $this->assertSame('Sem intervenção', $tudo['porIntervencao'][1]['nome']);

        $soAna = $this->gerar('mes', '2026-03', ['tecnico' => $this->ana->id, 'faturavel' => 'sim']);
        $this->assertSame([['Ana Martins', 2 * self::H]], array_map(fn ($l) => [$l['nome'], $l['total']], $soAna['porTecnico']));
        // O consumo das horas incluídas continua a ser o do contrato inteiro.
        $this->assertSame(6 * self::H, $soAna['periodos'][0]['faturavel']);

        $comEtiqueta = $this->gerar('mes', '2026-03', ['etiqueta' => 'deslocação']);
        $this->assertSame([2 * self::H], array_column($comEtiqueta['porTecnico'], 'total'));
    }

    // --- auxiliares ---

    private function horasIncluidas(string $periodo, float $horas, string $de, ?string $ate = null, bool $transita = false): ContratoHorasIncluidas
    {
        return ContratoHorasIncluidas::create([
            'contrato_id' => $this->contrato->id, 'periodo' => $periodo, 'horas_incluidas' => $horas,
            'transita' => $transita, 'valido_de' => $de, 'valido_ate' => $ate,
        ]);
    }

    private function horas(User $tecnico, string $dia, int $segundos, array $extra = []): void
    {
        $this->registo($tecnico, $this->cliente, $dia, $segundos, ['contrato_id' => $this->contrato->id] + $extra);
    }

    /** Refresca a view e gera o relatório. */
    private function gerar(string $periodo, string $ref, array $query = []): array
    {
        (new AtualizarConsumoContratos)->handle();

        return app(RelatorioContrato::class)->gerar($this->contrato, FiltrosRelatorio::doQuery(['periodo' => $periodo, 'ref' => $ref] + $query));
    }
}

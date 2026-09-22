<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Models\ExportacaoFaturacao;
use App\Models\RegistoTempo;
use App\Models\Tarifa;
use App\Models\User;
use App\Services\Tempos\Faturacao\CalculadorFaturacao;
use App\Services\Tempos\Faturacao\ExportadorFaturacao;
use App\Services\Tempos\Faturacao\FechoMensal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Fase 5 — regra 13 (o que se fatura: faturável, fechado, por faturar; só o excedente nos contratos com
// horas incluídas, tudo nos que não têm), arredondamento cima/próximo/baixo, excedente de trimestre
// faturado à medida, exportação (CSV global, PDF por cliente) e reexportação só com confirmação.
class FaturacaoTest extends TestCase
{
    use RefreshDatabase;

    private const H = 3600;

    private const M = 60;

    private User $admin;

    private User $ana;

    private Cliente $cliente;

    private Contrato $contrato;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->cliente = $this->cliente('Hospital Exemplo');
        $this->contrato = $this->contrato($this->cliente, 'CT-2026-001');
        Tarifa::create(['preco_hora_cent' => 4000, 'valido_de' => '2026-01-01']); // global 40 €/h
        Tarifa::create(['ambito_tipo' => 'contrato', 'ambito_id' => $this->contrato->id, 'preco_hora_cent' => 6000, 'valido_de' => '2026-01-01']);
    }

    // --- Regra 13 ---

    public function test_regra13_contrato_com_horas_incluidas_fatura_so_o_excedente_arredondado(): void
    {
        $this->horasIncluidas('mensal', 10, ['arredondamento_min' => 15, 'arredondamento_modo' => 'cima']);
        $this->horas('2026-08-03', 5 * self::H + 1);          // → 5:15
        $this->horas('2026-08-04', 5 * self::H + 20 * self::M); // → 5:30
        $this->horas('2026-08-05', 2 * self::H, ['faturavel' => false]); // não entra
        $this->fechar('2026-08');

        $linhas = $this->calcular('2026-08')['linhas'];

        $this->assertCount(1, $linhas);
        $this->assertSame('Horas excedentes contrato CT-2026-001 — agosto de 2026', $linhas[0]['descricao']);
        $this->assertSame(45 * self::M, $linhas[0]['segundos']);   // 10:45 − 10:00
        $this->assertSame(6000, $linhas[0]['preco_hora_cent']);
        $this->assertSame(4500, $linhas[0]['valor_cent']);        // 0,75 h × 60 €
    }

    public function test_arredondamento_para_cima_ao_mais_proximo_e_para_baixo(): void
    {
        $esperado = ['cima' => 2 * self::H + 30 * self::M, 'proximo' => 2 * self::H + 15 * self::M, 'baixo' => 2 * self::H];

        foreach ($esperado as $modo => $segundos) {
            ContratoHorasIncluidas::query()->forceDelete();
            RegistoTempo::query()->forceDelete();
            $this->horasIncluidas('mensal', 0, ['arredondamento_min' => 15, 'arredondamento_modo' => $modo]);
            $this->horas('2026-08-03', 1 * self::H + 7 * self::M);  // cima 1:15 · próximo 1:00 · baixo 1:00
            $this->horas('2026-08-04', 1 * self::H + 8 * self::M);  // cima 1:15 · próximo 1:15 · baixo 1:00
            RegistoTempo::query()->update(['fechado_em' => now()]);

            $this->assertSame($segundos, $this->calcular('2026-08')['linhas'][0]['segundos'], $modo);
        }
    }

    public function test_regra13_contrato_sem_horas_incluidas_fatura_tudo_a_15_min_e_por_preco(): void
    {
        $bruno = $this->tecnico();
        $semHoras = $this->contrato($this->cliente, 'CT-2026-002');
        Tarifa::create(['ambito_tipo' => 'tecnico', 'ambito_id' => $bruno->id, 'preco_hora_cent' => 5000, 'valido_de' => '2026-01-01']);

        $this->registo($this->ana, $this->cliente, '2026-08-03', 50 * self::M, ['contrato_id' => $semHoras->id]); // 1:00, global 40
        $this->registo($bruno, $this->cliente, '2026-08-04', 2 * self::H + 1, ['contrato_id' => $semHoras->id]);   // 2:15, técnico 50
        $this->fechar('2026-08');

        $linhas = collect($this->calcular('2026-08')['linhas'])->sortBy('preco_hora_cent')->values();

        $this->assertSame([['Horas contrato CT-2026-002 — agosto de 2026', self::H, 4000, 4000], ['Horas contrato CT-2026-002 — agosto de 2026', 2 * self::H + 15 * self::M, 5000, 11250]],
            $linhas->map(fn ($l) => [$l['descricao'], $l['segundos'], $l['preco_hora_cent'], $l['valor_cent']])->all());
    }

    public function test_regra13_so_registos_fechados_e_por_faturar(): void
    {
        $this->horas('2026-08-03', 2 * self::H);
        $semContrato = $this->registo($this->ana, $this->cliente, '2026-08-04', self::H);

        // Aberto: a pré-visualização vê tudo; o cálculo para exportar não vê nada.
        $this->assertCount(2, app(CalculadorFaturacao::class)->calcular(CarbonImmutable::parse('2026-08-01'), soFechados: false)['linhas']);
        $this->assertSame([], $this->calcular('2026-08')['linhas']);

        $this->fechar('2026-08');
        $semContrato->forceFill(['faturado_em' => now()])->save();

        $linhas = $this->calcular('2026-08')['linhas'];
        $this->assertCount(1, $linhas);
        $this->assertSame('Horas contrato CT-2026-001 — agosto de 2026', $linhas[0]['descricao']);
    }

    public function test_excedente_de_trimestre_faturado_a_medida_sem_faturar_duas_vezes(): void
    {
        $this->horasIncluidas('trimestral', 30, ['arredondamento_min' => 0]);
        $this->horas('2026-07-10', 20 * self::H);
        $this->horas('2026-07-11', 15 * self::H); // julho: 35 de 30 → 5
        $this->horas('2026-08-10', 4 * self::H);  // agosto: 39 → mais 4
        $this->horas('2026-09-10', 1 * self::H);  // setembro: 40 → mais 1

        foreach (['2026-07' => 5, '2026-08' => 4, '2026-09' => 1] as $mes => $horas) {
            $this->fechar($mes);
            $exportacao = app(ExportadorFaturacao::class)->exportar($this->admin, CarbonImmutable::parse($mes.'-01'));
            $this->assertSame($horas * self::H, $exportacao->linhas[0]['segundos'], $mes);
        }

        $this->assertSame(10 * self::H, (int) ExportacaoFaturacao::emVigor()->sum('segundos'));
    }

    public function test_excedente_nao_faturavel_nao_se_exporta_e_tarifa_do_excedente_e_usada(): void
    {
        $tarifaExcedente = Tarifa::create(['ambito_tipo' => 'contrato', 'ambito_id' => $this->contrato->id, 'preco_hora_cent' => 9000, 'valido_de' => '2020-01-01', 'valido_ate' => '2020-12-31']);
        $h = $this->horasIncluidas('mensal', 1, ['arredondamento_min' => 0, 'tarifa_excedente_id' => $tarifaExcedente->id]);
        $this->horas('2026-08-03', 3 * self::H);
        $this->fechar('2026-08');

        $this->assertSame([2 * self::H, 9000, 18000], [$this->calcular('2026-08')['linhas'][0]['segundos'], $this->calcular('2026-08')['linhas'][0]['preco_hora_cent'], $this->calcular('2026-08')['linhas'][0]['valor_cent']]);

        $h->update(['excedente_faturavel' => false]);
        $calculo = $this->calcular('2026-08');
        $this->assertSame([], $calculo['linhas']);
        $this->assertFalse($calculo['resumo'][0]['linha']['faturavel']); // aparece no resumo, não se exporta
    }

    public function test_sem_tarifa_nao_deixa_exportar(): void
    {
        Tarifa::query()->forceDelete();
        $this->registo($this->ana, $this->cliente, '2026-08-03', self::H);
        $this->fechar('2026-08');

        $this->assertStringContainsString('Sem tarifa para «Horas sem contrato — agosto de 2026»', $this->calcular('2026-08')['avisos'][0]);

        $this->expectException(ValidationException::class);
        app(ExportadorFaturacao::class)->exportar($this->admin, CarbonImmutable::parse('2026-08-01'));
    }

    // --- Exportação ---

    public function test_exportar_marca_os_registos_e_reexportar_exige_confirmacao(): void
    {
        $this->horasIncluidas('mensal', 1, ['arredondamento_min' => 0]);
        $excedente = $this->horas('2026-08-03', 3 * self::H);
        $semContrato = $this->registo($this->ana, $this->cliente, '2026-08-04', self::H);
        $exportador = app(ExportadorFaturacao::class);

        try {
            $exportador->exportar($this->admin, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Não devia exportar um mês aberto.');
        } catch (ValidationException $e) {
            $this->assertSame('Feche o mês antes de exportar a faturação.', $e->errors()['exportar'][0]);
        }

        $this->fechar('2026-08');
        $this->actingAs($this->admin);
        $primeira = $exportador->exportar($this->admin, CarbonImmutable::parse('2026-08-01'));

        $this->assertSame([2, 16000, 2], [count($primeira->linhas), $primeira->total_cent, $primeira->registos]); // 2 h × 60 + 1 h × 40
        $this->assertSame($primeira->id, $excedente->fresh()->exportacao_faturacao_id);
        $this->assertNotNull($semContrato->fresh()->faturado_em);

        try {
            $exportador->exportar($this->admin, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Reexportar sem confirmação devia ser recusado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Este mês já foi exportado em', $e->errors()['exportar'][0]);
        }

        $segunda = $exportador->exportar($this->admin, CarbonImmutable::parse('2026-08-01'), confirmarReexportacao: true);

        $this->assertNotNull($primeira->fresh()->anulada_em);
        $this->assertSame(16000, $segunda->total_cent);
        $this->assertSame($segunda->id, $excedente->fresh()->exportacao_faturacao_id);
        $this->assertSame(1, ExportacaoFaturacao::emVigor()->count());
        $this->assertSame(['tempo_mes_fechado', 'tempo_faturacao_exportada', 'tempo_faturacao_reexportada'], Auditoria::orderBy('id')->pluck('acao')->all());
    }

    // --- auxiliares ---

    private function horasIncluidas(string $periodo, float $horas, array $extra = []): ContratoHorasIncluidas
    {
        return ContratoHorasIncluidas::create(array_merge([
            'contrato_id' => $this->contrato->id, 'periodo' => $periodo, 'horas_incluidas' => $horas, 'valido_de' => '2026-01-01',
        ], $extra));
    }

    private function horas(string $dia, int $segundos, array $extra = []): RegistoTempo
    {
        return $this->registo($this->ana, $this->cliente, $dia, $segundos, ['contrato_id' => $this->contrato->id] + $extra);
    }

    private function fechar(string $mes): void
    {
        app(FechoMensal::class)->fechar($this->admin, CarbonImmutable::parse($mes.'-01'));
    }

    private function calcular(string $mes): array
    {
        return app(CalculadorFaturacao::class)->calcular(CarbonImmutable::parse($mes.'-01'));
    }
}

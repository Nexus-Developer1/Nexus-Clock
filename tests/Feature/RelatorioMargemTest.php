<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Tarifa;
use App\Models\User;
use App\Services\Tempos\Relatorios\FiltrosRelatorio;
use App\Services\Tempos\Relatorios\RelatorioMargem;
use App\Support\Dinheiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Fase 4 — relatório de margem: receita (faturável × preço), custo (todas as horas × custo),
// margem € e %, por contrato / cliente / técnico, tarifas pelo dia de cada registo (regra 11),
// margens negativas em destaque e horas sem tarifa à parte.
class RelatorioMargemTest extends TestCase
{
    use RefreshDatabase;

    private const H = 3600;

    private User $ana;

    private User $bruno;

    private Cliente $hospital;

    private Cliente $banco;

    private Contrato $contratoHospital;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 10:00:00');

        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->bruno = $this->tecnico();
        $this->bruno->update(['nome' => 'Bruno Costa']);
        $this->hospital = $this->cliente('Hospital Exemplo');
        $this->banco = $this->cliente('Banco Exemplo');
        $this->contratoHospital = $this->contrato($this->hospital, 'CT-H');

        // Global 40 €/h (custo 20); Bruno custa 35; o contrato do hospital vende a 60; o banco a 30 (sem custo).
        $this->tarifa(null, null, 4000, 2000);
        $this->tarifa('tecnico', $this->bruno->id, 4500, 3500);
        $this->tarifa('contrato', $this->contratoHospital->id, 6000, null);
        $this->tarifa('cliente', $this->banco->id, 3000, null);
    }

    public function test_receita_custo_e_margem_por_contrato(): void
    {
        $this->registo($this->ana, $this->hospital, '2026-08-03', 10 * self::H, ['contrato_id' => $this->contratoHospital->id]);
        $this->registo($this->ana, $this->hospital, '2026-08-04', 2 * self::H, ['contrato_id' => $this->contratoHospital->id, 'faturavel' => false]);
        $this->registo($this->bruno, $this->banco, '2026-08-05', 10 * self::H);

        $r = $this->gerar('contrato');
        $porNome = collect($r['linhas'])->keyBy('nome');

        // Hospital: receita 10 h × 60 = 600; custo 12 h × 20 (global, o contrato não tem custo) = 240.
        $h = $porNome['CT-H · Hospital Exemplo'];
        $this->assertSame([10 * self::H, 12 * self::H, 60000, 24000, 36000, 60.0], [$h['horas_faturaveis'], $h['horas_totais'], $h['receita'], $h['custo'], $h['margem'], $h['margem_percentagem']]);

        // Banco (sem contrato): receita 10 h × 30 (cliente) = 300; custo 10 h × 35 (Bruno) = 350 → margem negativa.
        $s = $porNome['Sem contrato'];
        $this->assertSame([30000, 35000, -5000, -16.7], [$s['receita'], $s['custo'], $s['margem'], $s['margem_percentagem']]);

        $this->assertSame('Sem contrato', $r['linhas'][0]['nome']); // pior margem primeiro
        $this->assertSame([90000, 59000, 31000, 1], [$r['totais']['receita'], $r['totais']['custo'], $r['totais']['margem'], $r['totais']['negativas']]);
    }

    public function test_agrupar_por_cliente_e_por_tecnico(): void
    {
        $this->registo($this->ana, $this->hospital, '2026-08-03', 4 * self::H, ['contrato_id' => $this->contratoHospital->id]);
        $this->registo($this->ana, $this->banco, '2026-08-04', 2 * self::H);
        $this->registo($this->bruno, $this->hospital, '2026-08-05', 1 * self::H);

        $porCliente = collect($this->gerar('cliente')['linhas'])->keyBy('nome');
        // Hospital: 4 h × 60 (contrato) + 1 h × 45 (Bruno, sem contrato) = 285; custo 4 × 20 + 1 × 35 = 115.
        $this->assertSame([28500, 11500], [$porCliente['Hospital Exemplo']['receita'], $porCliente['Hospital Exemplo']['custo']]);
        $this->assertSame([6000, 4000], [$porCliente['Banco Exemplo']['receita'], $porCliente['Banco Exemplo']['custo']]);

        $porTecnico = collect($this->gerar('tecnico')['linhas'])->keyBy('nome');
        $this->assertSame([30000, 12000], [$porTecnico['Ana Martins']['receita'], $porTecnico['Ana Martins']['custo']]);
        $this->assertSame([4500, 3500], [$porTecnico['Bruno Costa']['receita'], $porTecnico['Bruno Costa']['custo']]);
    }

    public function test_tarifa_do_dia_de_cada_registo_quando_o_preco_muda_a_meio(): void
    {
        Tarifa::where('ambito_tipo', 'contrato')->update(['valido_ate' => '2026-08-15']);
        $this->tarifa('contrato', $this->contratoHospital->id, 7000, null, '2026-08-16');

        $this->registo($this->ana, $this->hospital, '2026-08-15', 1 * self::H, ['contrato_id' => $this->contratoHospital->id]);
        $this->registo($this->ana, $this->hospital, '2026-08-16', 1 * self::H, ['contrato_id' => $this->contratoHospital->id]);

        $this->assertSame(13000, $this->gerar('contrato')['totais']['receita']); // 60 + 70
    }

    public function test_horas_sem_tarifa_ou_sem_custo_nao_entram_nos_valores_e_sao_mostradas(): void
    {
        Tarifa::query()->delete();
        $this->tarifa('cliente', $this->banco->id, 3000, null);
        $this->registo($this->ana, $this->hospital, '2026-08-03', 2 * self::H);
        $this->registo($this->ana, $this->banco, '2026-08-04', 3 * self::H);

        $t = $this->gerar('cliente')['totais'];

        $this->assertSame([9000, 0, 2 * self::H, 5 * self::H], [$t['receita'], $t['custo'], $t['sem_tarifa_seg'], $t['sem_custo_seg']]);
    }

    public function test_filtros_de_periodo_tecnico_e_etiqueta(): void
    {
        $this->registo($this->ana, $this->hospital, '2026-08-03', 1 * self::H, ['etiquetas' => ['remoto']]);
        $this->registo($this->bruno, $this->hospital, '2026-08-04', 2 * self::H);
        $this->registo($this->ana, $this->hospital, '2026-07-31', 5 * self::H);

        $this->assertSame(3 * self::H, $this->gerar('tecnico')['totais']['horas_totais']);
        $this->assertSame(2 * self::H, $this->gerar('tecnico', ['tecnico' => $this->bruno->id])['totais']['horas_totais']);
        $this->assertSame(1 * self::H, $this->gerar('tecnico', ['etiqueta' => 'remoto'])['totais']['horas_totais']);
        // O filtro faturável não se aplica à margem (o custo conta todas as horas).
        $this->assertSame(3 * self::H, $this->gerar('tecnico', ['faturavel' => 'sim'])['totais']['horas_totais']);
    }

    public function test_dinheiro_le_e_formata_euros(): void
    {
        $this->assertSame(4550, Dinheiro::paraCentimos('45,50'));
        $this->assertSame(4550, Dinheiro::paraCentimos('45.50'));
        $this->assertSame(123456, Dinheiro::paraCentimos('1.234,56 €'));
        $this->assertSame(100000, Dinheiro::paraCentimos('1 000'));
        $this->assertNull(Dinheiro::paraCentimos('  '));
        $this->assertSame('1 234,56 €', Dinheiro::formatar(123456));
        $this->assertSame('-50,00 €', Dinheiro::formatar(-5000));
        $this->assertSame('—', Dinheiro::formatar(null));

        $this->expectException(\InvalidArgumentException::class);
        Dinheiro::paraCentimos('45,555');
    }

    private function gerar(string $agrupar, array $query = []): array
    {
        return app(RelatorioMargem::class)->gerar(FiltrosRelatorio::doQuery(['periodo' => 'mes', 'ref' => '2026-08'] + $query), $agrupar);
    }

    private function tarifa(?string $ambito, ?int $id, int $preco, ?int $custo, string $de = '2026-01-01'): Tarifa
    {
        return Tarifa::create(['ambito_tipo' => $ambito, 'ambito_id' => $id, 'preco_hora_cent' => $preco, 'custo_hora_cent' => $custo, 'valido_de' => $de]);
    }
}

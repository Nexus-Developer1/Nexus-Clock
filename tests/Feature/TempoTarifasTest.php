<?php

namespace Tests\Feature;

use App\Models\Tarifa;
use App\Services\Tempos\ResolvedorTarifa;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Regra 11 (docs/modulo-tempos.md §2): a tarifa de um registo resolve-se estritamente por
// contrato → cliente → técnico → global, só com tarifas válidas no dia do registo.
class TempoTarifasTest extends TestCase
{
    use RefreshDatabase;

    public function test_regra11_ordem_contrato_cliente_tecnico_global(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);
        $registo = $this->registo($tecnico, $cliente, '2026-09-08', 3600, ['contrato_id' => $contrato->id]);

        $this->tarifa(null, null, 4000);
        $this->assertSame(4000, $this->resolver()->paraRegisto($registo)->preco_hora_cent);

        $this->tarifa('tecnico', $tecnico->id, 4500);
        $this->assertSame(4500, $this->resolver()->paraRegisto($registo)->preco_hora_cent);

        $this->tarifa('cliente', $cliente->id, 5000);
        $this->assertSame(5000, $this->resolver()->paraRegisto($registo)->preco_hora_cent);

        $this->tarifa('contrato', $contrato->id, 5500);
        $this->assertSame(5500, $this->resolver()->paraRegisto($registo)->preco_hora_cent);
    }

    public function test_regra11_registo_sem_contrato_salta_para_o_cliente(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $this->tarifa('contrato', $this->contrato($cliente)->id, 9900);
        $this->tarifa('cliente', $cliente->id, 5000);

        $this->assertSame(5000, $this->resolver()->paraRegisto($this->registo($tecnico, $cliente, '2026-09-08', 3600))->preco_hora_cent);
    }

    public function test_regra11_tarifa_expirada_ou_ainda_nao_em_vigor_nao_conta(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);
        $registo = $this->registo($tecnico, $cliente, '2026-09-08', 3600, ['contrato_id' => $contrato->id]);

        $this->tarifa('contrato', $contrato->id, 7000, '2026-01-01', '2026-09-07'); // acabou na véspera
        $this->tarifa('contrato', $contrato->id, 7500, '2026-09-09');               // começa no dia seguinte
        $this->tarifa('cliente', $cliente->id, 5000);

        $this->assertSame(5000, $this->resolver()->paraRegisto($registo)->preco_hora_cent);
    }

    public function test_regra11_validade_inclui_o_primeiro_e_o_ultimo_dia(): void
    {
        $cliente = $this->cliente();
        $this->tarifa('cliente', $cliente->id, 5000, '2026-09-08', '2026-09-08');

        $this->assertSame(5000, $this->resolver()->resolver(null, $cliente->id, null, '2026-09-08')?->preco_hora_cent);
        $this->assertNull($this->resolver()->resolver(null, $cliente->id, null, '2026-09-09'));
    }

    public function test_regra11_tarifa_apagada_nao_conta_e_sem_nenhuma_devolve_null(): void
    {
        $cliente = $this->cliente();
        $this->tarifa('cliente', $cliente->id, 5000)->delete();

        $this->assertNull($this->resolver()->resolver(null, $cliente->id, $this->tecnico()->id, '2026-09-08'));
    }

    public function test_regra11_mudanca_de_preco_ao_longo_do_tempo(): void
    {
        $cliente = $this->cliente();
        $this->tarifa(null, null, 4000, '2025-01-01', '2025-12-31');
        $this->tarifa(null, null, 4200, '2026-01-01');

        $this->assertSame(4000, $this->resolver()->resolver(null, $cliente->id, null, '2025-12-31')->preco_hora_cent);
        $this->assertSame(4200, $this->resolver()->resolver(null, $cliente->id, null, '2026-01-01')->preco_hora_cent);
    }

    public function test_regra11_usa_o_dia_de_lisboa_do_registo(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $this->tarifa(null, null, 4000, '2026-01-01', '2026-07-31');
        $this->tarifa(null, null, 4400, '2026-08-01');

        $registo = $this->registo($tecnico, $cliente, '2026-08-01', 3600); // inicio = 31/07 23:00 UTC

        $this->assertSame(4400, $this->resolver()->paraRegisto($registo)->preco_hora_cent);
    }

    public function test_resultado_fica_em_memoria_durante_o_pedido(): void
    {
        $cliente = $this->cliente();
        $this->tarifa(null, null, 4000);
        $resolvedor = $this->resolver();

        $resolvedor->resolver(null, $cliente->id, null, '2026-09-08');
        DB::enableQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $resolvedor->resolver(null, $cliente->id, null, '2026-09-08');
        }

        $this->assertCount(0, DB::getQueryLog());
        $this->assertSame($resolvedor, app(ResolvedorTarifa::class)); // mesma instância no mesmo pedido
    }

    public function test_ambito_incoerente_e_recusado_pela_base_de_dados(): void
    {
        $this->expectException(QueryException::class);
        Tarifa::create(['ambito_tipo' => 'cliente', 'ambito_id' => null, 'preco_hora_cent' => 1, 'valido_de' => '2026-01-01']);
    }

    private function resolver(): ResolvedorTarifa
    {
        $resolvedor = app(ResolvedorTarifa::class);
        $resolvedor->esquecer();

        return $resolvedor;
    }

    private function tarifa(?string $ambito, ?int $id, int $precoCent, string $de = '2026-01-01', ?string $ate = null): Tarifa
    {
        return Tarifa::create([
            'ambito_tipo' => $ambito, 'ambito_id' => $id, 'preco_hora_cent' => $precoCent, 'custo_hora_cent' => 2500,
            'valido_de' => $de, 'valido_ate' => $ate,
        ]);
    }
}

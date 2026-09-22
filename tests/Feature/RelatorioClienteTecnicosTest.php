<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\User;
use App\Services\Tempos\Relatorios\FiltrosRelatorio;
use App\Services\Tempos\Relatorios\RelatorioTecnicos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Fase 3 — resumo por cliente (todos os contratos no mesmo período, e horas sem contrato) e horas
// por técnico (por cliente, faturável vs não, filtros; o técnico só vê as suas).
class RelatorioClienteTecnicosTest extends TestCase
{
    use RefreshDatabase;

    private const H = 3600;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 10:00:00');
    }

    // --- Horas por técnico ---

    public function test_horas_por_tecnico_e_cliente_com_faturavel_e_nao(): void
    {
        [$ana, $bruno, $hospital, $banco, $contrato] = $this->cenarioTecnicos();

        $r = app(RelatorioTecnicos::class)->gerar(FiltrosRelatorio::doQuery(['periodo' => 'mes', 'ref' => '2026-09']));

        $this->assertSame(['Ana Martins', 'Bruno Costa'], array_column($r['tecnicos'], 'nome'));
        $this->assertSame([8 * self::H, 6 * self::H, 2 * self::H, 75], [$r['tecnicos'][0]['total'], $r['tecnicos'][0]['faturavel'], $r['tecnicos'][0]['nao_faturavel'], $r['tecnicos'][0]['percentagem_faturavel']]);
        $this->assertSame([['Hospital Exemplo', 5 * self::H], ['Banco Exemplo', 3 * self::H]], array_map(fn ($c) => [$c['nome'], $c['total']], $r['tecnicos'][0]['clientes']));
        $this->assertSame(['total' => 12 * self::H, 'faturavel' => 10 * self::H, 'nao_faturavel' => 2 * self::H], $r['totais']);
    }

    public function test_filtros_de_cliente_contrato_faturavel_etiqueta_e_periodo_livre(): void
    {
        [$ana, $bruno, $hospital, $banco, $contrato] = $this->cenarioTecnicos();
        $total = fn (array $q) => app(RelatorioTecnicos::class)->gerar(FiltrosRelatorio::doQuery(['periodo' => 'mes', 'ref' => '2026-09'] + $q))['totais']['total'] / self::H;

        $this->assertSame(9, $total(['cliente' => $hospital->id]));
        $this->assertSame(4, $total(['contrato' => $contrato->id]));
        $this->assertSame(10, $total(['faturavel' => 'sim']));
        $this->assertSame(2, $total(['faturavel' => 'nao']));
        $this->assertSame(3, $total(['etiqueta' => 'remoto']));
        $this->assertSame(4, $total(['tecnico' => $bruno->id]));
        $this->assertSame(8, app(RelatorioTecnicos::class)->gerar(FiltrosRelatorio::doQuery(['periodo' => 'personalizado', 'de' => '2026-09-01', 'ate' => '2026-09-02']))['totais']['total'] / self::H);
    }

    /** @return array{0: User, 1: User, 2: Cliente, 3: Cliente, 4: Contrato} */
    private function cenarioTecnicos(): array
    {
        $ana = $this->tecnico();
        $ana->update(['nome' => 'Ana Martins']);
        $bruno = $this->tecnico();
        $bruno->update(['nome' => 'Bruno Costa']);
        $hospital = $this->cliente('Hospital Exemplo');
        $banco = $this->cliente('Banco Exemplo');
        $contrato = $this->contrato($hospital, 'CT-H');

        $this->registo($ana, $hospital, '2026-09-01', 3 * self::H, ['etiquetas' => ['remoto']]);
        $this->registo($ana, $hospital, '2026-09-02', 2 * self::H, ['faturavel' => false]);
        $this->registo($ana, $banco, '2026-09-08', 3 * self::H);
        $this->registo($bruno, $hospital, '2026-09-02', 3 * self::H, ['contrato_id' => $contrato->id]);
        $this->registo($bruno, $hospital, '2026-09-09', 1 * self::H, ['contrato_id' => $contrato->id]);
        $this->registo($bruno, $hospital, '2026-08-31', 7 * self::H); // fora do mês

        return [$ana, $bruno, $hospital, $banco, $contrato];
    }
}

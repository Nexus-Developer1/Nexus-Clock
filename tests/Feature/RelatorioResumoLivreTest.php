<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Resumo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\User;
use App\Services\Tempos\Relatorios\FiltrosRelatorio;
use App\Services\Tempos\Relatorios\RelatorioResumo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Resumo livre: agrupar por qualquer dimensão (e por uma segunda), faturável e não faturável,
// períodos sem horas preenchidos a zero, etiquetas que contam em cada uma, e o técnico só vê as suas.
class RelatorioResumoLivreTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruno;

    private Cliente $hospital;

    private Cliente $banco;

    private Contrato $contrato;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 12:00:00');

        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->bruno = $this->tecnico();
        $this->bruno->update(['nome' => 'Bruno Costa']);
        $this->hospital = $this->cliente('Hospital Exemplo');
        $this->banco = $this->cliente('Banco Exemplo');
        $this->contrato = $this->contrato($this->hospital, 'CT-2026-001');

        $this->registo($this->ana, $this->hospital, '2026-09-01', 4 * 3600, ['contrato_id' => $this->contrato->id, 'etiquetas' => ['remoto', 'urgente']]);
        $this->registo($this->ana, $this->banco, '2026-09-03', 2 * 3600, ['faturavel' => false]);
        $this->registo($this->bruno, $this->hospital, '2026-09-03', 3 * 3600, ['etiquetas' => ['remoto']]);
    }

    private function gerar(string $agrupar, ?string $subgrupo = null, array $query = ['periodo' => 'mes', 'ref' => '2026-09']): array
    {
        return app(RelatorioResumo::class)->gerar(FiltrosRelatorio::doQuery($query), $agrupar, $subgrupo);
    }

    public function test_por_cliente_e_depois_por_tecnico(): void
    {
        $r = $this->gerar('cliente', 'tecnico');

        $this->assertSame(['total' => 9 * 3600, 'registos' => 3, 'faturavel' => 7 * 3600, 'nao_faturavel' => 2 * 3600], $r['totais']);
        $this->assertSame(['Hospital Exemplo', 'Banco Exemplo'], array_column($r['grupos'], 'nome')); // do maior para o menor
        $hospital = $r['grupos'][0];
        $this->assertSame([7 * 3600, 77.8], [$hospital['total'], $hospital['percentagem']]);
        $this->assertSame(['Ana Martins' => 4 * 3600, 'Bruno Costa' => 3 * 3600], array_column($hospital['subgrupos'], 'total', 'nome'));
        $this->assertSame([0, 2 * 3600, 0], [$r['grupos'][1]['faturavel'], $r['grupos'][1]['nao_faturavel'], $r['grupos'][1]['percentagem_faturavel']]);
    }

    public function test_por_contrato_com_sem_contrato_no_fim(): void
    {
        $r = $this->gerar('contrato');

        // "Sem contrato" tem mais horas, mas fica no fim.
        $this->assertSame(['CT-2026-001 · Hospital Exemplo' => 4 * 3600, 'Sem contrato' => 5 * 3600], array_column($r['grupos'], 'total', 'nome'));
    }

    public function test_por_etiqueta_um_registo_conta_em_cada_etiqueta(): void
    {
        $r = $this->gerar('etiqueta');

        $this->assertSame(['remoto' => 7 * 3600, 'urgente' => 4 * 3600, 'Sem etiqueta' => 2 * 3600], array_column($r['grupos'], 'total', 'nome'));
        $this->assertSame(9 * 3600, $r['totais']['total']); // o total não soma duas vezes
        $this->assertTrue($r['porEtiqueta']);
    }

    public function test_por_dia_preenche_os_dias_sem_horas(): void
    {
        $r = $this->gerar('dia', null, ['periodo' => 'personalizado', 'de' => '2026-09-01', 'ate' => '2026-09-04']);

        $this->assertSame(['2026-09-01' => 4 * 3600, '2026-09-02' => 0, '2026-09-03' => 5 * 3600, '2026-09-04' => 0], array_column($r['grupos'], 'total', 'chave'));
        $this->assertSame('Ter, 01/09', $r['grupos'][0]['nome']);
    }

    public function test_por_mes_e_por_semana(): void
    {
        $this->assertSame(['Setembro 2026' => 9 * 3600], array_column($this->gerar('mes')['grupos'], 'total', 'nome'));

        $semanas = $this->gerar('semana', null, ['periodo' => 'personalizado', 'de' => '2026-08-31', 'ate' => '2026-09-13']);
        $this->assertSame(['31/08 – 06/09' => 9 * 3600, '07/09 – 13/09' => 0], array_column($semanas['grupos'], 'total', 'nome'));
    }
}

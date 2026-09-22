<?php

namespace Tests\Feature;

use App\Models\ContratoHorasIncluidas;
use App\Models\User;
use App\Services\Tempos\GestorHorasIncluidas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Fase 4 — horas incluídas de um contrato: criar, alterar, apagar (só admin), uma só ativa por data
// (sobreposição de validades recusada), avisos fora das datas do contrato e relatórios logo certos.
class HorasIncluidasGestaoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private GestorHorasIncluidas $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 10:00:00');
        $this->admin = $this->admin();
        $this->gestor = app(GestorHorasIncluidas::class);
    }

    public function test_uma_so_ativa_por_data_validades_sobrepostas_recusadas(): void
    {
        $contrato = $this->contrato($this->cliente());
        $this->guardar($contrato, ['valido_de' => '2026-01-01', 'valido_ate' => '2026-06-30']);

        foreach ([['2026-06-30', null], ['2025-12-01', '2026-01-01'], ['2026-02-01', '2026-02-28'], ['2025-01-01', null]] as [$de, $ate]) {
            $erro = $this->erro(fn () => $this->guardar($contrato, ['valido_de' => $de, 'valido_ate' => $ate]));
            $this->assertStringContainsString('Este contrato já tem horas incluídas de 01/01/2026 a 30/06/2026', $erro['valido_de'][0]);
        }

        // Seguidas e noutro contrato: aceites.
        $this->guardar($contrato, ['valido_de' => '2026-07-01']);
        $this->guardar($this->contrato($this->cliente()), ['valido_de' => '2026-01-01']);

        // Sem fim a bloquear o futuro.
        $erro = $this->erro(fn () => $this->guardar($contrato, ['valido_de' => '2030-01-01']));
        $this->assertStringContainsString('de 01/07/2026 em diante', $erro['valido_de'][0]);

        $this->assertSame(3, ContratoHorasIncluidas::count());
    }

    public function test_alterar_propria_validade_e_apagar_liberta_as_datas(): void
    {
        $contrato = $this->contrato($this->cliente());
        $h = $this->guardar($contrato, ['valido_de' => '2026-01-01']);

        $this->gestor->guardar($this->admin, $contrato, $h, $this->dados(['horas' => '7,5', 'valido_de' => '2026-01-01', 'valido_ate' => '2026-12-31']));
        $this->assertSame('7.50', $h->fresh()->horas_incluidas);

        $this->gestor->apagar($this->admin, $h);
        $this->guardar($contrato, ['valido_de' => '2026-03-01']);
        $this->assertSame(1, ContratoHorasIncluidas::count());
    }

    public function test_validacoes(): void
    {
        $contrato = $this->contrato($this->cliente());

        $this->assertArrayHasKey('horas', $this->erro(fn () => $this->guardar($contrato, ['horas' => 'dez'])));
        $this->assertArrayHasKey('periodo', $this->erro(fn () => $this->guardar($contrato, ['periodo' => 'semanal'])));
        $this->assertArrayHasKey('arredondamento_min', $this->erro(fn () => $this->guardar($contrato, ['arredondamento_min' => 7])));
        $this->assertArrayHasKey('valido_de', $this->erro(fn () => $this->guardar($contrato, ['valido_de' => ''])));
        $this->assertArrayHasKey('valido_ate', $this->erro(fn () => $this->guardar($contrato, ['valido_de' => '2026-05-01', 'valido_ate' => '2026-04-01'])));
    }

    public function test_avisa_fora_das_datas_do_contrato_sem_bloquear(): void
    {
        $contrato = $this->contrato($this->cliente()); // 2026-01-01 a 2027-12-31
        $h = $this->guardar($contrato, ['valido_de' => '2025-12-01']);

        $this->assertSame(['Começam antes do início do contrato (01/01/2026).', 'Vão além do fim do contrato (31/12/2027).'], $this->gestor->avisos($contrato, $h));
    }

    /** @param array<string, mixed> $dados */
    private function guardar($contrato, array $dados = []): ContratoHorasIncluidas
    {
        return $this->gestor->guardar($this->admin, $contrato, null, $this->dados($dados));
    }

    /** @param array<string, mixed> $dados */
    private function dados(array $dados = []): array
    {
        return $dados + [
            'periodo' => 'mensal', 'horas' => '10', 'transita' => false, 'excedente_faturavel' => true,
            'arredondamento_min' => 15, 'arredondamento_modo' => 'cima', 'valido_de' => '2026-01-01', 'valido_ate' => null,
        ];
    }

    /** @return array<string, list<string>> */
    private function erro(callable $acao): array
    {
        try {
            $acao();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Esperava-se um erro de validação.');
    }
}

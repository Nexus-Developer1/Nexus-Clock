<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Tarifa;
use App\Models\User;
use App\Services\Tempos\GestorTarifas;
use App\Services\Tempos\ResolvedorTarifa;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Fase 4 — gestão de tarifas: criar, alterar e apagar (só admin), valores em euros, validade e
// deteção de sobreposição no mesmo âmbito; regra 11 com tarifas criadas pela gestão.
class TarifasGestaoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private GestorTarifas $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 10:00:00');
        $this->admin = $this->admin();
        $this->gestor = app(GestorTarifas::class);
    }

    // --- Sobreposição de validades no mesmo âmbito ---

    public function test_recusa_tarifas_sobrepostas_no_mesmo_ambito(): void
    {
        $cliente = $this->cliente('ACME');
        $this->guardar(['ambito_tipo' => 'cliente', 'ambito_id' => $cliente->id, 'preco' => '50', 'valido_de' => '2026-01-01', 'valido_ate' => '2026-06-30']);

        $casos = [
            'começa dentro' => ['2026-06-30', '2026-12-31'],
            'acaba dentro' => ['2025-10-01', '2026-01-01'],
            'por fora' => ['2025-01-01', '2027-01-01'],
            'por dentro' => ['2026-03-01', '2026-03-31'],
            'sem fim, a começar antes' => ['2025-06-01', null],
        ];

        foreach ($casos as $caso => [$de, $ate]) {
            $erro = $this->erro(fn () => $this->guardar(['ambito_tipo' => 'cliente', 'ambito_id' => $cliente->id, 'preco' => '55', 'valido_de' => $de, 'valido_ate' => $ate]));
            $this->assertStringContainsString('Já existe uma tarifa para cliente acme de 01/01/2026 a 30/06/2026', $erro['valido_de'][0], $caso);
        }

        $this->assertSame(1, Tarifa::count());
    }

    public function test_aceita_validades_seguidas_outros_ambitos_e_ignora_apagadas(): void
    {
        $cliente = $this->cliente();
        $outro = $this->cliente();
        $primeira = $this->guardar(['ambito_tipo' => 'cliente', 'ambito_id' => $cliente->id, 'preco' => '50', 'valido_de' => '2026-01-01', 'valido_ate' => '2026-06-30']);

        $this->guardar(['ambito_tipo' => 'cliente', 'ambito_id' => $cliente->id, 'preco' => '55', 'valido_de' => '2026-07-01']); // começa no dia seguinte
        $this->guardar(['ambito_tipo' => 'cliente', 'ambito_id' => $outro->id, 'preco' => '60', 'valido_de' => '2026-01-01']);  // outro cliente
        $this->guardar(['preco' => '40', 'valido_de' => '2026-01-01']);                                                       // global

        $this->gestor->apagar($this->admin, $primeira);
        $this->guardar(['ambito_tipo' => 'cliente', 'ambito_id' => $cliente->id, 'preco' => '52', 'valido_de' => '2026-02-01', 'valido_ate' => '2026-06-30']);

        $this->assertSame(4, Tarifa::count());
    }

    public function test_alterar_nao_choca_consigo_propria_mas_choca_com_as_outras(): void
    {
        $a = $this->guardar(['preco' => '40', 'valido_de' => '2026-01-01', 'valido_ate' => '2026-06-30']);
        $b = $this->guardar(['preco' => '42', 'valido_de' => '2026-07-01']);

        $this->gestor->guardar($this->admin, $a, ['preco' => '41', 'valido_de' => '2026-01-01', 'valido_ate' => '2026-06-30']);
        $this->assertSame(4100, $a->fresh()->preco_hora_cent);

        $erro = $this->erro(fn () => $this->gestor->guardar($this->admin, $b, ['preco' => '42', 'valido_de' => '2026-06-01']));
        $this->assertStringContainsString('global de 01/01/2026 a 30/06/2026', $erro['valido_de'][0]);
    }

    // --- Validação ---

    public function test_valores_em_euros_e_validacoes(): void
    {
        $tecnico = $this->tecnico();

        $t = $this->guardar(['ambito_tipo' => 'tecnico', 'ambito_id' => $tecnico->id, 'preco' => '1.234,56 €', 'custo' => '25,5', 'valido_de' => '2026-01-01']);
        $this->assertSame([123456, 2550], [$t->preco_hora_cent, $t->custo_hora_cent]);

        $this->assertArrayHasKey('preco', $this->erro(fn () => $this->guardar(['valido_de' => '2026-01-01'])));
        $this->assertSame('Valor inválido: «abc». Use, por exemplo, 45,50.', $this->erro(fn () => $this->guardar(['preco' => 'abc', 'valido_de' => '2027-01-01']))['preco'][0]);
        $this->assertArrayHasKey('custo', $this->erro(fn () => $this->guardar(['preco' => '10', 'custo' => '-5', 'valido_de' => '2027-01-01'])));
        $this->assertSame('Escolha o contrato.', $this->erro(fn () => $this->guardar(['ambito_tipo' => 'contrato', 'preco' => '10', 'valido_de' => '2027-01-01']))['ambito_id'][0]);
        $this->assertSame('Escolha o técnico.', $this->erro(fn () => $this->guardar(['ambito_tipo' => 'tecnico', 'ambito_id' => $this->utilizador(null)->id, 'preco' => '10', 'valido_de' => '2027-01-01']))['ambito_id'][0]);
        $this->assertSame('O fim não pode ser antes do início.', $this->erro(fn () => $this->guardar(['preco' => '10', 'valido_de' => '2027-02-01', 'valido_ate' => '2027-01-01']))['valido_ate'][0]);
    }

    public function test_so_admin_gere_tarifas_e_fica_na_auditoria(): void
    {
        $this->actingAs($this->admin);
        $tarifa = $this->guardar(['preco' => '40', 'valido_de' => '2026-01-01']);
        $this->gestor->guardar($this->admin, $tarifa, ['preco' => '45', 'valido_de' => '2026-01-01']);
        $this->gestor->apagar($this->admin, $tarifa);

        $this->assertSame(['tempo_tarifa_criada', 'tempo_tarifa_alterada', 'tempo_tarifa_apagada'], Auditoria::orderBy('id')->pluck('acao')->all());
        $this->assertSame('40,00 €', Auditoria::where('acao', 'tempo_tarifa_alterada')->first()->detalhe['antes']['preco']);

        $this->expectException(AuthorizationException::class);
        $this->gestor->guardar($this->tecnico(), null, ['preco' => '40', 'valido_de' => '2027-01-01']);
    }

    // --- Regra 11 com tarifas criadas pela gestão ---

    public function test_regra11_resolucao_com_tarifas_da_gestao_no_mesmo_pedido(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);
        $resolvedor = app(ResolvedorTarifa::class);

        $this->guardar(['preco' => '40', 'custo' => '20', 'valido_de' => '2026-01-01']);
        $this->assertSame(4000, $resolvedor->resolver($contrato->id, $cliente->id, $tecnico->id, '2026-09-01')->preco_hora_cent);

        // Criada no mesmo pedido: o resolvedor esquece o que tinha lido.
        $this->guardar(['ambito_tipo' => 'contrato', 'ambito_id' => $contrato->id, 'preco' => '60', 'valido_de' => '2026-09-01']);
        $this->guardar(['ambito_tipo' => 'tecnico', 'ambito_id' => $tecnico->id, 'preco' => '50', 'custo' => '30', 'valido_de' => '2026-01-01']);

        $this->assertSame(6000, $resolvedor->resolver($contrato->id, $cliente->id, $tecnico->id, '2026-09-01')->preco_hora_cent);
        $this->assertSame(5000, $resolvedor->resolver($contrato->id, $cliente->id, $tecnico->id, '2026-08-31')->preco_hora_cent); // a do contrato ainda não vigorava
        // O custo vem da primeira da cadeia COM custo: a do contrato não tem, a do técnico tem.
        $this->assertSame(3000, $resolvedor->resolverCusto($contrato->id, $cliente->id, $tecnico->id, '2026-09-01')->custo_hora_cent);
        $this->assertSame(2000, $resolvedor->resolverCusto($contrato->id, $cliente->id, null, '2026-09-01')->custo_hora_cent);
    }

    // --- Página ---

    /** @param array<string, mixed> $dados */
    private function guardar(array $dados): Tarifa
    {
        return $this->gestor->guardar($this->admin, null, $dados);
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

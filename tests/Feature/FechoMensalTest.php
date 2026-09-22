<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\MesTempo;
use App\Models\RegistoTempo;
use App\Models\Tarifa;
use App\Models\User;
use App\Services\Tempos\Faturacao\FechoMensal;
use App\Services\Tempos\FolhaSemanal;
use App\Services\Tempos\GestorHorasIncluidas;
use App\Services\Tempos\GestorTarifas;
use App\Services\Tempos\GravadorRegistos;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Fase 5 — regra 12: fecho mensal (só admin, bloqueia os registos do mês de todos os técnicos, impede
// submeter semanas que cruzem o mês) e reabertura (permissão explícita, motivo, auditoria). As
// condições (tarifas, horas incluídas) dos meses fechados também ficam bloqueadas.
class FechoMensalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $chefe;

    private FechoMensal $fecho;

    private CarbonImmutable $agosto;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 10:00:00');
        config(['tempos.pode_reabrir' => ['chefe@nxs.pt']]);
        $this->admin = $this->admin();
        $this->chefe = $this->admin('chefe@nxs.pt');
        $this->fecho = app(FechoMensal::class);
        $this->agosto = CarbonImmutable::parse('2026-08-01');
    }

    public function test_regra12_fechar_bloqueia_os_registos_do_mes_de_todos_os_tecnicos(): void
    {
        $ana = $this->tecnico();
        $bruno = $this->tecnico();
        $cliente = $this->cliente();
        $a = $this->registo($ana, $cliente, '2026-08-03', 3600);
        $b = $this->registo($bruno, $cliente, '2026-08-31', 3600);
        $setembro = $this->registo($ana, $cliente, '2026-09-01', 3600);

        $this->actingAs($this->admin);
        $estado = $this->fecho->fechar($this->admin, $this->agosto);

        $this->assertTrue($estado->estaFechado());
        $this->assertNotNull($a->fresh()->fechado_em);
        $this->assertNotNull($b->fresh()->fechado_em);
        $this->assertNull($setembro->fresh()->fechado_em);
        $this->assertSame(['mes' => '2026-08', 'registos' => 2], Auditoria::where('acao', 'tempo_mes_fechado')->sole()->detalhe);

        $this->assertFalse(Gate::forUser($ana)->allows('update', $a->fresh()));
        $this->assertFalse(Gate::forUser($this->admin)->allows('update', $a->fresh()));
        $this->assertTrue(Gate::forUser($this->chefe)->allows('update', $a->fresh()));
    }

    public function test_regra12_nao_se_criam_nem_mudam_registos_para_um_mes_fechado(): void
    {
        $ana = $this->tecnico();
        $cliente = $this->cliente();
        $setembro = $this->registo($ana, $cliente, '2026-09-02', 3600);
        $this->fecho->fechar($this->admin, $this->agosto);
        $gravador = app(GravadorRegistos::class);

        try {
            $gravador->criar($ana, ['cliente_id' => $cliente->id, 'dia' => '2026-08-20', 'duracao_seg' => 600]);
            $this->fail('Não devia criar num mês fechado.');
        } catch (AuthorizationException) {
        }

        try {
            $gravador->atualizar($ana, $setembro, ['dia' => '2026-08-28', 'duracao_seg' => 3600]);
            $this->fail('Não devia mudar para um mês fechado.');
        } catch (AuthorizationException) {
        }

        // Com permissão de reabrir: cria, e o registo nasce fechado (entra na faturação).
        $novo = $gravador->criar($this->chefe, ['tecnico_id' => $ana->id, 'cliente_id' => $cliente->id, 'dia' => '2026-08-20', 'duracao_seg' => 600]);
        $this->assertNotNull($novo->fresh()->fechado_em);
    }

    public function test_regra12_nao_se_submete_semana_que_cruze_o_mes_fechado(): void
    {
        $ana = $this->tecnico();
        $this->fecho->fechar($this->admin, $this->agosto);

        try {
            app(FolhaSemanal::class)->submeter($ana, $ana, CarbonImmutable::parse('2026-08-31')); // 31/08 a 06/09
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame('A semana inclui dias de agosto de 2026, que já está fechado.', $e->errors()['semana'][0]);
        }

        $this->assertSame('submetida', app(FolhaSemanal::class)->submeter($ana, $ana, CarbonImmutable::parse('2026-09-07'))->estado->value);
    }

    public function test_regra12_so_admin_fecha_e_so_meses_acabados_e_sem_buracos(): void
    {
        $cliente = $this->cliente();
        $ana = $this->tecnico();

        $this->expectErro(fn () => $this->fecho->fechar($this->admin, CarbonImmutable::parse('2026-09-01')), 'Só se fecha um mês depois de ele acabar.');

        $this->registo($ana, $cliente, '2026-07-10', 3600);
        $this->expectErro(fn () => $this->fecho->fechar($this->admin, $this->agosto), 'Feche primeiro julho de 2026, que ainda tem horas por fechar.');

        $this->fecho->fechar($this->admin, CarbonImmutable::parse('2026-07-01'));
        RegistoTempo::create(['tecnico_id' => $ana->id, 'cliente_id' => $cliente->id, 'inicio' => '2026-08-31 20:00:00+00']); // cronómetro a correr
        $this->expectErro(fn () => $this->fecho->fechar($this->admin, $this->agosto), 'Há cronómetros a correr com início neste mês. Parem-se antes de fechar.');

        RegistoTempo::whereNull('fim')->delete();
        $this->fecho->fechar($this->admin, $this->agosto);
        $this->expectErro(fn () => $this->fecho->fechar($this->admin, $this->agosto), 'Este mês já está fechado.');

        $this->expectException(AuthorizationException::class);
        $this->fecho->fechar($ana, CarbonImmutable::parse('2026-06-01'));
    }

    public function test_reabrir_exige_permissao_explicita_motivo_e_ordem(): void
    {
        $ana = $this->tecnico();
        $cliente = $this->cliente();
        $registo = $this->registo($ana, $cliente, '2026-08-10', 3600);
        $faturado = $this->registo($ana, $cliente, '2026-08-11', 3600);
        $this->fecho->fechar($this->admin, CarbonImmutable::parse('2026-07-01'));
        $this->fecho->fechar($this->admin, $this->agosto);
        $faturado->forceFill(['faturado_em' => now()])->save();

        try {
            $this->fecho->reabrir($this->admin, $this->agosto, 'engano');
            $this->fail('Admin sem permissão explícita não reabre.');
        } catch (AuthorizationException) {
        }

        $this->expectErro(fn () => $this->fecho->reabrir($this->chefe, $this->agosto, '  '), 'Indique o motivo da reabertura.');
        $this->expectErro(fn () => $this->fecho->reabrir($this->chefe, CarbonImmutable::parse('2026-07-01'), 'engano'), 'Reabra primeiro os meses seguintes, que estão fechados.');

        $this->actingAs($this->chefe);
        $this->fecho->reabrir($this->chefe, $this->agosto, 'Faltavam horas do Bruno');

        $this->assertNull($registo->fresh()->fechado_em);
        $this->assertNotNull($faturado->fresh()->fechado_em); // regra 4: faturado fica como está
        $this->assertFalse(MesTempo::where('mes', '2026-08-01')->sole()->estaFechado());
        $this->assertSame('Faltavam horas do Bruno', Auditoria::where('acao', 'tempo_mes_reaberto')->sole()->detalhe['motivo']);
        $this->assertTrue(Gate::forUser($ana)->allows('update', $registo->fresh()));
    }

    public function test_condicoes_dos_meses_fechados_nao_mudam(): void
    {
        $contrato = $this->contrato($this->cliente());
        $tarifas = app(GestorTarifas::class);
        $horas = app(GestorHorasIncluidas::class);
        $global = $tarifas->guardar($this->admin, null, ['preco' => '40', 'valido_de' => '2026-01-01']);
        $incluidas = $horas->guardar($this->admin, $contrato, null, ['periodo' => 'mensal', 'horas' => '10', 'valido_de' => '2026-01-01', 'arredondamento_min' => 15, 'arredondamento_modo' => 'cima']);
        $this->fecho->fechar($this->admin, $this->agosto);

        // Mudar o preço, apagar ou criar por cima de agosto: recusado.
        $this->expectErro(fn () => $tarifas->guardar($this->admin, $global, ['preco' => '45', 'valido_de' => '2026-01-01']), 'mexe no preço de meses já fechados (agosto de 2026)');
        $this->expectErro(fn () => $tarifas->apagar($this->admin, $global), 'Não pode ser apagada');
        $this->expectErro(fn () => $horas->guardar($this->admin, $contrato, $incluidas, ['periodo' => 'mensal', 'horas' => '12', 'valido_de' => '2026-01-01', 'arredondamento_min' => 15, 'arredondamento_modo' => 'cima']), 'horas incluídas de meses já fechados');
        $this->expectErro(fn () => $tarifas->guardar($this->admin, null, ['ambito_tipo' => 'contrato', 'ambito_id' => $contrato->id, 'preco' => '60', 'valido_de' => '2026-08-15']), 'meses já fechados');

        // O caminho certo: terminar no fim do último mês fechado e criar a partir do seguinte.
        $tarifas->guardar($this->admin, $global, ['preco' => '40', 'valido_de' => '2026-01-01', 'valido_ate' => '2026-08-31']);
        $tarifas->guardar($this->admin, null, ['preco' => '45', 'valido_de' => '2026-09-01']);
        $horas->guardar($this->admin, $contrato, $incluidas, ['periodo' => 'mensal', 'horas' => '10', 'valido_de' => '2026-01-01', 'valido_ate' => '2026-08-31', 'arredondamento_min' => 15, 'arredondamento_modo' => 'cima']);

        $this->assertSame(2, Tarifa::count());
    }

    private function expectErro(callable $acao, string $mensagem): void
    {
        try {
            $acao();
        } catch (ValidationException $e) {
            $this->assertStringContainsString($mensagem, collect($e->errors())->flatten()->implode(' '));

            return;
        }

        $this->fail("Esperava-se o erro «{$mensagem}».");
    }
}

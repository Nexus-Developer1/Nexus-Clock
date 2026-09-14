<?php

namespace Tests\Feature;

use App\Enums\EstadoSemanaTempo;
use App\Models\Auditoria;
use App\Models\SemanaTempo;
use App\Services\Tempos\GravadorRegistos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Regras 1–4 (docs/modulo-tempos.md §2): de quem é o registo, semana submetida, registo fechado
// no fecho mensal e registo faturado. Tudo pelo GravadorRegistos, que é por onde a UI grava.
class TempoPermissoesTest extends TestCase
{
    use RefreshDatabase;

    private GravadorRegistos $gravador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gravador = app(GravadorRegistos::class);
    }

    // --- Regra 1: cada técnico só mexe no que é seu; o admin mexe em tudo ---

    public function test_regra1_tecnico_cria_e_edita_os_seus(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();

        $registo = $this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600]);
        $this->gravador->atualizar($tecnico, $registo, ['duracao_seg' => 5400, 'dia' => '2026-09-08']);

        $this->assertSame(5400, $registo->fresh()->duracao_seg);
        $this->assertSame($tecnico->id, $registo->fresh()->criado_por);
    }

    public function test_regra1_tecnico_nao_cria_em_nome_de_outro(): void
    {
        $tecnico = $this->tecnico();
        $outro = $this->tecnico();

        $this->expectException(AuthorizationException::class);
        $this->gravador->criar($tecnico, ['tecnico_id' => $outro->id, 'cliente_id' => $this->cliente()->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600]);
    }

    public function test_regra1_tecnico_nao_edita_nem_apaga_registo_de_outro(): void
    {
        $tecnico = $this->tecnico();
        $registo = $this->registo($this->tecnico(), $this->cliente(), '2026-09-08', 3600);

        $this->assertFalse(Gate::forUser($tecnico)->allows('update', $registo));
        $this->assertFalse(Gate::forUser($tecnico)->allows('delete', $registo));
        $this->assertFalse(Gate::forUser($tecnico)->allows('view', $registo));

        $this->expectException(AuthorizationException::class);
        $this->gravador->atualizar($tecnico, $registo, ['descricao' => 'meu agora']);
    }

    public function test_regra1_tecnico_nao_passa_o_seu_registo_para_outro_tecnico(): void
    {
        $tecnico = $this->tecnico();
        $registo = $this->registo($tecnico, $this->cliente(), '2026-09-08', 3600);

        $this->expectException(AuthorizationException::class);
        $this->gravador->atualizar($tecnico, $registo, ['tecnico_id' => $this->tecnico()->id]);
    }

    public function test_regra1_admin_cria_edita_e_apaga_de_qualquer_tecnico(): void
    {
        $admin = $this->admin();
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();

        $registo = $this->gravador->criar($admin, ['tecnico_id' => $tecnico->id, 'cliente_id' => $cliente->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600]);
        $this->assertSame($tecnico->id, $registo->tecnico_id);
        $this->assertTrue(Gate::forUser($admin)->allows('view', $registo));

        $this->gravador->atualizar($admin, $registo, ['descricao' => 'Corrigido pelo admin']);
        $this->assertSame($admin->id, $registo->fresh()->alterado_por);

        $this->gravador->apagar($admin, $registo);
        $this->assertSoftDeleted($registo);
    }

    // --- Regra 2: semana submetida fecha a porta ao técnico, não ao admin ---

    public function test_regra2_semana_submetida_bloqueia_o_tecnico(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $registo = $this->registo($tecnico, $cliente, '2026-09-09', 3600);
        $this->submeterSemana($tecnico->id, '2026-09-07');

        $this->assertFalse(Gate::forUser($tecnico)->allows('update', $registo));
        $this->assertFalse(Gate::forUser($tecnico)->allows('delete', $registo));

        // Nem acrescentar um registo novo a essa semana (domingo ainda é a mesma semana).
        $this->expectException(AuthorizationException::class);
        $this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'dia' => '2026-09-13', 'duracao_seg' => 600]);
    }

    public function test_regra2_semana_submetida_nao_bloqueia_o_admin(): void
    {
        $tecnico = $this->tecnico();
        $registo = $this->registo($tecnico, $this->cliente(), '2026-09-09', 3600);
        $this->submeterSemana($tecnico->id, '2026-09-07');

        $this->gravador->atualizar($this->admin(), $registo, ['duracao_seg' => 7200, 'dia' => '2026-09-09']);

        $this->assertSame(7200, $registo->fresh()->duracao_seg);
    }

    public function test_regra2_semana_reaberta_volta_a_ser_editavel_pelo_tecnico(): void
    {
        $tecnico = $this->tecnico();
        $registo = $this->registo($tecnico, $this->cliente(), '2026-09-09', 3600);
        $semana = $this->submeterSemana($tecnico->id, '2026-09-07');

        $semana->update(['estado' => EstadoSemanaTempo::Reaberta, 'reaberta_por' => $this->admin()->id]);

        $this->assertTrue(Gate::forUser($tecnico)->allows('update', $registo));
    }

    public function test_regra2_nao_se_tira_um_registo_de_uma_semana_submetida_mudando_o_dia(): void
    {
        $tecnico = $this->tecnico();
        $registo = $this->registo($tecnico, $this->cliente(), '2026-09-09', 3600);
        $this->submeterSemana($tecnico->id, '2026-09-07');

        $this->expectException(AuthorizationException::class);
        $this->gravador->atualizar($tecnico, $registo, ['dia' => '2026-09-15', 'duracao_seg' => 3600]);
    }

    public function test_regra2_nao_se_mete_um_registo_numa_semana_submetida_mudando_o_dia(): void
    {
        $tecnico = $this->tecnico();
        $registo = $this->registo($tecnico, $this->cliente(), '2026-09-15', 3600);
        $this->submeterSemana($tecnico->id, '2026-09-07');

        $this->expectException(AuthorizationException::class);
        $this->gravador->atualizar($tecnico, $registo, ['dia' => '2026-09-09', 'duracao_seg' => 3600]);
    }

    public function test_regra2_semana_submetida_de_um_tecnico_nao_afeta_outro(): void
    {
        $tecnico = $this->tecnico();
        $outro = $this->tecnico();
        $this->submeterSemana($outro->id, '2026-09-07');

        $registo = $this->gravador->criar($tecnico, ['cliente_id' => $this->cliente()->id, 'dia' => '2026-09-09', 'duracao_seg' => 3600]);

        $this->assertTrue($registo->exists);
    }

    // --- Regra 3: registo fechado só se mexe com permissão explícita de reabrir ---

    public function test_regra3_registo_fechado_nao_e_editavel_por_tecnico_nem_por_admin_sem_permissao(): void
    {
        config(['tempos.pode_reabrir' => ['chefe@nxs.pt']]);
        $tecnico = $this->tecnico();
        $registo = $this->registo($tecnico, $this->cliente(), '2026-08-12', 3600, ['fechado_em' => now()]);

        $this->assertFalse(Gate::forUser($tecnico)->allows('update', $registo));
        $this->assertFalse(Gate::forUser($this->admin())->allows('update', $registo));
        $this->assertFalse(Gate::forUser($this->admin())->allows('delete', $registo));
    }

    public function test_regra3_admin_com_permissao_de_reabrir_edita_registo_fechado(): void
    {
        config(['tempos.pode_reabrir' => ['chefe@nxs.pt']]);
        $chefe = $this->admin('chefe@nxs.pt');
        $registo = $this->registo($this->tecnico(), $this->cliente(), '2026-08-12', 3600, ['fechado_em' => now()]);

        $this->gravador->atualizar($chefe, $registo, ['descricao' => 'Corrigido após fecho']);

        $this->assertSame('Corrigido após fecho', $registo->fresh()->descricao);
    }

    public function test_regra3_tecnico_na_lista_de_reabrir_nao_ganha_poderes(): void
    {
        config(['tempos.pode_reabrir' => ['tecnico-esperto@nxs.pt']]);
        $tecnico = $this->utilizador('tecnico', 'tecnico-esperto@nxs.pt');
        $registo = $this->registo($tecnico, $this->cliente(), '2026-08-12', 3600, ['fechado_em' => now()]);

        $this->assertFalse(Gate::forUser($tecnico)->allows('update', $registo));
    }

    // --- Regra 4: faturado nunca se edita; só se anula, por admin, com registo ---

    public function test_regra4_registo_faturado_nao_e_editavel_por_ninguem(): void
    {
        config(['tempos.pode_reabrir' => ['chefe@nxs.pt']]);
        $tecnico = $this->tecnico();
        $chefe = $this->admin('chefe@nxs.pt');
        $registo = $this->registo($tecnico, $this->cliente(), '2026-07-10', 3600, ['fechado_em' => now(), 'faturado_em' => now()]);

        foreach ([$tecnico, $this->admin(), $chefe] as $quem) {
            $this->assertFalse(Gate::forUser($quem)->allows('update', $registo));
            $this->assertFalse(Gate::forUser($quem)->allows('delete', $registo));
        }

        $this->expectException(AuthorizationException::class);
        $this->gravador->atualizar($chefe, $registo, ['descricao' => 'tentativa']);
    }

    public function test_regra4_admin_anula_registo_faturado_com_motivo_e_fica_na_auditoria(): void
    {
        $admin = $this->admin();
        $registo = $this->registo($this->tecnico(), $this->cliente(), '2026-07-10', 3600, ['fechado_em' => now(), 'faturado_em' => now()]);

        $this->actingAs($admin);
        $this->gravador->anular($admin, $registo, 'Horas lançadas no cliente errado');

        $this->assertSoftDeleted($registo);
        $auditoria = Auditoria::where('acao', 'tempo_registo_anulado')->sole();
        $this->assertSame('RegistoTempo', $auditoria->entidade_tipo);
        $this->assertSame($registo->id, $auditoria->entidade_id);
        $this->assertSame($admin->id, $auditoria->user_id);
        $this->assertSame('Horas lançadas no cliente errado', $auditoria->detalhe['motivo']);
    }

    public function test_regra4_tecnico_nao_anula_e_anular_exige_motivo(): void
    {
        $registo = $this->registo($this->tecnico(), $this->cliente(), '2026-07-10', 3600, ['faturado_em' => now()]);

        $this->assertFalse(Gate::forUser($this->tecnico())->allows('anular', $registo));

        $this->expectException(ValidationException::class);
        $this->gravador->anular($this->admin(), $registo, '   ');
    }

    public function test_regra4_so_se_anula_o_que_foi_faturado(): void
    {
        $registo = $this->registo($this->tecnico(), $this->cliente(), '2026-07-10', 3600);

        $this->assertFalse(Gate::forUser($this->admin())->allows('anular', $registo));
    }

    public function test_campos_de_estado_nao_se_alteram_pelo_gravador(): void
    {
        $tecnico = $this->tecnico();
        $registo = $this->gravador->criar($tecnico, [
            'cliente_id' => $this->cliente()->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600,
            'fechado_em' => now(), 'faturado_em' => now(),
        ]);

        $this->assertNull($registo->fresh()->fechado_em);
        $this->assertNull($registo->fresh()->faturado_em);
    }

    private function submeterSemana(int $tecnicoId, string $segunda): SemanaTempo
    {
        return SemanaTempo::create([
            'tecnico_id' => $tecnicoId, 'semana_inicio' => $segunda,
            'estado' => EstadoSemanaTempo::Submetida, 'submetida_em' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\EstadoSemanaTempo;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\RegistoTempo;
use App\Models\SemanaTempo;
use App\Models\User;
use App\Services\Tempos\FolhaSemanal;
use App\Services\Tempos\GravadorRegistos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Aprovação das semanas (um só nível): quem gere aprova ou rejeita com motivo uma semana submetida.
// Aprovada fica fechada a todos até ser reaberta; rejeitada volta a ser editável e a semana conta
// como em falta até ser submetida de novo.
class SemanasAprovacaoTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUNDA = '2026-09-07';

    private User $admin;

    private User $tecnico;

    private Cliente $cliente;

    private FolhaSemanal $folha;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 09:00:00'); // segunda-feira

        $this->admin = $this->admin();
        $this->tecnico = $this->tecnico();
        $this->tecnico->update(['nome' => 'Ana Martins']);
        $this->cliente = $this->cliente('Hospital Exemplo');
        $this->folha = app(FolhaSemanal::class);
        $this->registo($this->tecnico, $this->cliente, '2026-09-08', 4 * 3600);
    }

    // --- Aprovar ---

    public function test_admin_aprova_semana_submetida_e_fica_na_auditoria(): void
    {
        $this->folha->submeter($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA));
        $semana = $this->folha->aprovar($this->admin, $this->tecnico, Carbon::parse(self::SEGUNDA));

        $this->assertSame(EstadoSemanaTempo::Aprovada, $semana->estado);
        $this->assertSame($this->admin->id, $semana->aprovada_por);
        $this->assertNotNull($semana->aprovada_em);
        $this->assertSame(['tecnico' => 'Ana Martins', 'semana_inicio' => self::SEGUNDA], Auditoria::where('acao', 'tempo_semana_aprovada')->sole()->detalhe);
    }

    public function test_so_se_aprova_uma_semana_submetida(): void
    {
        $this->expectException(ValidationException::class);
        $this->folha->aprovar($this->admin, $this->tecnico, Carbon::parse(self::SEGUNDA));
    }

    public function test_tecnico_nao_aprova_nem_rejeita(): void
    {
        $this->folha->submeter($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA));

        foreach ([fn () => $this->folha->aprovar($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA)),
            fn () => $this->folha->rejeitar($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA), 'Não')] as $acao) {
            try {
                $acao();
                $this->fail('Devia ter sido recusado.');
            } catch (AuthorizationException) {
                $this->assertSame(EstadoSemanaTempo::Submetida, SemanaTempo::estadoDe($this->tecnico->id, self::SEGUNDA));
            }
        }
    }

    public function test_semana_aprovada_fica_fechada_a_todos_ate_ser_reaberta(): void
    {
        $registo = RegistoTempo::sole();
        $this->folha->submeter($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA));
        $this->folha->aprovar($this->admin, $this->tecnico, Carbon::parse(self::SEGUNDA));
        $gravador = app(GravadorRegistos::class);

        foreach ([$this->tecnico, $this->admin] as $quem) {
            try {
                $gravador->atualizar($quem, $registo->fresh(), ['dia' => '2026-09-08', 'duracao_seg' => 3600]);
                $this->fail('Semana aprovada não devia ser editável.');
            } catch (AuthorizationException) {
                $this->assertSame(4 * 3600, $registo->fresh()->duracao_seg);
            }
        }
        $this->assertFalse($this->folha->podeEditar($this->admin, $this->tecnico, Carbon::parse(self::SEGUNDA)));

        $this->folha->reabrir($this->admin, $this->tecnico, Carbon::parse(self::SEGUNDA));

        $this->assertSame(EstadoSemanaTempo::Reaberta, SemanaTempo::estadoDe($this->tecnico->id, self::SEGUNDA));
        $gravador->atualizar($this->tecnico, $registo->fresh(), ['dia' => '2026-09-08', 'duracao_seg' => 3600]);
        $this->assertSame(3600, $registo->fresh()->duracao_seg);
        $this->assertTrue(Auditoria::where('acao', 'tempo_semana_reaberta')->sole()->detalhe['estava_aprovada']);
    }

    // --- Rejeitar ---

    public function test_rejeitar_exige_motivo(): void
    {
        $this->folha->submeter($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA));

        try {
            $this->folha->rejeitar($this->admin, $this->tecnico, Carbon::parse(self::SEGUNDA), '   ');
            $this->fail('Sem motivo não devia rejeitar.');
        } catch (ValidationException $e) {
            $this->assertSame('Indique o motivo da rejeição: é o que o técnico vai ler.', $e->errors()['motivo'][0]);
        }

        $this->assertSame(EstadoSemanaTempo::Submetida, SemanaTempo::estadoDe($this->tecnico->id, self::SEGUNDA));
    }

    public function test_rejeitada_volta_a_ser_editavel_e_resubmeter_limpa_o_motivo(): void
    {
        $this->folha->submeter($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA));

        $this->folha->rejeitar($this->admin, $this->tecnico, Carbon::parse(self::SEGUNDA), 'Faltam as horas de quarta.');

        $semana = SemanaTempo::sole();
        $this->assertSame(EstadoSemanaTempo::Rejeitada, $semana->estado);
        $this->assertSame('Faltam as horas de quarta.', $semana->motivo_rejeicao);
        $this->assertNull(RegistoTempo::sole()->submetido_em);
        $this->assertTrue($this->folha->podeEditar($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA)));
        $this->assertSame('Faltam as horas de quarta.', Auditoria::where('acao', 'tempo_semana_rejeitada')->sole()->detalhe['motivo']);

        $this->folha->submeter($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA));

        $semana->refresh();
        $this->assertSame(EstadoSemanaTempo::Submetida, $semana->estado);
        $this->assertNull($semana->motivo_rejeicao);
        $this->assertNull($semana->rejeitada_em);
    }

    // --- Efeitos no resto da aplicação ---

    public function test_nao_se_submete_com_cronometro_a_correr(): void
    {
        $inicio = RegistoTempo::inicioDoDia('2026-09-13')->addHours(9);
        $this->registo($this->tecnico, $this->cliente, '2026-09-13', 0, ['inicio' => $inicio, 'fim' => null, 'duracao_seg' => null, 'origem' => 'cronometro']);

        $this->expectExceptionMessage('Pare o cronómetro antes de submeter a semana.');
        $this->folha->submeter($this->tecnico, $this->tecnico, Carbon::parse(self::SEGUNDA));
    }
}

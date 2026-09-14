<?php

namespace Tests\Feature;

use App\Models\RegistoTempo;
use App\Services\Tempos\GravadorRegistos;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Regras 5–7 (docs/modulo-tempos.md §2) e validações base de um registo: um só cronómetro a
// correr por técnico; contrato e intervenção do mesmo cliente; intervenção concluída avisa.
class TempoRegistoValidacaoTest extends TestCase
{
    use RefreshDatabase;

    private GravadorRegistos $gravador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gravador = app(GravadorRegistos::class);
    }

    // --- Regra 5: um só cronómetro a correr por técnico ---

    public function test_regra5_segundo_cronometro_do_mesmo_tecnico_e_recusado(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();

        $primeiro = $this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'inicio' => '2026-09-14 09:00:00', 'origem' => 'cronometro']);
        $this->assertTrue($primeiro->cronometroACorrer());
        $this->assertNull($primeiro->duracao_seg);

        $erro = $this->apanharErro(fn () => $this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'inicio' => '2026-09-14 10:00:00', 'origem' => 'cronometro']));
        $this->assertArrayHasKey('fim', $erro);
    }

    public function test_regra5_tecnicos_diferentes_podem_ter_cada_um_o_seu_cronometro(): void
    {
        $cliente = $this->cliente();

        $this->gravador->criar($this->tecnico(), ['cliente_id' => $cliente->id, 'inicio' => '2026-09-14 09:00:00']);
        $this->gravador->criar($this->tecnico(), ['cliente_id' => $cliente->id, 'inicio' => '2026-09-14 09:00:00']);

        $this->assertSame(2, RegistoTempo::whereNull('fim')->count());
    }

    public function test_regra5_parado_o_cronometro_pode_iniciar_outro_e_a_duracao_e_calculada(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();

        $cronometro = $this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'inicio' => '2026-09-14 09:00:00']);
        $this->gravador->atualizar($tecnico, $cronometro, ['fim' => '2026-09-14 10:30:15']);
        $this->assertSame(5415, $cronometro->fresh()->duracao_seg);

        $this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'inicio' => '2026-09-14 11:00:00']);
        $this->assertSame(1, RegistoTempo::whereNull('fim')->count());
    }

    public function test_regra5_cronometro_apagado_nao_conta(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();

        $cronometro = $this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'inicio' => '2026-09-14 09:00:00']);
        $this->gravador->apagar($tecnico, $cronometro);

        $this->assertTrue($this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'inicio' => '2026-09-14 10:00:00'])->exists);
    }

    public function test_regra5_a_base_de_dados_tambem_o_garante(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $linha = ['tecnico_id' => $tecnico->id, 'cliente_id' => $cliente->id, 'inicio' => now(), 'faturavel' => true, 'origem' => 'cronometro'];

        DB::table('registos_tempo')->insert($linha);

        $this->expectException(QueryException::class);
        DB::table('registos_tempo')->insert($linha);
    }

    // --- Regra 6: contrato e intervenção têm de ser do cliente ---

    public function test_regra6_contrato_de_outro_cliente_e_recusado(): void
    {
        $tecnico = $this->tecnico();
        $contratoDeOutro = $this->contrato($this->cliente());

        $erro = $this->apanharErro(fn () => $this->gravador->criar($tecnico, [
            'cliente_id' => $this->cliente()->id, 'contrato_id' => $contratoDeOutro->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600,
        ]));

        $this->assertStringContainsString('não é deste cliente', $erro['contrato_id'][0]);
    }

    public function test_regra6_intervencao_de_outro_cliente_e_recusada(): void
    {
        $tecnico = $this->tecnico();
        $intervencaoDeOutro = $this->intervencao($this->cliente());

        $erro = $this->apanharErro(fn () => $this->gravador->criar($tecnico, [
            'cliente_id' => $this->cliente()->id, 'intervencao_id' => $intervencaoDeOutro->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600,
        ]));

        $this->assertSame('A intervenção não é deste cliente.', $erro['intervencao_id'][0]);
    }

    public function test_regra6_intervencao_de_outro_contrato_do_mesmo_cliente_e_recusada(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $contratoA = $this->contrato($cliente);
        $contratoB = $this->contrato($cliente);
        $intervencaoDoB = $this->intervencao($cliente, $contratoB);

        $erro = $this->apanharErro(fn () => $this->gravador->criar($tecnico, [
            'cliente_id' => $cliente->id, 'contrato_id' => $contratoA->id, 'intervencao_id' => $intervencaoDoB->id,
            'dia' => '2026-09-08', 'duracao_seg' => 3600,
        ]));

        $this->assertSame('A intervenção não pertence a este contrato.', $erro['intervencao_id'][0]);
    }

    public function test_regra6_intervencao_sem_contrato_nao_serve_para_registo_com_contrato(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $intervencaoAvulsa = $this->intervencao($cliente);

        $erro = $this->apanharErro(fn () => $this->gravador->criar($tecnico, [
            'cliente_id' => $cliente->id, 'contrato_id' => $this->contrato($cliente)->id, 'intervencao_id' => $intervencaoAvulsa->id,
            'dia' => '2026-09-08', 'duracao_seg' => 3600,
        ]));

        $this->assertArrayHasKey('intervencao_id', $erro);
    }

    public function test_regra6_intervencao_sem_cliente_e_recusada_com_mensagem_clara(): void
    {
        $tecnico = $this->tecnico();
        $intervencaoPorAssociar = $this->intervencao(null);

        $erro = $this->apanharErro(fn () => $this->gravador->criar($tecnico, [
            'cliente_id' => $this->cliente()->id, 'intervencao_id' => $intervencaoPorAssociar->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600,
        ]));

        $this->assertStringContainsString('não tem cliente', $erro['intervencao_id'][0]);
    }

    public function test_regra6_cliente_contrato_e_intervencao_coerentes_gravam(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);
        $intervencao = $this->intervencao($cliente, $contrato);

        $registo = $this->gravador->criar($tecnico, [
            'cliente_id' => $cliente->id, 'contrato_id' => $contrato->id, 'intervencao_id' => $intervencao->id,
            'dia' => '2026-09-08', 'duracao_seg' => 3600, 'etiquetas' => ['deslocação'],
        ]);

        $this->assertSame($intervencao->id, $registo->fresh()->intervencao_id);
        $this->assertSame(['deslocação'], $registo->fresh()->etiquetas);
    }

    // --- Regra 7 (decisão 2026-09-14): intervenção concluída avisa, não bloqueia ---

    public function test_regra7_intervencao_concluida_grava_mas_avisa(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->cliente();
        $concluida = $this->intervencao($cliente, null, 'concluida');

        $registo = $this->gravador->criar($tecnico, ['cliente_id' => $cliente->id, 'intervencao_id' => $concluida->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600]);

        $this->assertTrue($registo->exists);
        $this->assertSame(['A intervenção já está concluída na Nexus Infra.'], $this->gravador->avisos($registo));
    }

    public function test_regra7_intervencao_em_curso_nao_avisa(): void
    {
        $cliente = $this->cliente();
        $registo = $this->gravador->criar($this->tecnico(), ['cliente_id' => $cliente->id, 'intervencao_id' => $this->intervencao($cliente)->id, 'dia' => '2026-09-08', 'duracao_seg' => 3600]);

        $this->assertSame([], $this->gravador->avisos($registo));
    }

    // --- Validações base ---

    public function test_cliente_e_obrigatorio(): void
    {
        $erro = $this->apanharErro(fn () => $this->gravador->criar($this->tecnico(), ['dia' => '2026-09-08', 'duracao_seg' => 3600]));

        $this->assertSame('Indique o cliente.', $erro['cliente_id'][0]);
    }

    public function test_duracao_acima_de_24_horas_e_recusada(): void
    {
        $erro = $this->apanharErro(fn () => $this->gravador->criar($this->tecnico(), ['cliente_id' => $this->cliente()->id, 'dia' => '2026-09-08', 'duracao_seg' => 86401]));

        $this->assertSame('Uma duração não pode passar de 24 horas.', $erro['duracao_seg'][0]);
    }

    public function test_fim_antes_do_inicio_e_recusado(): void
    {
        $erro = $this->apanharErro(fn () => $this->gravador->criar($this->tecnico(), ['cliente_id' => $this->cliente()->id, 'inicio' => '2026-09-08 10:00', 'fim' => '2026-09-08 09:00']));

        $this->assertArrayHasKey('duracao_seg', $erro);
    }

    public function test_registo_da_timesheet_fica_a_meia_noite_local_e_nao_se_arredonda(): void
    {
        $registo = $this->gravador->criar($this->tecnico(), ['cliente_id' => $this->cliente()->id, 'dia' => '2026-09-08', 'duracao_seg' => 1234]);

        $guardado = DB::table('registos_tempo')->where('id', $registo->id)->first();
        $this->assertSame('2026-09-07 23:00:00+00', $guardado->inicio); // meia-noite de Lisboa (verão) em UTC
        $this->assertSame(1234, $guardado->duracao_seg);
        $this->assertSame('2026-09-08', $registo->fresh()->dia()->toDateString());
    }

    /** @return array<string, list<string>> */
    private function apanharErro(callable $acao): array
    {
        try {
            $acao();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Esperava-se um erro de validação.');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\OrigemRegistoTempo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\Cronometro;
use App\Services\Tempos\FolhaSemanal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Fase 6 — cronómetro: estado no servidor, um só a correr por técnico (regra 5), trocar de tarefa
// para o anterior, descartar arranques de menos de um minuto, e as horas a aparecerem na folha no
// dia em que o cronómetro começou.
class CronometroTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private Cliente $cliente;

    private Contrato $contrato;

    private Cronometro $cronometro;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-16 09:00:00'); // quarta-feira, 10:00 em Lisboa

        $this->tecnico = $this->tecnico();
        $this->cliente = $this->cliente('Hospital Exemplo');
        $this->contrato = $this->contrato($this->cliente, 'CT-2026-001');
        $this->cronometro = app(Cronometro::class);
    }

    private function iniciar(array $extra = []): RegistoTempo
    {
        return $this->cronometro->iniciar($this->tecnico, ['cliente_id' => $this->cliente->id, 'contrato_id' => $this->contrato->id, 'descricao' => 'Manutenção UPS'] + $extra);
    }

    public function test_iniciar_grava_um_registo_a_correr_no_servidor(): void
    {
        $registo = $this->iniciar(['etiquetas' => ['remoto']]);

        $this->assertNull($registo->fim);
        $this->assertNull($registo->duracao_seg);
        $this->assertSame(OrigemRegistoTempo::Cronometro, $registo->origem);
        $this->assertTrue($registo->inicio->equalTo(now()));
        $this->assertSame(['remoto'], $registo->etiquetas);
        $this->assertTrue($this->cronometro->aCorrer($this->tecnico)->is($registo));
    }

    public function test_parar_grava_a_duracao_e_aparece_na_folha_somado_ao_dia(): void
    {
        $this->iniciar();
        Carbon::setTestNow('2026-09-16 10:30:00');
        $parado = $this->cronometro->parar($this->tecnico);

        $this->assertSame(90 * 60, $parado->duracao_seg);
        $this->assertNull($this->cronometro->aCorrer($this->tecnico));

        // Mais meia hora à mão no mesmo dia e na mesma linha: a célula soma e fica só de leitura.
        $this->registo($this->tecnico, $this->cliente, '2026-09-16', 1800, ['contrato_id' => $this->contrato->id]);
        $linha = collect(app(FolhaSemanal::class)->linhas($this->tecnico, Carbon::parse('2026-09-14')))->sole();
        $this->assertSame(2 * 3600, $linha['dias'][2]['segundos']);
        $this->assertSame(2, $linha['dias'][2]['registos']);
    }

    public function test_iniciar_outro_para_o_que_esta_a_correr(): void
    {
        $primeiro = $this->iniciar();
        Carbon::setTestNow('2026-09-16 09:45:00');
        $segundo = $this->iniciar(['descricao' => 'Outra tarefa']);

        $this->assertSame(45 * 60, $primeiro->fresh()->duracao_seg);
        $this->assertNull($segundo->fim);
        $this->assertSame(1, RegistoTempo::whereNull('fim')->count()); // regra 5
    }

    public function test_menos_de_um_minuto_e_descartado(): void
    {
        $registo = $this->iniciar();
        Carbon::setTestNow('2026-09-16 09:00:40');

        $this->assertNull($this->cronometro->parar($this->tecnico));
        $this->assertSoftDeleted($registo);
    }

    public function test_mais_de_24_horas_nao_para_sozinho(): void
    {
        $this->iniciar();
        Carbon::setTestNow('2026-09-17 09:30:00');

        $this->expectExceptionMessage('O cronómetro está a correr há mais de 24 horas.');
        $this->cronometro->parar($this->tecnico);
    }

    public function test_atravessar_a_meia_noite_fica_no_dia_em_que_comecou(): void
    {
        Carbon::setTestNow('2026-09-16 22:30:00'); // 23:30 em Lisboa
        $this->iniciar();
        Carbon::setTestNow('2026-09-17 00:30:00');
        $registo = $this->cronometro->parar($this->tecnico);

        $this->assertSame('2026-09-16', $registo->dia()->toDateString());
        $this->assertSame(2 * 3600, $registo->duracao_seg);
    }

    public function test_descartar_e_continuar_so_os_seus(): void
    {
        $this->iniciar();
        $this->cronometro->descartar($this->tecnico);
        $this->assertSame(0, RegistoTempo::count());

        $alheio = $this->registo($this->tecnico(), $this->cliente, '2026-09-15', 3600);
        $this->expectException(AuthorizationException::class);
        $this->cronometro->continuar($this->tecnico, $alheio);
    }

    public function test_continuar_recomeca_com_os_mesmos_dados(): void
    {
        $modelo = $this->registo($this->tecnico, $this->cliente, '2026-09-15', 3600, ['contrato_id' => $this->contrato->id, 'descricao' => 'Revisão', 'faturavel' => false, 'etiquetas' => ['deslocação']]);

        $novo = $this->cronometro->continuar($this->tecnico, $modelo);

        $this->assertNull($novo->fim);
        $this->assertSame([$this->cliente->id, $this->contrato->id, 'Revisão', false, ['deslocação']],
            [$novo->cliente_id, $novo->contrato_id, $novo->descricao, $novo->faturavel, $novo->etiquetas]);
    }

    public function test_sem_cliente_nao_arranca(): void
    {
        try {
            $this->cronometro->iniciar($this->tecnico, []);
            $this->fail('Sem cliente não devia arrancar.');
        } catch (ValidationException $e) {
            $this->assertSame('Indique o cliente.', $e->errors()['cliente_id'][0]);
        }
    }
}

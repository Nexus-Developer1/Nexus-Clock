<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\RegistoTempo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

// Base dos modelos: tabelas da Nexus Infra só de leitura, etiquetas text[], dias e semanas no fuso
// de Lisboa (incluindo a mudança de hora) e os scopes da especificação.
class TempoModelosTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabelas_da_nexus_infra_sao_so_de_leitura(): void
    {
        $cliente = $this->cliente('ACME');
        config(['tempos.escrever_tabelas_da_nexus_infra' => false]);

        $this->assertSame('ACME', Cliente::find($cliente->id)->nome); // ler, sim

        try {
            $cliente->update(['nome' => 'Outro nome']);
            $this->fail('Devia ter recusado gravar num cliente.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('só de leitura', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $cliente->delete();
    }

    public function test_etiquetas_vao_e_voltam_de_text_array_sem_vazios_nem_repetidas(): void
    {
        $registo = $this->registo($this->tecnico(), $this->cliente(), '2026-09-08', 60, [
            'etiquetas' => ['deslocação', ' remoto ', '', 'deslocação', 'com "aspas", e vírgula', 'barra \\ invertida'],
        ]);

        $this->assertSame(['deslocação', 'remoto', 'com "aspas", e vírgula', 'barra \\ invertida'], $registo->fresh()->etiquetas);
        $this->assertSame(1, DB::table('registos_tempo')->whereRaw("'remoto' = any(etiquetas)")->count());
        $this->assertSame([], $this->registo($this->tecnico(), $this->cliente(), '2026-09-08', 60)->fresh()->etiquetas);
    }

    public function test_dia_e_semana_no_fuso_de_lisboa_mesmo_na_mudanca_de_hora(): void
    {
        // 25/10/2026: a hora muda (volta ao inverno). A meia-noite local ainda é às 23h UTC da véspera.
        $antes = $this->registo($this->tecnico(), $this->cliente(), '2026-10-25', 60);
        $depois = $this->registo($this->tecnico(), $this->cliente(), '2026-10-26', 60);

        $this->assertSame('2026-10-24T23:00:00+00:00', $antes->inicio->toIso8601String());
        $this->assertSame('2026-10-26T00:00:00+00:00', $depois->inicio->toIso8601String());
        $this->assertSame('2026-10-25', $antes->fresh()->dia()->toDateString());
        $this->assertSame('2026-10-19', $antes->fresh()->semanaInicio()->toDateString()); // domingo → segunda anterior
        $this->assertSame('2026-10-26', $depois->fresh()->semanaInicio()->toDateString());
    }

    public function test_scopes_da_especificacao(): void
    {
        $tecnico = $this->tecnico();
        $outro = $this->tecnico();
        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);

        $this->registo($tecnico, $cliente, '2026-08-31', 100, ['contrato_id' => $contrato->id]);
        $this->registo($tecnico, $cliente, '2026-09-01', 200, ['contrato_id' => $contrato->id, 'faturavel' => false]);
        $this->registo($tecnico, $cliente, '2026-09-30', 400, ['fechado_em' => now()]);
        $this->registo($outro, $cliente, '2026-10-01', 800, ['contrato_id' => $contrato->id]);

        $this->assertSame(700, (int) RegistoTempo::doTecnico($tecnico)->sum('duracao_seg'));
        $this->assertSame(1100, (int) RegistoTempo::doContrato($contrato)->sum('duracao_seg'));
        $this->assertSame(600, (int) RegistoTempo::noPeriodo('2026-09-01', '2026-09-30')->sum('duracao_seg'));
        $this->assertSame(1300, (int) RegistoTempo::faturaveis()->sum('duracao_seg'));
        $this->assertSame(1100, (int) RegistoTempo::porFechar()->sum('duracao_seg'));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\OrigemRegistoTempo;
use App\Livewire\Relatorios\Presencas;
use App\Models\Cliente;
use App\Models\MembroEquipa;
use App\Models\User;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\Presencas as ServicoPresencas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Relatório Presenças: por pessoa e dia, entrada/saída (registos com horas), capacidade (da Equipa ou
// por omissão, nos dias de trabalho), trabalho, horas extra, em falta, saldo e pausas.
class RelatorioPresencasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private Cliente $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14/09–20/09

        $this->admin = $this->admin();
        $this->admin->update(['nome' => 'Suporte Nexus']);
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        app(GestorEquipa::class)->sincronizar();
        $this->hospital = $this->cliente('Hospital');

        // Rui: 6 h por dia, de segunda a quarta.
        MembroEquipa::where('utilizador_id', $this->rui->id)->update(['capacidade_diaria_seg' => 6 * 3600]);
        MembroEquipa::where('utilizador_id', $this->rui->id)->sole()->forceFill(['dias_trabalho' => [1, 2, 3]])->save();

        // Ana, segunda: 09:00–12:30 e 13:30–18:00 (horas reais, Lisboa = UTC+1) → 8 h, 1 h de pausa.
        $this->registo($this->ana, $this->hospital, '2026-09-14', 0, ['inicio' => '2026-09-14 08:00:00+00', 'fim' => '2026-09-14 11:30:00+00', 'duracao_seg' => 12600, 'origem' => OrigemRegistoTempo::Cronometro]);
        $this->registo($this->ana, $this->hospital, '2026-09-14', 0, ['inicio' => '2026-09-14 12:30:00+00', 'fim' => '2026-09-14 17:00:00+00', 'duracao_seg' => 16200, 'origem' => OrigemRegistoTempo::Cronometro]);
        // Ana, terça: 9 h só com duração (sem entrada/saída).
        $this->registo($this->ana, $this->hospital, '2026-09-15', 9 * 3600);
        // Rui, sábado (dia de folga): 2 h.
        $this->registo($this->rui, $this->hospital, '2026-09-19', 7200);
    }

    private function linhas(?array $membros = null, string $situacao = ''): array
    {
        return app(ServicoPresencas::class)->linhas($membros, CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-20'), $situacao);
    }

    private function dia(array $linhas, User $u, string $dia): array
    {
        return collect($linhas)->first(fn ($l) => $l['tecnico_id'] === $u->id && $l['dia']->toDateString() === $dia);
    }

    public function test_calcula_entrada_saida_capacidade_saldo_e_pausas(): void
    {
        $linhas = $this->linhas();
        $this->assertCount(3 * 7, $linhas);

        $seg = $this->dia($linhas, $this->ana, '2026-09-14');
        $this->assertSame(['09:00', '18:00'], [$seg['inicio']->format('H:i'), $seg['fim']->format('H:i')]);
        $this->assertSame([8 * 3600, 8 * 3600, 0, 0, 0, 3600], [$seg['capacidade'], $seg['trabalho'], $seg['extra'], $seg['em_falta'], $seg['saldo'], $seg['pausa']]);

        $ter = $this->dia($linhas, $this->ana, '2026-09-15');
        $this->assertNull($ter['inicio']);
        $this->assertSame([3600, 0, 3600, 0], [$ter['extra'], $ter['em_falta'], $ter['saldo'], $ter['pausa']]);

        $qua = $this->dia($linhas, $this->ana, '2026-09-16');
        $this->assertSame([0, 8 * 3600, -8 * 3600], [$qua['trabalho'], $qua['em_falta'], $qua['saldo']]);

        // Rui: capacidade própria e só nos seus dias; trabalho num dia de folga é tudo extra.
        $this->assertSame(6 * 3600, $this->dia($linhas, $this->rui, '2026-09-16')['capacidade']);
        $this->assertSame(0, $this->dia($linhas, $this->rui, '2026-09-17')['capacidade']);
        $sab = $this->dia($linhas, $this->rui, '2026-09-19');
        $this->assertSame([0, 7200, 7200], [$sab['capacidade'], $sab['extra'], $sab['saldo']]);

        $total = ServicoPresencas::somar($this->linhas([$this->ana->id]));
        $this->assertSame(['capacidade' => 40 * 3600, 'trabalho' => 17 * 3600, 'extra' => 3600, 'em_falta' => 24 * 3600, 'saldo' => -23 * 3600, 'pausa' => 3600], $total);
        $this->assertSame('−23:00:00', ServicoPresencas::saldo($total['saldo']));
        $this->assertSame('+1:00:00', ServicoPresencas::saldo(3600));

        // Situações.
        $contar = fn (string $s) => count($this->linhas(situacao: $s));
        $this->assertSame(3, $contar('com_registos'));
        $this->assertSame(2, $contar('extra')); // Ana terça, Rui sábado
        $this->assertSame(3 + 3 + 5, $contar('em_falta')); // Ana qua–sex, Rui seg–qua, Suporte seg–sex
        $this->assertSame(0, count(array_filter($this->linhas(situacao: 'sem_registos'), fn ($l) => $l['capacidade'] === 0)));
    }

    public function test_tecnico_so_ve_a_sua_linha(): void
    {
        $this->actingAs($this->ana)->get('/relatorios/presencas')->assertOk()->assertSee('Presenças — Nexus Suporte', false);

        Livewire::actingAs($this->ana)->withQueryParams(['membros' => [(string) $this->rui->id]])->test(Presencas::class)
            ->assertSee('Ana Martins')
            ->assertDontSee('Rui Costa')
            ->assertSee('7 linhas')
            ->assertSee('17:00:00');
    }

    public function test_admin_filtra_agrupa_ordena_pagina_e_exporta(): void
    {
        $pagina = Livewire::actingAs($this->admin)->test(Presencas::class)
            ->assertSee('Esta semana')
            ->assertSee('21 linhas')
            ->assertSee('Presenças')
            ->assertDontSee('Atribuições')
            ->set('membros', [(string) $this->ana->id])
            ->assertSee('7 linhas')
            ->assertSee('09:00')
            ->assertSee('−8:00:00')
            ->set('situacao', 'extra')
            ->assertSee('1 linha')
            ->call('limparFiltros')
            ->assertSee('21 linhas')
            ->call('ordenarPor', 'trabalho')
            ->assertSet('ordem', '-trabalho');

        // (Depois de uma ação, o assertSeeInOrder compara com o JSON, onde "/" vem escapado.)
        preg_match_all('/\d{2}\/09\/2026/', $pagina->html(), $datas);
        $this->assertSame(['15/09/2026', '14/09/2026', '19/09/2026'], array_slice($datas[0], 0, 3));

        $pagina->set('agrupar', 'membro')
            ->assertSeeInOrder(['Ana Martins', '7 linhas', 'Rui Costa', '7 linhas', 'Suporte Nexus', '7 linhas'])
            ->call('escolherPeriodo', 'mes')
            ->assertSee('Este mês')
            ->assertSee('90 linhas')
            ->assertSee('Página 1 de 2')
            ->call('irPara', 2)
            ->assertSee('51–90 de 90')
            ->call('anterior')
            ->assertSet('pagina', 1)
            ->call('exportar')
            ->assertFileDownloaded('presencas-20260801-20260831.csv');
    }
}

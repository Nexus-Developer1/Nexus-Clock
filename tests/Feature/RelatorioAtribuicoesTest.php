<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Atribuicoes;
use App\Models\AtribuicaoTempo;
use App\Models\Auditoria;
use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Tempos\GestorAtribuicoes;
use App\Services\Tempos\RelatorioAtribuicoes;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Atribuições: pessoa × projeto × datas × horas por dia; o relatório compara o agendado (só a parte
// dentro do período, dias úteis ou todos) com o registado nesse projeto, com diferença e estado.
class RelatorioAtribuicoesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private ProjetoTempo $obra;

    private ProjetoTempo $interno;

    private GestorAtribuicoes $gestor;

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
        $cliente = ClienteTempo::create(['nome' => 'Hospital']);
        $this->obra = ProjetoTempo::create(['nome' => 'Obra', 'cliente_id' => $cliente->id]);
        $this->interno = ProjetoTempo::create(['nome' => 'Interno']);
        $this->gestor = app(GestorAtribuicoes::class);

        $nexus = $this->cliente('Nexus');
        // Ana: 2 h/dia na obra de 10/09 a 16/09 (dias úteis) e 1 h/dia no interno, sábado incluído.
        $this->gestor->criar($this->admin, ['utilizador_id' => $this->ana->id, 'projeto_id' => $this->obra->id, 'de' => '2026-09-10', 'ate' => '2026-09-16', 'horas_dia' => '2']);
        $this->gestor->criar($this->admin, ['utilizador_id' => $this->ana->id, 'projeto_id' => $this->interno->id, 'de' => '2026-09-18', 'ate' => '2026-09-19', 'horas_dia' => '1', 'fins_de_semana' => true]);
        // Rui: 4 h na obra, dia 25 (ainda não começou).
        $this->gestor->criar($this->admin, ['utilizador_id' => $this->rui->id, 'projeto_id' => $this->obra->id, 'de' => '2026-09-25', 'ate' => '2026-09-25', 'horas_dia' => '4']);

        $this->registo($this->ana, $nexus, '2026-09-14', 5 * 3600, ['projeto_id' => $this->obra->id]);   // abaixo (agendado 6 h esta semana)
        $this->registo($this->rui, $nexus, '2026-09-15', 1800, ['projeto_id' => $this->interno->id]);    // sem atribuição
        $this->registo($this->rui, $nexus, '2026-09-15', 3600);                                          // sem projeto: não conta
    }

    private function gerar(string $de = '2026-09-14', string $ate = '2026-09-20', string $g1 = 'membro', ?string $g2 = 'projeto', array $filtros = [], bool $semTempo = false): array
    {
        return app(RelatorioAtribuicoes::class)->gerar($filtros, CarbonImmutable::parse($de), CarbonImmutable::parse($ate), $g1, $g2, $semTempo);
    }

    public function test_criar_valida_e_so_quem_gere_a_equipa(): void
    {
        $this->assertSame(3, Auditoria::where('acao', 'tempo_atribuicao_criada')->count());

        try {
            $this->gestor->criar($this->admin, ['utilizador_id' => $this->utilizador(null)->id, 'projeto_id' => 999, 'de' => '2026-09-20', 'ate' => '2026-09-10', 'horas_dia' => '25']);
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame([
                'utilizador_id' => ['Escolha um membro da equipa.'],
                'projeto_id' => ['Escolha um projeto.'],
                'ate' => ['A data de fim não pode ser antes da de início.'],
                'horas_dia' => ['Indique as horas por dia (ex.: 4 ou 7:30), até 24.'],
            ], $e->errors());
        }

        $this->interno->forceFill(['arquivado_em' => now()])->save();
        try {
            $this->gestor->criar($this->admin, ['utilizador_id' => $this->ana->id, 'projeto_id' => $this->interno->id, 'de' => '2026-09-01', 'ate' => '2027-09-10', 'horas_dia' => '7:30']);
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame(['projeto_id' => ['O projeto «Interno» está arquivado.'], 'ate' => ['No máximo um ano.']], $e->errors());
        }

        // Uma atribuição de um projeto entretanto arquivado continua editável.
        $a = AtribuicaoTempo::where('projeto_id', $this->interno->id)->sole();
        $this->gestor->atualizar($this->admin, $a, ['horas_dia' => '1:30', 'projeto_id' => $this->interno->id]);
        $this->assertSame(5400, $a->fresh()->horas_dia_seg);

        $this->expectException(AuthorizationException::class);
        $this->gestor->criar($this->ana, ['utilizador_id' => $this->ana->id, 'projeto_id' => $this->obra->id, 'de' => '2026-09-01', 'ate' => '2026-09-01', 'horas_dia' => '1']);
    }

    public function test_agendado_so_dentro_do_periodo_diferenca_e_estado(): void
    {
        $r = $this->gerar();

        // Ana: obra 14–16/09 = 3 dias × 2 h (a atribuição começou antes da semana); interno 18 e 19 = 2 × 1 h.
        $this->assertSame([6 * 3600 + 2 * 3600, 5 * 3600 + 1800], [$r['agendado'], $r['registado']]);
        $this->assertSame(['Ana Martins', 'Rui Costa'], array_column($r['grupos'], 'nome'));

        $ana = collect($r['grupos'][0]['filhos'])->keyBy('nome');
        $this->assertSame([6 * 3600, 5 * 3600, -3600, 'abaixo'], [$ana['Obra']['agendado'], $ana['Obra']['registado'], $ana['Obra']['diferenca'], $ana['Obra']['estado']]);
        $this->assertSame([7200, 0, 'por_comecar'], [$ana['Interno']['agendado'], $ana['Interno']['registado'], $ana['Interno']['estado']]);

        $rui = collect($r['grupos'][1]['filhos'])->keyBy('nome');
        $this->assertSame([0, 1800, 'sem_atribuicao'], [$rui['Interno']['agendado'], $rui['Interno']['registado'], $rui['Interno']['estado']]);
        $this->assertArrayNotHasKey('Obra', $rui->all()); // a atribuição do Rui é noutra semana

        // Por projeto › cliente e filtro por cliente (do projeto).
        $porProjeto = collect($this->gerar(g1: 'projeto', g2: 'cliente')['grupos'])->keyBy('nome');
        $this->assertSame('Hospital', $porProjeto['Obra']['filhos'][0]['nome']);
        $this->assertSame('Sem cliente', $porProjeto['Interno']['filhos'][0]['nome']);
        $this->assertSame(['Obra'], array_column($this->gerar(g1: 'projeto', filtros: ['clientes' => [$this->obra->cliente_id]])['grupos'], 'nome'));

        // Mostrar quem não tem tempo.
        $this->assertSame(['Ana Martins', 'Rui Costa', 'Suporte Nexus'], array_column($this->gerar(semTempo: true)['grupos'], 'nome'));
        $this->assertSame('sem_tempo', $this->gerar(semTempo: true)['grupos'][2]['estado']);

        // Estados.
        $hoje = CarbonImmutable::parse('2026-09-17');
        $d = fn (string $x) => CarbonImmutable::parse($x);
        $this->assertSame('cumprida', RelatorioAtribuicoes::estado(3600, 3600, $d('2026-09-01'), $d('2026-09-30'), $hoje));
        $this->assertSame('acima', RelatorioAtribuicoes::estado(3600, 3601, $d('2026-09-01'), $d('2026-09-02'), $hoje));
        $this->assertSame('em_curso', RelatorioAtribuicoes::estado(3600, 0, $d('2026-09-10'), $d('2026-09-30'), $hoje));
        $this->assertSame('abaixo', RelatorioAtribuicoes::estado(3600, 10, $d('2026-09-01'), $d('2026-09-16'), $hoje));
    }

    public function test_pagina_admin_cria_altera_apaga_ordena_e_exporta(): void
    {
        $pagina = Livewire::actingAs($this->admin)->test(Atribuicoes::class)
            ->assertSee('Esta semana')
            ->assertSee('8:00:00')
            ->assertSee('5:30:00')
            ->assertSee('Abaixo do agendado')
            ->assertSee('Sem atribuição')
            ->assertSee('Atribuições no período')
            ->call('nova')
            ->assertSet('formulario.de', '2026-09-14')
            ->call('guardar')
            ->assertHasErrors(['formulario.utilizador_id', 'formulario.projeto_id', 'formulario.horas_dia'])
            ->set('formulario.utilizador_id', (string) $this->rui->id)
            ->set('formulario.projeto_id', (string) $this->interno->id)
            ->set('formulario.horas_dia', '0:30')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSee('Atribuição criada.')
            ->assertSee('2:30:00') // 0:30 × 5 dias úteis
            ->assertSee('Em curso')
            ->call('ordenarPor', 'diferenca')
            ->assertSet('ordem', '-diferenca')
            ->assertSeeInOrder(['Rui Costa', 'Ana Martins'])
            ->set('semTempo', true)
            ->assertSee('Sem tempo');

        $nova = AtribuicaoTempo::where('horas_dia_seg', 1800)->sole();
        $this->assertSame(['2026-09-14', '2026-09-20', false], [$nova->de->toDateString(), $nova->ate->toDateString(), $nova->fins_de_semana]);

        $pagina->call('editar', $nova->id)
            ->assertSet('formulario.horas_dia', '0:30')
            ->set('formulario.horas_dia', '1')
            ->call('guardar')
            ->assertSee('Atribuição guardada.')
            ->call('apagar', $nova->id)
            ->assertSee('Atribuição apagada.')
            ->call('exportar')
            ->assertFileDownloaded('atribuicoes-20260914-20260920.csv');

        $this->assertModelMissing($nova);
        $this->actingAs($this->admin)->get('/relatorios/tarefas')->assertRedirect('/relatorios/atribuicoes');
    }

    public function test_tecnico_so_ve_as_suas_e_nao_gere(): void
    {
        $this->actingAs($this->ana)->get('/relatorios/atribuicoes')->assertOk()->assertSee('Atribuições — Nexus Tempos', false);

        Livewire::actingAs($this->ana)->withQueryParams(['membros' => [(string) $this->rui->id], 'sem_tempo' => '1'])->test(Atribuicoes::class)
            ->assertSee('Ana Martins')
            ->assertDontSee('Rui Costa')
            ->assertDontSee('Suporte Nexus')
            ->assertDontSee('Nova atribuição')
            ->assertDontSeeHtml('aria-label="Alterar atribuição"')
            ->call('apagar', AtribuicaoTempo::first()->id)
            ->assertForbidden();
    }
}

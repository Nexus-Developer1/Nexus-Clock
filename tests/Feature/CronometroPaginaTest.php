<?php

namespace Tests\Feature;

use App\Livewire\Tempos\Cronometro as PaginaCronometro;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Página Cronómetro: barra com o que se está a fazer (cronómetro no servidor ou horas à mão) e os
// registos da semana por dia, com continuar, alterar, duplicar e apagar. Cada um só vê as suas.
class CronometroPaginaTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $rui;

    private ProjetoTempo $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14/09–20/09

        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        $this->obra = ProjetoTempo::create(['nome' => 'Obra']);
    }

    private function pagina()
    {
        return Livewire::actingAs($this->ana)->test(PaginaCronometro::class);
    }

    public function test_comecar_grava_o_que_esta_na_barra_e_parar_fecha_o_registo(): void
    {
        $pagina = $this->pagina()
            ->set('descricao', 'Manutenção dos servidores')
            ->set('barraProjeto', (string) $this->obra->id)
            ->set('barraEtiquetas', 'remoto, urgente')
            ->call('comecar')
            ->assertSet('erro', null);

        $registo = RegistoTempo::sole();
        $this->assertSame(
            [$this->ana->id, 'Manutenção dos servidores', $this->obra->id, ['remoto', 'urgente'], true, null],
            [$registo->tecnico_id, $registo->descricao, $registo->projeto_id, $registo->etiquetas, $registo->faturavel, $registo->fim]
        );

        // Enquanto corre, o que se escreve na barra vai para o registo.
        $pagina->set('descricao', 'Servidores e firewall')->set('barraFaturavel', false);
        $this->assertSame(['Servidores e firewall', false], [$registo->fresh()->descricao, $registo->fresh()->faturavel]);

        Carbon::setTestNow('2026-09-17 11:30:00');
        $pagina->call('parar')
            ->assertSee('Registo de 1:30:00 gravado.')
            ->assertSet('descricao', '')
            ->assertSee('Servidores e firewall');

        $this->assertSame(5400, $registo->fresh()->duracao_seg);
    }

    public function test_cronometro_de_menos_de_um_minuto_e_descartado_e_descartar_apaga(): void
    {
        $this->pagina()->call('comecar')->call('parar')
            ->assertSee('Cronómetro descartado: durou menos de um minuto.');
        $this->assertSame(0, RegistoTempo::count());

        $this->pagina()->call('comecar')->call('descartar')
            ->assertSee('Cronómetro descartado.');
        $this->assertSame(0, RegistoTempo::count());
    }

    public function test_comeca_sem_projeto_nem_descricao(): void
    {
        $this->pagina()->call('comecar')->assertSet('erro', null)->assertSee('Parar');

        $registo = RegistoTempo::sole();
        $this->assertSame([null, null, null, null], [$registo->projeto_id, $registo->descricao, $registo->cliente_id, $registo->fim]);
    }

    public function test_modo_manual_acrescenta_as_horas_indicadas(): void
    {
        $this->pagina()
            ->set('modo', 'manual')
            ->set('descricao', 'Visita')
            ->set('manualInicio', '09:00')
            ->set('manualFim', '')
            ->call('acrescentarManual')
            ->assertSet('erro', 'Indique a hora de início e a de fim (ou só a duração).')
            ->set('manualFim', '11:15')
            ->call('acrescentarManual')
            ->assertSee('Registo acrescentado.');

        $registo = RegistoTempo::sole();
        $this->assertSame([8100, 'Visita', '09:00'], [
            $registo->duracao_seg, $registo->descricao, $registo->inicio->setTimezone(config('tempos.fuso'))->format('H:i'),
        ]);
    }

    public function test_lista_da_semana_so_mostra_as_minhas_horas_e_navega(): void
    {
        $cliente = $this->cliente('Hospital');
        $this->registo($this->ana, $cliente, '2026-09-15', 3600, ['descricao' => 'Reunião', 'projeto_id' => $this->obra->id]);
        $this->registo($this->ana, $cliente, '2026-09-16', 1800, ['descricao' => 'Chamada']);
        $this->registo($this->rui, $cliente, '2026-09-15', 7200, ['descricao' => 'Do Rui']);
        $this->registo($this->ana, $cliente, '2026-09-08', 900, ['descricao' => 'Semana passada']);

        $this->pagina()
            ->assertSee('Esta semana')
            ->assertSee('1:30:00')          // total da semana
            ->assertSee('Reunião')
            ->assertSee('Chamada')
            ->assertSee('Obra')
            ->assertDontSee('Do Rui')
            ->assertDontSee('Semana passada')
            ->assertSeeInOrder(['Chamada', 'Reunião'])  // dias do mais recente para o mais antigo
            ->call('semanaAnterior')
            ->assertSee('Semana passada')
            ->assertSee('0:15:00')
            ->assertDontSee('Reunião')
            ->call('estaSemana')
            ->assertSee('Esta semana')
            ->assertSee('Reunião');
    }

    public function test_filtro_da_lista_por_projeto_nao_mexe_na_barra(): void
    {
        $cliente = $this->cliente('Hospital');
        $this->registo($this->ana, $cliente, '2026-09-15', 3600, ['descricao' => 'Na obra', 'projeto_id' => $this->obra->id]);
        $this->registo($this->ana, $cliente, '2026-09-16', 1800, ['descricao' => 'Sem nada']);
        $this->registo($this->rui, $cliente, '2026-09-15', 7200, ['descricao' => 'Obra do Rui', 'projeto_id' => $this->obra->id]);

        $this->pagina()
            // A barra (registo a começar) e o filtro (lista) dizem coisas diferentes — notas §46.
            ->assertSee('Escolher projeto…')->assertSee('Todos os projetos')
            ->assertSee('Total da semana')->assertSee('1:30:00')
            ->set('filtroProjeto', (string) $this->obra->id)
            ->assertSee('Na obra')->assertDontSee('Sem nada')->assertDontSee('Obra do Rui')
            ->assertSee('Total do filtro')->assertSee('1:00:00')
            ->assertSet('barraProjeto', '')
            ->set('filtroProjeto', '0')
            ->assertSee('Sem nada')->assertDontSee('Na obra')->assertSee('0:30:00')
            ->set('filtroProjeto', 'abc')
            ->assertSet('filtroProjeto', '')
            ->assertSee('Na obra')->assertSee('Sem nada');

        // Filtro sem horas: diz porquê e deixa voltar a todos.
        $vazio = ProjetoTempo::create(['nome' => 'Vazio']);
        $this->pagina()->set('filtroProjeto', (string) $vazio->id)
            ->assertSee('Sem horas deste projeto nesta semana')
            ->assertSee('Ver todos os projetos');
    }

    public function test_continuar_alterar_duplicar_e_apagar(): void
    {
        $cliente = $this->cliente('Hospital');
        $registo = $this->registo($this->ana, $cliente, '2026-09-15', 3600, ['descricao' => 'Reunião', 'projeto_id' => $this->obra->id]);
        $doRui = $this->registo($this->rui, $cliente, '2026-09-15', 3600, ['descricao' => 'Do Rui']);

        $pagina = $this->pagina()->call('continuar', $registo->id)->assertSet('erro', null);
        $aCorrer = RegistoTempo::whereNull('fim')->sole();
        $this->assertSame([$this->ana->id, 'Reunião', $this->obra->id], [$aCorrer->tecnico_id, $aCorrer->descricao, $aCorrer->projeto_id]);

        $pagina->call('descartar')
            ->call('editar', $registo->id)
            ->assertSet('formulario.descricao', 'Reunião')
            ->set('formulario.descricao', 'Reunião com o cliente')
            ->call('guardar')
            ->assertSee('Registo alterado.')
            ->call('duplicar', $registo->id)
            ->assertSee('Registo duplicado.');

        $this->assertSame('Reunião com o cliente', $registo->fresh()->descricao);
        $this->assertSame(2, RegistoTempo::where('tecnico_id', $this->ana->id)->count());

        $pagina->call('apagar', $registo->id)->assertSee('Registo apagado.');
        $this->assertSoftDeleted($registo);

        // Não mexe nos registos dos outros.
        $pagina->call('continuar', $doRui->id)->assertSet('erro', 'Só pode continuar os seus registos.');
    }

    public function test_pagina_abre_na_raiz_e_retoma_o_cronometro_a_correr(): void
    {
        RegistoTempo::create([
            'tecnico_id' => $this->ana->id, 'projeto_id' => $this->obra->id, 'descricao' => 'A decorrer',
            'inicio' => CarbonImmutable::parse('2026-09-17 08:00:00'), 'fim' => null, 'duracao_seg' => 0,
        ]);

        $this->actingAs($this->ana)->get('/')->assertOk()->assertSee('Cronómetro — Nexus Suporte', false);

        $this->pagina()
            ->assertSet('descricao', 'A decorrer')
            ->assertSet('barraProjeto', (string) $this->obra->id)
            ->assertSee('Parar');
    }
}

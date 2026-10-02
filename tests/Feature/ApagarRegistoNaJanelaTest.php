<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Detalhado;
use App\Livewire\Tempos\Calendario;
use App\Livewire\Tempos\Cronometro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Botão Apagar na janela «Alterar registo» (a mesma no Cronómetro, no Calendário e no Detalhado):
// apaga o registo e fecha a janela; se não puder (outra pessoa, mês fechado…), diz porquê na janela.
class ApagarRegistoNaJanelaTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00');

        $this->ana = $this->tecnico();
    }

    public function test_apagar_na_janela_apaga_e_fecha_em_todas_as_paginas(): void
    {
        foreach ([Cronometro::class, Calendario::class, Detalhado::class] as $pagina) {
            $registo = $this->registo($this->ana, $this->cliente('Hospital'), '2026-09-16', 3600, ['descricao' => 'Para apagar']);

            Livewire::actingAs($this->ana)->test($pagina)
                ->call('editar', $registo->id)
                ->assertSee('Apagar')
                ->call('apagarDoFormulario')
                ->assertSet('editarId', null)
                ->assertSee('Registo apagado.');

            $this->assertSoftDeleted($registo);
        }
    }

    public function test_janela_de_acrescentar_nao_tem_apagar(): void
    {
        Livewire::actingAs($this->ana)->test(Calendario::class)
            ->call('novo')
            ->assertDontSeeHtml('wire:click="apagarDoFormulario"');
    }

    public function test_sem_permissao_a_janela_fica_aberta_com_o_motivo(): void
    {
        $doRui = $this->registo($this->tecnico(), $this->cliente('Hospital'), '2026-09-16', 3600);
        $admin = $this->admin();

        // Um admin abre o registo de outra pessoa; entretanto perde o direito de o apagar
        // (aqui: o registo já foi faturado).
        $doRui->forceFill(['faturado_em' => now()])->save();

        Livewire::actingAs($admin)->test(Calendario::class)
            ->set('editarId', $doRui->id)
            ->call('apagarDoFormulario')
            ->assertSet('editarId', $doRui->id)
            ->assertHasErrors('formulario.geral')
            ->assertSet('erro', null);

        $this->assertNotSoftDeleted($doRui);
    }
}

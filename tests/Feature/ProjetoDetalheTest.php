<?php

namespace Tests\Feature;

use App\Models\AtribuicaoTempo;
use App\Models\ClienteTempo;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\GestorProjetos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Página de cada projeto (notas §47): abre pelo nome na lista de Projetos e na página do cliente;
// mostra dados, horas, progresso, equipa e atribuições; privado só para membros; dinheiro só para
// quem gere.
class ProjetoDetalheTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private ClienteTempo $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00');
        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        app(GestorEquipa::class)->sincronizar();
        $this->hospital = ClienteTempo::create(['nome' => 'Hospital da Luz']);
    }

    private function projeto(array $dados): ProjetoTempo
    {
        return app(GestorProjetos::class)->criar($this->admin, $dados + ['cliente_id' => $this->hospital->id]);
    }

    public function test_pagina_do_projeto_para_um_tecnico(): void
    {
        $obra = $this->projeto(['nome' => 'Manutenção UPS', 'estimativa' => '2', 'taxa' => '55', 'nota' => "Contrato anual\npiso -1"]);
        $infra = $this->cliente('Infra');
        $this->registo($this->ana, $infra, '2026-09-14', 3600, ['projeto_id' => $obra->id, 'faturavel' => true]);
        $this->registo($this->rui, $infra, '2026-09-15', 7200, ['projeto_id' => $obra->id, 'faturavel' => false]);
        RegistoTempo::create(['tecnico_id' => $this->ana->id, 'projeto_id' => $obra->id, 'inicio' => '2026-09-17 08:00:00+00']); // a correr: não conta
        AtribuicaoTempo::forceCreate(['utilizador_id' => $this->rui->id, 'projeto_id' => $obra->id, 'de' => '2026-09-14', 'ate' => '2026-09-30',
            'horas_dia_seg' => 4 * 3600, 'fins_de_semana' => false, 'nota' => 'Semana de manutenção', 'criado_por' => $this->admin->id]);
        AtribuicaoTempo::forceCreate(['utilizador_id' => $this->ana->id, 'projeto_id' => $obra->id, 'de' => '2026-08-01', 'ate' => '2026-08-05',
            'horas_dia_seg' => 3600, 'fins_de_semana' => false, 'nota' => 'Já acabou', 'criado_por' => $this->admin->id]);

        // Na lista, o nome é um link para a página.
        // Na lista, a linha toda abre a página (e o nome continua a ser um link).
        $this->actingAs($this->ana)->get(route('projetos'))->assertOk()->assertSee(route('projetos.ver', $obra))
            ->assertSee('@click="Livewire.navigate(', false);

        $this->actingAs($this->ana)->get(route('projetos.ver', $obra))->assertOk()
            ->assertSee('Manutenção UPS — Nexus Suporte', false)
            ->assertSee(route('clientes.ver', $this->hospital))
            ->assertSee('3:00:00')                         // total, das duas pessoas
            ->assertSee('33% do total')                    // 1 h faturável de 3 h
            ->assertSee('150%')->assertSee('3:00 de 2:00 estimadas')
            ->assertSeeInOrder(['Rui Costa', '2:00:00', 'Ana Martins', '1:00:00'])
            ->assertSee('Semana de manutenção')->assertSee('Em curso')->assertDontSee('Já acabou')
            ->assertSee('piso -1')
            // Dinheiro é de quem gere.
            ->assertDontSee('Valor faturável')->assertDontSee('55,00 €');

        $this->actingAs($this->admin)->get(route('projetos.ver', $obra))->assertOk()
            ->assertSee('Valor faturável')->assertSee('55,00 €/h')->assertSee('55,00 €');
    }

    public function test_privado_so_para_membros_e_arquivado_abre_apagado_nao(): void
    {
        $membroRui = MembroEquipa::where('utilizador_id', $this->rui->id)->sole();
        $segredo = $this->projeto(['nome' => 'Projeto Secreto', 'publico' => false, 'membros' => [$membroRui->id]]);

        // Quem não é membro não sabe que existe.
        $this->actingAs($this->ana)->get(route('projetos.ver', $segredo))->assertNotFound();
        $this->actingAs($this->rui)->get(route('projetos.ver', $segredo))->assertOk()->assertSee('Privado')->assertSee('Rui Costa');
        $this->actingAs($this->admin)->get(route('projetos.ver', $segredo))->assertOk();

        // Na página do cliente, os projetos também abrem a sua página.
        $this->actingAs($this->rui)->get(route('clientes.ver', $this->hospital))->assertSee(route('projetos.ver', $segredo));

        app(GestorProjetos::class)->arquivar($this->admin, [$segredo->id]);
        $this->actingAs($this->rui)->get(route('projetos.ver', $segredo))->assertOk()->assertSee('Arquivado');
        app(GestorProjetos::class)->apagar($this->admin, [$segredo->id]);
        $this->actingAs($this->admin)->get(route('projetos.ver', $segredo))->assertNotFound();
    }
}

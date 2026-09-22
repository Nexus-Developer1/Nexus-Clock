<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Menu da aplicação (como o do Clockify): Painel; Relatórios com as secções Tempo (Resumo, Detalhado,
// Semanal, Partilhados), Equipa (Presenças, Atribuições) e Despesas (Detalhado); Projetos, Equipa e
// Clientes. Cada página abre e marca-se ativa.
class MenuPaginasTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_tem_os_relatorios_por_seccao_projetos_equipa_e_clientes(): void
    {
        $this->actingAs($this->tecnico())->get(route('painel'))->assertOk()
            ->assertSeeInOrder([
                'Cronómetro', 'Calendário', 'Painel', 'Relatórios',
                'Tempo', 'Resumo', 'Detalhado', 'Semanal', 'Partilhados',
                'Equipa', 'Presenças', 'Atribuições',
                'Despesas', 'Detalhado',
                'Projetos', 'Equipa', 'Clientes',
            ])
            ->assertDontSee('Folha de horas')
            ->assertDontSee('Quiosques')->assertDontSee('Aprovações')->assertDontSee('Etiquetas')->assertDontSee('Gerir');
    }

    public function test_cada_pagina_abre_e_fica_ativa_no_menu(): void
    {
        $this->actingAs($this->admin());

        $paginas = [
            'cronometro' => 'Cronómetro', 'calendario' => 'Calendário', 'painel' => 'Painel', 'relatorios.resumo' => 'Resumo', 'relatorios.detalhado' => 'Detalhado',
            'relatorios.semanal' => 'Semanal', 'relatorios.partilhados' => 'Partilhados', 'relatorios.presencas' => 'Presenças',
            'relatorios.atribuicoes' => 'Atribuições', 'relatorios.despesas' => 'Despesas',
            'projetos' => 'Projetos',
            'equipa' => 'Equipa', 'clientes' => 'Clientes',
        ];

        foreach ($paginas as $rota => $titulo) {
            $this->get(route($rota))->assertOk()
                ->assertSee('<title>'.$titulo.' — Nexus Suporte</title>', false)
                ->assertSee('aria-current="page"', false);
        }
    }

    public function test_relatorios_abre_no_resumo_e_sem_acesso_vai_ao_portal(): void
    {
        $this->actingAs($this->tecnico())->get('/relatorios')->assertRedirect('/relatorios/resumo');

        $this->actingAs($this->utilizador(null))->get(route('clientes'))->assertRedirect(config('app.portal_url'));
    }
}

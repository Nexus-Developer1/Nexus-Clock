<?php

namespace Tests\Feature;

use App\Models\AtribuicaoTempo;
use App\Models\MembroEquipa;
use App\Models\User;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\GestorProjetos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Página de cada membro da equipa (notas §48): abre pela linha em Equipa › Membros; dados, horas da
// semana e do mês, projetos do mês e atribuições; os projetos privados de quem vê não aparecem
// pelo nome; taxas só para quem gere.
class MembroDetalheTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14–20/09; setembro
        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        app(GestorEquipa::class)->sincronizar();
    }

    private function membro(User $u): MembroEquipa
    {
        return MembroEquipa::where('utilizador_id', $u->id)->sole();
    }

    public function test_pagina_de_um_colega_sem_revelar_os_projetos_privados(): void
    {
        $projetos = app(GestorProjetos::class);
        $obra = $projetos->criar($this->admin, ['nome' => 'Obra Pública']);
        $segredo = $projetos->criar($this->admin, ['nome' => 'Projeto Secreto', 'publico' => false, 'membros' => [$this->membro($this->rui)->id]]);
        $infra = $this->cliente('Infra');
        $this->registo($this->rui, $infra, '2026-09-15', 3600, ['projeto_id' => $obra->id, 'faturavel' => true]);   // esta semana
        $this->registo($this->rui, $infra, '2026-09-02', 7200, ['projeto_id' => $segredo->id, 'faturavel' => true]); // mês, não semana
        $this->registo($this->rui, $infra, '2026-08-20', 9999, ['projeto_id' => $obra->id]);                        // agosto: fora
        AtribuicaoTempo::forceCreate(['utilizador_id' => $this->rui->id, 'projeto_id' => $segredo->id, 'de' => '2026-09-14', 'ate' => '2026-09-30',
            'horas_dia_seg' => 4 * 3600, 'fins_de_semana' => false, 'criado_por' => $this->admin->id]);
        app(GestorEquipa::class)->mudarTaxa($this->admin, $this->membro($this->rui), 'custo', '31,40', '2026-01-01');
        app(GestorEquipa::class)->atualizarCampo($this->admin, $this->membro($this->rui), 'capacidade', '8');

        // Na lista, a linha toda abre o membro (e o nome é um link).
        $this->actingAs($this->ana)->get(route('equipa'))->assertOk()
            ->assertSee(route('equipa.ver', $this->membro($this->rui)))->assertSee('Livewire.navigate(', false);

        // A Ana (técnica) vê o Rui…
        $this->actingAs($this->ana)->get(route('equipa.ver', $this->membro($this->rui)))->assertOk()
            ->assertSee('Rui Costa — Nexus Suporte', false)
            ->assertSee('1:00:00')->assertSee('de 40:00 de capacidade')   // semana: 1 h de 8 h × 5 dias
            ->assertSee('3:00:00')                                          // setembro: 1 h + 2 h (agosto fora)
            ->assertSee('Obra Pública')->assertSee(route('projetos.ver', $obra))
            // …mas o projeto privado de que não é membro não aparece pelo nome, nem com link.
            ->assertDontSee('Projeto Secreto')->assertDontSee(route('projetos.ver', $segredo))
            ->assertSee('Outros projetos (privados)')->assertSee('2:00:00')
            ->assertSee('Projeto privado')                                  // a atribuição também
            // Taxas são de quem gere.
            ->assertDontSee('Taxa de custo')->assertDontSee('31,40');

        // O admin vê tudo.
        $this->actingAs($this->admin)->get(route('equipa.ver', $this->membro($this->rui)))->assertOk()
            ->assertSee('Projeto Secreto')->assertDontSee('Outros projetos (privados)')
            ->assertSee('Taxa de custo')->assertSee('31,40 €/h');
    }

    public function test_limitado_e_inexistente(): void
    {
        $limitado = MembroEquipa::create(['limitado' => true, 'nome' => 'Técnico Externo', 'email' => 'externo@exemplo.pt']);

        $this->actingAs($this->ana)->get(route('equipa.ver', $limitado))->assertOk()
            ->assertSee('Técnico Externo')->assertSee('não regista horas');

        $this->actingAs($this->ana)->get('/equipa/999999')->assertNotFound();
    }
}

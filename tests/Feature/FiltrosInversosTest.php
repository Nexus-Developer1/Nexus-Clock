<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Detalhado;
use App\Livewire\Relatorios\Partilhado;
use App\Livewire\Relatorios\Resumo;
use App\Livewire\Relatorios\Semanal;
use App\Livewire\Tempos\Calendario;
use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Tempos\GestorPartilhados;
use App\Services\Tempos\ResumoTempos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// «Incluir · Excluir» nos filtros: Equipa, Cliente, Projeto e Etiqueta nos relatórios de tempo
// (Resumo, Detalhado, Semanal, partilhado e email) e Projeto no Calendário. Excluir = tudo menos o
// escolhido; os registos sem projeto/cliente/etiquetas ficam, a não ser que «Sem …» também esteja escolhido.
class FiltrosInversosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private ProjetoTempo $obra;

    private ProjetoTempo $interno;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // semana 14/09–20/09

        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        $hospital = ClienteTempo::create(['nome' => 'Hospital']);
        $this->obra = ProjetoTempo::create(['nome' => 'Obra', 'cliente_id' => $hospital->id]);
        $this->interno = ProjetoTempo::create(['nome' => 'Interno']);

        $c = $this->cliente('Nexus');
        $this->registo($this->ana, $c, '2026-09-14', 3600, ['descricao' => 'R-obra', 'projeto_id' => $this->obra->id, 'etiquetas' => ['remoto']]);
        $this->registo($this->ana, $c, '2026-09-15', 1800, ['descricao' => 'R-interno', 'projeto_id' => $this->interno->id]);
        $this->registo($this->ana, $c, '2026-09-16', 900, ['descricao' => 'R-sem']);                       // sem projeto, sem etiquetas
        $this->registo($this->rui, $c, '2026-09-15', 7200, ['descricao' => 'R-rui', 'projeto_id' => $this->obra->id]);
    }

    private function total(array $filtros): int
    {
        return app(ResumoTempos::class)->totais($this->admin, $filtros, CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-20'))['total'];
    }

    public function test_excluir_projetos_fica_com_o_resto_incluindo_os_sem_projeto(): void
    {
        $this->assertSame(10800, $this->total(['projetos' => [$this->obra->id]]));
        $this->assertSame(2700, $this->total(['projetos' => [$this->obra->id], 'excluir' => ['projetos']]));      // interno + sem projeto
        $this->assertSame(1800, $this->total(['projetos' => [$this->obra->id, 0], 'excluir' => ['projetos']]));   // só o interno
        $this->assertSame(12600, $this->total(['projetos' => [0], 'excluir' => ['projetos']]));                    // tudo com projeto
    }

    public function test_excluir_clientes_etiquetas_e_membros(): void
    {
        $hospital = $this->obra->cliente_id;

        // Cliente do projeto: o interno e o sem projeto não têm cliente e ficam.
        $this->assertSame(2700, $this->total(['clientes' => [$hospital], 'excluir' => ['clientes']]));
        // Etiqueta: os registos sem etiquetas ficam.
        $this->assertSame(9900, $this->total(['etiquetas' => ['remoto'], 'excluir' => ['etiquetas']]));
        $this->assertSame(6300, $this->total(['membros' => [$this->rui->id], 'excluir' => ['membros']]));
        // Combinado: tudo menos a obra, só da Ana.
        $this->assertSame(2700, $this->total(['membros' => [$this->ana->id], 'projetos' => [$this->obra->id], 'excluir' => ['projetos']]));
    }

    public function test_quem_nao_ve_a_equipa_nao_exclui_membros(): void
    {
        $this->assertSame(['membros', 'projetos'], ResumoTempos::exclusoes(['projetos', 'membros', 'xpto', 'projetos'], true));
        $this->assertSame(['projetos'], ResumoTempos::exclusoes(['membros', 'projetos'], false));

        // Um técnico com ?excluir=membros continua a ver só as suas horas.
        Livewire::actingAs($this->rui)->withQueryParams(['membros' => [(string) $this->rui->id], 'excluir' => ['membros']])->test(Detalhado::class)
            ->assertSet('excluir', [])
            ->assertSee('R-rui')
            ->assertDontSee('R-obra');
    }

    public function test_detalhado_alterna_incluir_e_excluir_e_o_botao_diz_exceto(): void
    {
        $pagina = Livewire::actingAs($this->admin)->withQueryParams(['projetos' => [(string) $this->obra->id]])->test(Detalhado::class)
            ->assertSee('R-obra')
            ->assertDontSee('R-interno')
            ->assertSeeHtml('wire:click="alternarExclusao(\'projetos\')"')
            ->call('alternarExclusao', 'projetos')
            ->assertSet('excluir', ['projetos'])
            ->assertSee('Exceto: Obra')
            ->assertSee('R-interno')
            ->assertSee('R-sem')
            ->assertDontSee('R-obra')
            ->assertDontSee('R-rui');

        $pagina->call('alternarExclusao', 'xpto')->assertSet('excluir', ['projetos'])
            ->call('limparFiltros')->assertSet('excluir', [])->assertSee('R-obra');
    }

    public function test_semanal_leva_a_exclusao_para_o_detalhado_menos_no_grupo_da_celula(): void
    {
        $pagina = Livewire::actingAs($this->admin)
            ->withQueryParams(['projetos' => [(string) $this->obra->id], 'excluir' => ['projetos'], 'membros' => [(string) $this->rui->id]])
            ->test(Semanal::class)
            ->call('alternarExclusao', 'membros');

        $this->assertSame(['membros', 'projetos'], $pagina->get('excluir'));
        $semGrupo = urldecode($pagina->instance()->ligacao('2026-09-14', '2026-09-20'));
        $this->assertStringContainsString('excluir[0]=membros', $semGrupo);
        $this->assertStringContainsString('excluir[1]=projetos', $semGrupo);

        // Célula do projeto Interno (agrupado por projeto): esse filtro passa a incluir só o Interno.
        $celula = urldecode($pagina->instance()->ligacao('2026-09-14', '2026-09-20', (string) $this->interno->id));
        $this->assertStringContainsString('projetos[0]='.$this->interno->id, $celula);
        $this->assertStringNotContainsString('=projetos', $celula);
        $this->assertStringContainsString('=membros', $celula);
    }

    public function test_partilhado_e_email_respeitam_a_exclusao_guardada(): void
    {
        $resumo = Livewire::actingAs($this->admin)->withQueryParams(['projetos' => [(string) $this->obra->id]])->test(Resumo::class)
            ->call('alternarExclusao', 'projetos');
        $parametros = $resumo->instance()->parametros();
        $this->assertSame(['projetos'], $parametros['excluir']);

        $link = app(GestorPartilhados::class)->criar($this->admin, 'resumo', ['nome' => 'Sem a obra', 'publico' => true], $parametros);

        Livewire::test(Partilhado::class, ['token' => $link->token])
            ->assertSet('excluir', ['projetos'])
            ->assertSee('0:45:00')       // interno (0:30) + sem projeto (0:15)
            ->assertDontSee('3:00:00');  // o total com a obra
    }

    public function test_calendario_filtra_projetos_com_incluir_e_excluir(): void
    {
        Livewire::actingAs($this->ana)->test(Calendario::class)
            ->assertSee('R-obra')->assertSee('R-interno')->assertSee('R-sem')
            ->set('projetos', [(string) $this->obra->id])
            ->assertSee('R-obra')->assertDontSee('R-interno')->assertDontSee('R-sem')
            ->call('alternarExclusao', 'projetos')
            ->assertSet('excluir', ['projetos'])
            ->assertSee('Exceto projeto')
            ->assertDontSee('R-obra')->assertSee('R-interno')->assertSee('R-sem')
            ->set('projetos', [(string) $this->obra->id, '0'])
            ->assertDontSee('R-sem')->assertSee('R-interno')
            ->call('alternarExclusao', 'membros')
            ->assertSet('excluir', ['projetos']);
    }
}

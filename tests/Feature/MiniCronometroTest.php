<?php

namespace Tests\Feature;

use App\Livewire\Tempos\Cronometro as PaginaCronometro;
use App\Livewire\Tempos\MiniCronometro;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\Cronometro as ServicoCronometro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Cronómetro pequeno (notas §69): no canto de todas as páginas e numa janela por cima de tudo, para
// começar e parar sem ir à página do Cronómetro.
class MiniCronometroTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private ProjetoTempo $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');

        $this->ana = $this->tecnico();
        $this->obra = ProjetoTempo::create(['nome' => 'Obra']);
        $this->juntarAoProjeto($this->obra, $this->ana);
    }

    public function test_comecar_e_parar_no_cartao(): void
    {
        $mini = Livewire::actingAs($this->ana)->test(MiniCronometro::class)
            ->assertSee('Em que está a trabalhar?')
            ->assertSee('Obra')
            ->set('descricao', 'Servidor do cliente')
            ->set('projeto', (string) $this->obra->id)
            ->call('comecar')
            ->assertSet('erro', null)
            ->assertSet('descricao', '')
            ->assertDispatched('cronometro-mudou')
            ->assertSee('Parar')
            ->assertSee('Servidor do cliente');

        $registo = RegistoTempo::sole();
        $this->assertSame([$this->ana->id, $this->obra->id, 'Servidor do cliente', null], [$registo->tecnico_id, $registo->projeto_id, $registo->descricao, $registo->fim]);

        Carbon::setTestNow('2026-10-01 10:30:00');
        $mini->call('parar')
            ->assertSet('aviso', 'Gravado: 1:30:00.')
            ->assertDispatched('cronometro-mudou')
            ->assertSee('Começar');
        $this->assertSame(5400, $registo->fresh()->duracao_seg);
    }

    public function test_menos_de_um_minuto_e_descartado(): void
    {
        $mini = Livewire::actingAs($this->ana)->test(MiniCronometro::class)->call('comecar');
        Carbon::setTestNow('2026-10-01 09:00:30');

        $mini->call('parar')->assertSet('aviso', 'Descartado: durou menos de um minuto.');
        $this->assertSame(0, RegistoTempo::count());
    }

    public function test_so_os_projetos_que_a_pessoa_ve(): void
    {
        $alheio = ProjetoTempo::create(['nome' => 'Projeto do Rui']);
        $this->juntarAoProjeto($alheio, $this->tecnico());

        Livewire::actingAs($this->ana)->test(MiniCronometro::class)
            ->assertDontSee('Projeto do Rui')
            ->set('projeto', (string) $alheio->id)
            ->call('comecar')
            ->assertSet('erro', fn ($erro) => $erro !== null);

        $this->assertSame(0, RegistoTempo::count(), 'não começou num projeto que não vê');
    }

    public function test_mostra_so_o_cronometro_de_quem_esta_a_ver(): void
    {
        app(ServicoCronometro::class)->iniciar($rui = $this->tecnico(), ['descricao' => 'Tarefa do Rui']);

        Livewire::actingAs($this->ana)->test(MiniCronometro::class)
            ->assertDontSee('Tarefa do Rui')
            ->assertSee('Começar')
            ->call('parar')
            ->assertSet('erro', 'Não há nenhum cronómetro a correr.');

        $this->assertNull(RegistoTempo::sole()->fim, 'o do Rui continua a correr');
    }

    public function test_o_cartao_esta_nas_paginas_menos_na_do_cronometro(): void
    {
        $this->actingAs($this->ana);

        $this->get(route('painel'))->assertOk()->assertSee('Abrir o cronómetro');
        $this->get(route('projetos'))->assertOk()->assertSee('Abrir o cronómetro');
        $this->get(route('cronometro'))->assertOk()->assertDontSee('Abrir o cronómetro')->assertSee('Janela por cima');
    }

    public function test_a_pagina_da_janela_tem_so_o_cronometro(): void
    {
        $this->actingAs($this->ana)->get(route('cronometro.janela'))->assertOk()
            ->assertSee('Em que está a trabalhar?')
            ->assertDontSee('Minimizar')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        // Sem acesso ao Suporte no portal, nada.
        $this->actingAs($this->utilizador(null))->get(route('cronometro.janela'))->assertRedirect(config('app.portal_url'));
    }

    public function test_a_pagina_do_cronometro_acompanha_o_que_se_faz_fora(): void
    {
        $pagina = Livewire::actingAs($this->ana)->test(PaginaCronometro::class)->assertSee('Começar');

        // Começa na janela por cima de tudo…
        app(ServicoCronometro::class)->iniciar($this->ana, ['projeto_id' => $this->obra->id, 'descricao' => 'Na janela']);

        $pagina->call('recarregarCronometro')
            ->assertSet('descricao', 'Na janela')
            ->assertSet('barraProjeto', (string) $this->obra->id)
            ->assertSee('Parar');

        // …e o que se faz na página também avisa a janela.
        Carbon::setTestNow('2026-10-01 09:10:00');
        $pagina->call('parar')->assertDispatched('cronometro-mudou');
    }
}

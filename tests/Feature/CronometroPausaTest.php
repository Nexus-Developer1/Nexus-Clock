<?php

namespace Tests\Feature;

use App\Livewire\Tempos\Cronometro as PaginaCronometro;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\Cronometro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Pausar o cronómetro (notas §65): o tempo até à pausa fica gravado; «Retomar» começa outro registo com
// a mesma descrição, projeto, etiquetas e faturável. No dia ficam dois registos, com a pausa entre eles.
class CronometroPausaTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private ProjetoTempo $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 08:00:00'); // 09:00 em Lisboa
        $this->ana = $this->tecnico();
        $this->obra = ProjetoTempo::create(['nome' => 'Obra']);
        $this->juntarAoProjeto($this->obra, $this->ana);
    }

    public function test_pausar_grava_o_tempo_e_retomar_continua_a_mesma_tarefa(): void
    {
        $pagina = Livewire::actingAs($this->ana)->test(PaginaCronometro::class)
            ->set('descricao', 'Manutenção UPS')
            ->set('barraProjeto', (string) $this->obra->id)
            ->set('barraEtiquetas', 'remoto')
            ->set('barraFaturavel', false)
            ->call('comecar')
            ->assertSee('Pausar');

        Carbon::setTestNow('2026-10-01 11:30:00'); // 12:30
        $pagina->call('pausar')->assertSet('erro', null)
            ->assertSee('Em pausa desde 12:30')->assertSee('Retomar')->assertDontSee('Pausar');
        $primeiro = RegistoTempo::sole();
        $this->assertSame([3 * 3600 + 1800, 'Manutenção UPS'], [$primeiro->duracao_seg, $primeiro->descricao]);
        $this->assertNull(app(Cronometro::class)->aCorrer($this->ana));

        Carbon::setTestNow('2026-10-01 12:30:00'); // 13:30
        $pagina->call('retomar')->assertSet('erro', null)->assertDontSee('Em pausa')->assertSee('Pausar');
        $segundo = app(Cronometro::class)->aCorrer($this->ana);
        $this->assertSame(['Manutenção UPS', $this->obra->id, ['remoto'], false], [$segundo->descricao, $segundo->projeto_id, $segundo->etiquetas, $segundo->faturavel]);
        $this->assertNull(app(Cronometro::class)->emPausa($this->ana));

        Carbon::setTestNow('2026-10-01 16:00:00');
        $pagina->call('parar');
        $this->assertSame(2, RegistoTempo::whereNotNull('fim')->count(), 'dois registos, com a pausa entre eles');
    }

    public function test_terminar_ou_comecar_outra_coisa_acaba_a_pausa(): void
    {
        $servico = app(Cronometro::class);
        $servico->iniciar($this->ana, ['descricao' => 'Antes do almoço']);
        Carbon::setTestNow('2026-10-01 11:00:00');
        $servico->pausar($this->ana);
        $this->assertSame('Antes do almoço', $servico->emPausa($this->ana)['dados']['descricao']);

        // Terminar: fica só o que já estava gravado.
        Livewire::actingAs($this->ana)->test(PaginaCronometro::class)->call('terminarPausa')->assertDontSee('Em pausa');
        $this->assertNull($servico->emPausa($this->ana));
        $this->assertSame(1, RegistoTempo::count());

        // Começar outra coisa durante a pausa também a acaba.
        $servico->iniciar($this->ana, ['descricao' => 'Depois']);
        $servico->pausar($this->ana);
        $servico->iniciar($this->ana, ['descricao' => 'Outra tarefa']);
        $this->assertNull($servico->emPausa($this->ana));
    }

    public function test_cada_um_so_ve_a_sua_pausa_e_sem_cronometro_nao_ha_pausa(): void
    {
        $rui = $this->tecnico();
        $servico = app(Cronometro::class);
        $servico->iniciar($this->ana, ['descricao' => 'Da Ana']);
        Carbon::setTestNow('2026-10-01 09:00:00');
        $servico->pausar($this->ana);

        $this->assertNull($servico->emPausa($rui));
        Livewire::actingAs($rui)->test(PaginaCronometro::class)
            ->assertDontSee('Em pausa')
            ->call('pausar')->assertSet('erro', 'Não há nenhum cronómetro a correr.')
            ->call('retomar')->assertSet('erro', 'Não há nada em pausa.');
    }

    public function test_pausa_logo_a_seguir_a_comecar_nao_grava_mas_deixa_retomar(): void
    {
        $servico = app(Cronometro::class);
        $servico->iniciar($this->ana, ['descricao' => 'Engano']);
        Carbon::setTestNow('2026-10-01 08:00:30'); // 30 s: menos de um minuto, não se grava
        $servico->pausar($this->ana);

        $this->assertSame(0, RegistoTempo::count());
        $this->assertSame('Engano', $servico->emPausa($this->ana)['dados']['descricao']);
        $this->assertSame('Engano', $servico->retomar($this->ana)->descricao);
    }
}

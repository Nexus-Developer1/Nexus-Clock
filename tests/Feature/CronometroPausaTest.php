<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Detalhado;
use App\Livewire\Tempos\Cronometro as PaginaCronometro;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\Cronometro;
use App\Services\Tempos\Presencas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Pausar o cronómetro (notas §67): o relógio para; «Retomar» continua de onde estava, no MESMO registo;
// ao parar fica um só registo, com a pausa descontada da duração.
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

    public function test_pausar_para_o_relogio_e_retomar_continua_de_onde_estava(): void
    {
        $pagina = Livewire::actingAs($this->ana)->test(PaginaCronometro::class)
            ->set('descricao', 'Manutenção UPS')
            ->set('barraProjeto', (string) $this->obra->id)
            ->call('comecar')
            ->assertSee('Pausar');
        $registo = RegistoTempo::sole();

        // 12:30: pausa — o relógio fica parado em 3:30:00.
        Carbon::setTestNow('2026-10-01 11:30:00');
        $pagina->call('pausar')->assertSet('erro', null)
            ->assertSee('Em pausa desde 12:30')->assertSee('3:30:00')->assertSee('Retomar')->assertDontSee('Pausar');
        $this->assertNull($registo->fresh()->fim, 'continua a ser o mesmo registo, por acabar');

        // 13:30: retoma — o relógio continua em 3:30:00 (conta a partir do início + 1 h de pausa).
        Carbon::setTestNow('2026-10-01 12:30:00');
        $pagina->call('retomar')->assertSet('erro', null)->assertDontSee('Em pausa')->assertSee('Pausar')
            ->assertSeeHtml('inicio: '.(CarbonImmutable::parse('2026-10-01 08:00:00')->getTimestamp() + 3600));
        $this->assertSame(3600, $registo->fresh()->pausa_seg);

        // 17:00: para — um só registo, das 09:00 às 17:00, com 7 h (8 h menos 1 h de pausa).
        Carbon::setTestNow('2026-10-01 16:00:00');
        $pagina->call('parar')->assertSet('erro', null);
        $final = RegistoTempo::sole();
        $this->assertSame(['09:00', '17:00', 7 * 3600], [
            $final->inicio->setTimezone('Europe/Lisbon')->format('H:i'), $final->fim->setTimezone('Europe/Lisbon')->format('H:i'), $final->duracao_seg,
        ]);

        // As Presenças mostram a hora de pausa.
        $dia = app(Presencas::class)->linhas([$this->ana->id], CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-01'))[0];
        $this->assertSame([7 * 3600, 3600], [$dia['trabalho'], $dia['pausa']]);
    }

    public function test_parar_em_pausa_acaba_na_hora_da_pausa(): void
    {
        $servico = app(Cronometro::class);
        $servico->iniciar($this->ana, ['descricao' => 'Antes do almoço']);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $servico->pausar($this->ana);
        Carbon::setTestNow('2026-10-01 14:00:00'); // esqueceu-se e parou mais tarde

        $registo = $servico->parar($this->ana);
        $this->assertSame([2 * 3600, '2026-10-01 10:00:00'], [$registo->duracao_seg, $registo->fim->format('Y-m-d H:i:s')]);
        $this->assertNull($registo->pausado_em);
    }

    public function test_comecar_outra_tarefa_em_pausa_para_a_anterior(): void
    {
        $servico = app(Cronometro::class);
        $servico->iniciar($this->ana, ['descricao' => 'Primeira']);
        Carbon::setTestNow('2026-10-01 09:00:00');
        $servico->pausar($this->ana);
        Carbon::setTestNow('2026-10-01 09:30:00');
        $servico->iniciar($this->ana, ['descricao' => 'Segunda']);

        $this->assertSame(3600, RegistoTempo::where('descricao', 'Primeira')->sole()->duracao_seg);
        $this->assertSame('Segunda', $servico->aCorrer($this->ana)->descricao);
    }

    public function test_erros_e_cada_um_com_o_seu(): void
    {
        $rui = $this->tecnico();
        $servico = app(Cronometro::class);
        $servico->iniciar($this->ana, ['descricao' => 'Da Ana']);
        Carbon::setTestNow('2026-10-01 09:00:00');
        $servico->pausar($this->ana);

        Livewire::actingAs($this->ana)->test(PaginaCronometro::class)
            ->call('pausar')->assertSet('erro', 'O cronómetro já está em pausa.');
        Livewire::actingAs($rui)->test(PaginaCronometro::class)
            ->assertDontSee('Em pausa')
            ->call('pausar')->assertSet('erro', 'Não há nenhum cronómetro a correr.')
            ->call('retomar')->assertSet('erro', 'Não há nada em pausa.');
    }

    public function test_alterar_as_horas_a_mao_tira_a_pausa(): void
    {
        $servico = app(Cronometro::class);
        $servico->iniciar($this->ana, ['descricao' => 'Com pausa']);
        Carbon::setTestNow('2026-10-01 09:00:00');
        $servico->pausar($this->ana);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $servico->retomar($this->ana);
        Carbon::setTestNow('2026-10-01 12:00:00');
        $registo = $servico->parar($this->ana);
        $this->assertSame(3 * 3600, $registo->duracao_seg);

        // No formulário, «Início 09:00, Fim 13:00» são 4 h: as horas escritas mandam.
        Livewire::actingAs($this->ana)->test(Detalhado::class)
            ->call('editar', $registo->id)
            ->set('formulario.hora_inicio', '09:00')->set('formulario.hora_fim', '13:00')
            ->call('guardar')->assertHasNoErrors();
        $this->assertSame([4 * 3600, 0], [$registo->fresh()->duracao_seg, $registo->fresh()->pausa_seg]);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Tempos\Calendario;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Calendário: os registos da semana desenhados nas horas de cada dia (sobreposições lado a lado),
// os que só têm duração numa faixa à parte, e arrastar numa coluna para acrescentar tempo.
class CalendarioTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $rui;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14/09–20/09

        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
    }

    /** Registo com horas reais (início e fim), como os do cronómetro. */
    private function comHoras(User $tecnico, string $dia, string $de, string $ate, string $descricao, array $extra = []): RegistoTempo
    {
        $inicio = CarbonImmutable::parse($dia.' '.$de, config('tempos.fuso'))->utc();
        $fim = CarbonImmutable::parse($dia.' '.$ate, config('tempos.fuso'))->utc();

        return RegistoTempo::create(array_merge([
            'tecnico_id' => $tecnico->id,
            'cliente_id' => $this->cliente('Hospital')->id,
            'descricao' => $descricao,
            'inicio' => $inicio,
            'fim' => $fim,
            'duracao_seg' => (int) $inicio->diffInSeconds($fim),
        ], $extra));
    }

    public function test_desenha_os_registos_da_semana_e_separa_os_que_nao_tem_horas(): void
    {
        $projeto = ProjetoTempo::create(['nome' => 'Obra', 'cor' => '#2a78d6']);
        $this->comHoras($this->ana, '2026-09-15', '09:00', '10:30', 'Reunião', ['projeto_id' => $projeto->id]);
        $this->comHoras($this->rui, '2026-09-15', '09:00', '10:00', 'Do Rui');
        $this->registo($this->ana, $this->cliente('Hospital'), '2026-09-16', 1800, ['descricao' => 'Folha de horas']);

        $pagina = Livewire::actingAs($this->ana)->test(Calendario::class)
            ->assertSee('Esta semana')
            ->assertSee('Reunião')
            ->assertSee('09:00 – 10:30')
            ->assertSee('Folha de horas')
            ->assertSee('Sem horas')
            ->assertDontSee('Do Rui');

        $dias = $pagina->viewData('dias');
        $terca = $dias[1];  // 15/09
        $quarta = $dias[2]; // 16/09

        $this->assertSame([540, 90, 1, 0], [
            $terca['blocos'][0]['minuto'], $terca['blocos'][0]['minutos'],
            $terca['blocos'][0]['colunas'], $terca['blocos'][0]['coluna'],
        ]);
        $this->assertSame([5400, 1800], [$terca['total'], $quarta['total']]);
        $this->assertCount(0, $quarta['blocos']);
        $this->assertCount(1, $quarta['semHoras']);
    }

    public function test_registos_sobrepostos_ficam_lado_a_lado(): void
    {
        $this->comHoras($this->ana, '2026-09-14', '09:00', '11:00', 'Um');
        $this->comHoras($this->ana, '2026-09-14', '10:00', '12:00', 'Dois');
        $this->comHoras($this->ana, '2026-09-14', '15:00', '16:00', 'Sozinho');

        $dias = Livewire::actingAs($this->ana)->test(Calendario::class)->viewData('dias');
        $blocos = $dias[0]['blocos'];

        $this->assertSame([2, 2, 1], array_column($blocos, 'colunas'));
        $this->assertSame([0, 1, 0], array_column($blocos, 'coluna'));
    }

    public function test_arrastar_abre_o_formulario_com_o_dia_e_as_horas(): void
    {
        Livewire::actingAs($this->ana)->test(Calendario::class)
            ->call('novo', ['dia' => '2026-09-16', 'hora_inicio' => '14:00', 'hora_fim' => '15:30'])
            ->assertSet('editarId', 0)
            ->assertSet('formulario.dia', '2026-09-16')
            ->assertSet('formulario.hora_inicio', '14:00')
            ->assertSet('formulario.hora_fim', '15:30')
            ->set('formulario.descricao', 'Visita à obra')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSee('Registo acrescentado.')
            ->assertSee('Visita à obra');

        $registo = RegistoTempo::sole();
        $this->assertSame([5400, '14:00'], [$registo->duracao_seg, $registo->inicio->setTimezone(config('tempos.fuso'))->format('H:i')]);
    }

    public function test_navega_entre_semanas(): void
    {
        $this->comHoras($this->ana, '2026-09-08', '09:00', '10:00', 'Semana passada');

        Livewire::actingAs($this->ana)->test(Calendario::class)
            ->assertDontSee('Semana passada')
            ->call('semanaAnterior')
            ->assertSee('Semana passada')
            ->assertSee('1:00:00')
            ->call('estaSemana')
            ->assertSee('Esta semana')
            ->assertDontSee('Semana passada');
    }
}

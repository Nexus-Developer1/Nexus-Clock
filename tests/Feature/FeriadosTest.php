<?php

namespace Tests\Feature;

use App\Enums\OrigemRegistoTempo;
use App\Livewire\Tempos\Calendario;
use App\Livewire\Tempos\MiniCronometro;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\Cronometro as ServicoCronometro;
use App\Services\Tempos\Feriados;
use App\Services\Tempos\GravadorRegistos;
use App\Services\Tempos\Presencas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Feriados (notas §72), como na agenda da Nexus Infra: aparecem no Calendário, não se registam horas
// neles e não contam como horas em falta. O Carnaval é tolerância: vê-se, mas não bloqueia.
class FeriadosTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00'); // terça; 05/10 foi a Implantação da República
        $this->ana = $this->tecnico();
    }

    public function test_calcula_os_feriados_do_ano_incluindo_os_da_pascoa(): void
    {
        $feriados = app(Feriados::class);
        $ano = $feriados->doAno(2026);

        // Páscoa de 2026: 5 de abril.
        $this->assertSame('2026-04-05', Feriados::pascoa(2026)->toDateString());
        $this->assertSame('Sexta-feira Santa', $feriados->nome('2026-04-03'));
        $this->assertSame('Corpo de Deus', $feriados->nome('2026-06-04'));
        $this->assertSame('Implantação da República', $feriados->nome('2026-10-05'));
        $this->assertSame('São João', $feriados->nome('2026-06-24'));
        $this->assertNull($feriados->nome('2026-10-06'));

        // Carnaval: só como tolerância.
        $this->assertNull($feriados->nome('2026-02-17'));
        $this->assertSame('Carnaval (tolerância)', $feriados->nome('2026-02-17', true));

        $this->assertCount(14, array_filter($ano, fn ($f) => ! $f['tolerancia']));
        $this->assertSame('2027-03-28', Feriados::pascoa(2027)->toDateString());
    }

    public function test_nao_se_registam_horas_num_feriado(): void
    {
        $inicio = CarbonImmutable::parse('2026-10-05 09:00', config('tempos.fuso'));

        try {
            app(GravadorRegistos::class)->criar($this->ana, [
                'tecnico_id' => $this->ana->id, 'inicio' => $inicio, 'fim' => $inicio->addHour(),
                'origem' => OrigemRegistoTempo::Timesheet,
            ]);
            $this->fail('devia recusar');
        } catch (ValidationException $e) {
            $this->assertSame('05/10/2026 é feriado (Implantação da República) — não é possível registar horas neste dia.', $e->errors()['dia'][0]);
        }
        $this->assertSame(0, RegistoTempo::count());

        // Nem mudar um registo para o feriado.
        $registo = app(GravadorRegistos::class)->criar($this->ana, [
            'tecnico_id' => $this->ana->id, 'inicio' => $inicio->addDay(), 'fim' => $inicio->addDay()->addHour(),
            'origem' => OrigemRegistoTempo::Timesheet,
        ]);
        $this->expectException(ValidationException::class);
        app(GravadorRegistos::class)->atualizar($this->ana, $registo, ['inicio' => $inicio, 'fim' => $inicio->addHour()]);
    }

    public function test_o_cronometro_nao_comeca_num_feriado_mas_o_carnaval_deixa(): void
    {
        Carbon::setTestNow('2026-12-25 10:00:00');
        Livewire::actingAs($this->ana)->test(MiniCronometro::class)
            ->call('comecar')
            ->assertSet('erro', '25/12/2026 é feriado (Natal) — não é possível registar horas neste dia.');
        $this->assertSame(0, RegistoTempo::count());

        // Um cronómetro que vem da véspera passa a meia-noite e para no feriado: grava (conta o início).
        Carbon::setTestNow('2026-12-24 22:00:00');
        app(ServicoCronometro::class)->iniciar($this->ana, []);
        Carbon::setTestNow('2026-12-25 00:30:00');
        $this->assertNotNull(app(ServicoCronometro::class)->parar($this->ana));

        Carbon::setTestNow('2026-02-17 10:00:00'); // Carnaval
        app(ServicoCronometro::class)->iniciar($this->ana, []);
        $this->assertNotNull(app(ServicoCronometro::class)->aCorrer($this->ana));
    }

    public function test_o_calendario_mostra_o_feriado_e_nao_abre_o_formulario(): void
    {
        Livewire::actingAs($this->ana)->test(Calendario::class)
            ->assertSee('Implantação da República')
            ->call('novo', ['dia' => '2026-10-05', 'hora_inicio' => '09:00', 'hora_fim' => '10:00'])
            ->assertSet('editarId', null)
            ->assertSet('erro', '05/10/2026 é feriado (Implantação da República) — não é possível registar horas neste dia.')
            ->call('novo', ['dia' => '2026-10-06', 'hora_inicio' => '09:00', 'hora_fim' => '10:00'])
            ->assertSet('editarId', 0);

        // Semana do Carnaval: aparece, mas deixa registar.
        Livewire::actingAs($this->ana)->withQueryParams(['de' => '2026-02-16'])->test(Calendario::class)
            ->assertSee('Carnaval (tolerância)')
            ->call('novo', ['dia' => '2026-02-17'])
            ->assertSet('editarId', 0);
    }

    public function test_desligado_volta_a_deixar_registar(): void
    {
        config(['tempos.bloquear_feriados' => false]);
        $inicio = CarbonImmutable::parse('2026-10-05 09:00', config('tempos.fuso'));

        app(GravadorRegistos::class)->criar($this->ana, [
            'tecnico_id' => $this->ana->id, 'inicio' => $inicio, 'fim' => $inicio->addHour(),
            'origem' => OrigemRegistoTempo::Timesheet,
        ]);
        $this->assertSame(1, RegistoTempo::count());
    }

    public function test_o_feriado_nao_conta_como_horas_em_falta_nas_presencas(): void
    {
        $linhas = collect(app(Presencas::class)->linhas([$this->ana->id],
            CarbonImmutable::parse('2026-10-05', config('tempos.fuso')),
            CarbonImmutable::parse('2026-10-06', config('tempos.fuso'))
        ))->keyBy(fn ($l) => $l['dia']->toDateString());

        $this->assertSame(0, $linhas['2026-10-05']['capacidade']);
        $this->assertSame(0, $linhas['2026-10-05']['em_falta']);
        $this->assertSame(8 * 3600, $linhas['2026-10-06']['capacidade']);
    }

    public function test_feriados_em_dias_uteis(): void
    {
        $f = app(Feriados::class);
        // Semana de 30/11 a 06/12/2026: 1/12 (terça) é feriado; 08/12 já é a seguinte.
        $this->assertSame(1, $f->emDiasUteis(CarbonImmutable::parse('2026-11-30'), CarbonImmutable::parse('2026-12-06')));
        // 25/04/2026 é sábado: não conta.
        $this->assertSame(0, $f->emDiasUteis(CarbonImmutable::parse('2026-04-20'), CarbonImmutable::parse('2026-04-26')));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\EstadoSemanaTempo;
use App\Livewire\Timesheet\Semanas;
use App\Models\Cliente;
use App\Models\SemanaTempo;
use App\Models\User;
use App\Services\Tempos\AlertasHoras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Alertas de horas (ideia tirada do Clockify): horas a menos na semana, dias acima de 10 h, semanas
// por entregar e por aprovar — no resumo de segunda-feira a quem gere e na vista Semanas. Só avisam.
class AlertasHorasTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUNDA = '2026-09-07';

    private User $admin;

    private User $ana;

    private User $bruno;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 09:00:00');
        config(['tempos.horas_semana_minimas' => 35]);

        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->bruno = $this->tecnico();
        $this->bruno->update(['nome' => 'Bruno Costa']);
        $this->cliente = $this->cliente('Hospital Exemplo');
    }

    // Ana: 40 h em quatro dias, um deles com 11 h. Bruno: 20 h.
    private function semanaTipica(): void
    {
        foreach (['2026-09-07' => 11, '2026-09-08' => 9, '2026-09-09' => 10, '2026-09-10' => 10] as $dia => $h) {
            $this->registo($this->ana, $this->cliente, $dia, $h * 3600);
        }
        foreach (['2026-09-07', '2026-09-08'] as $dia) {
            $this->registo($this->bruno, $this->cliente, $dia, 10 * 3600);
        }
        SemanaTempo::create(['tecnico_id' => $this->ana->id, 'semana_inicio' => self::SEGUNDA, 'estado' => EstadoSemanaTempo::Submetida, 'submetida_em' => now()]);
    }

    public function test_calcula_horas_a_menos_dias_longos_por_entregar_e_por_aprovar(): void
    {
        $this->semanaTipica();

        $r = app(AlertasHoras::class)->semana(Carbon::parse(self::SEGUNDA));
        $porNome = collect($r['tecnicos'])->keyBy(fn ($t) => $t['tecnico']->nome);

        $this->assertSame(['Ana Martins', 'Bruno Costa'], $porNome->keys()->all()); // admin sem horas fica de fora
        $this->assertFalse($porNome['Ana Martins']['abaixoDoMinimo']);
        $this->assertSame([['2026-09-07', 11 * 3600]], collect($porNome['Ana Martins']['diasLongos'])->map(fn ($d) => [$d['dia']->toDateString(), $d['segundos']])->all());
        $this->assertTrue($porNome['Bruno Costa']['abaixoDoMinimo']);
        $this->assertSame([1, 1, 1, 1, true], [$r['abaixoDoMinimo'], $r['diasLongos'], $r['emFalta'], $r['porAprovar'], $r['temAlertas']]);
    }

    public function test_minimo_a_zero_nao_avisa_horas_a_menos(): void
    {
        config(['tempos.horas_semana_minimas' => 0]);
        $this->semanaTipica();

        $this->assertSame(0, app(AlertasHoras::class)->semana(Carbon::parse(self::SEGUNDA))['abaixoDoMinimo']);
    }
}

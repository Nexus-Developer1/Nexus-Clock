<?php

namespace Tests\Feature;

use App\Enums\OrigemRegistoTempo;
use App\Jobs\PararCronometrosEsquecidos;
use App\Models\Auditoria;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\Cronometro;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// Cronómetro esquecido para sozinho (notas §78): às 19:00 os começados antes das 19h, às 23:59 os
// começados depois; o fim gravado é o do limite, não a hora a que o trabalho corre. Sem email.
class CronometroParaSozinhoTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->tecnico();
    }

    /** Cronómetro a correr desde $quando (hora de Lisboa). */
    private function aCorrer(User $quem, string $quando): RegistoTempo
    {
        return RegistoTempo::create([
            'tecnico_id' => $quem->id, 'cliente_id' => $this->cliente('Hospital')->id, 'descricao' => 'Esquecido',
            'inicio' => CarbonImmutable::parse($quando, config('tempos.fuso'))->utc(), 'fim' => null, 'duracao_seg' => 0,
            'origem' => OrigemRegistoTempo::Cronometro,
        ]);
    }

    private function lisboa(?CarbonImmutable $d): ?string
    {
        return $d?->setTimezone(config('tempos.fuso'))->format('Y-m-d H:i:s');
    }

    /** Corre a paragem como se fossem $quando (hora de Lisboa). */
    private function correrAs(string $quando): int
    {
        Carbon::setTestNow(CarbonImmutable::parse($quando, config('tempos.fuso'))->utc());

        return app(Cronometro::class)->pararEsquecidos();
    }

    public function test_limite_e_as_19_ou_as_23_59_do_dia_em_que_comecou(): void
    {
        $this->assertSame('2026-09-17 19:00:00', $this->lisboa(Cronometro::limite($this->aCorrer($this->ana, '2026-09-17 08:30'))));
        $this->assertSame('2026-09-17 23:59:59', $this->lisboa(Cronometro::limite($this->aCorrer($this->tecnico(), '2026-09-17 19:00'))));
        $this->assertSame('2026-09-17 23:59:59', $this->lisboa(Cronometro::limite($this->aCorrer($this->tecnico(), '2026-09-17 21:15'))));

        config(['tempos.parar_cronometro_as' => '']);
        $this->assertNull(Cronometro::limite(RegistoTempo::first()));
    }

    public function test_as_19_para_os_de_antes_e_grava_o_fim_as_19(): void
    {
        Notification::fake();
        $manha = $this->aCorrer($this->ana, '2026-09-17 09:00');
        $noite = $this->aCorrer($this->tecnico(), '2026-09-17 19:20');

        $this->assertSame(0, $this->correrAs('2026-09-17 18:58'));
        $this->assertNull($manha->fresh()->fim);

        // O trabalho corre uns minutos depois das 19h: o fim fica às 19:00.
        $this->assertSame(1, $this->correrAs('2026-09-17 19:03'));
        $this->assertSame('2026-09-17 19:00:00', $this->lisboa($manha->fresh()->fim));
        $this->assertSame(10 * 3600, $manha->fresh()->duracao_seg);
        $this->assertNull($noite->fresh()->fim);

        // O de depois das 19h para às 23:59:59.
        $this->assertSame(1, $this->correrAs('2026-09-18 00:04'));
        $this->assertSame('2026-09-17 23:59:59', $this->lisboa($noite->fresh()->fim));

        $this->assertSame(2, Auditoria::where('acao', 'tempo_cronometro_parado_sozinho')->count());
        Notification::assertNothingSent();
    }

    public function test_esquecido_desde_ontem_para_no_limite_de_ontem_e_com_menos_de_um_minuto_descarta(): void
    {
        // O servidor esteve parado: o de ontem para às 19:00 de ontem, não agora.
        $ontem = $this->aCorrer($this->ana, '2026-09-16 10:00');
        // Começado aos 18:59:30: até às 19:00 são 30 segundos — descarta-se, como ao parar à mão.
        $relampago = $this->aCorrer($this->tecnico(), '2026-09-17 18:59:30');

        $this->assertSame(2, $this->correrAs('2026-09-17 19:01'));

        $this->assertSame('2026-09-16 19:00:00', $this->lisboa($ontem->fresh()->fim));
        $this->assertSame(9 * 3600, $ontem->fresh()->duracao_seg);
        $this->assertSoftDeleted($relampago);
    }

    public function test_o_que_o_gravador_recusa_fica_a_correr_e_nao_rebenta(): void
    {
        $fechado = $this->aCorrer($this->ana, '2026-09-17 09:00');
        $fechado->forceFill(['fechado_em' => now()])->save();   // mês fechado: o dono já não lhe mexe
        $bom = $this->aCorrer($this->tecnico(), '2026-09-17 09:00');

        $this->assertSame(1, $this->correrAs('2026-09-17 19:05'));
        $this->assertNull($fechado->fresh()->fim);
        $this->assertNotNull($bom->fresh()->fim);
    }

    public function test_desligado_nao_para_nada_e_o_trabalho_esta_agendado(): void
    {
        config(['tempos.parar_cronometro_as' => '']);
        $registo = $this->aCorrer($this->ana, '2026-09-17 09:00');

        Carbon::setTestNow(CarbonImmutable::parse('2026-09-17 20:00', config('tempos.fuso'))->utc());
        (new PararCronometrosEsquecidos)->handle(app(Cronometro::class));
        $this->assertNull($registo->fresh()->fim);

        $agendado = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'tempos-parar-cronometros');
        $this->assertNotNull($agendado);
        $this->assertSame('*/5 * * * *', $agendado->expression);
    }
}

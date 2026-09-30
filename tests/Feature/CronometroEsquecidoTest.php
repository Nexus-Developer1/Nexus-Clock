<?php

namespace Tests\Feature;

use App\Jobs\AvisarCronometrosEsquecidos;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Notifications\CronometroEsquecido;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// Aviso de cronómetro esquecido (notas §61): um cronómetro a correr há mais de 10 horas manda um email à
// própria pessoa, uma vez por cronómetro. Verifica-se de hora a hora.
class CronometroEsquecidoTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-09-30 20:00:00'); // 21:00 em Lisboa
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
    }

    private function aCorrerDesde(User $quem, string $inicioUtc): RegistoTempo
    {
        return RegistoTempo::create(['tecnico_id' => $quem->id, 'inicio' => $inicioUtc, 'descricao' => 'Manutenção UPS']);
    }

    public function test_avisa_uma_vez_quem_tem_o_cronometro_a_correr_ha_mais_de_10_horas(): void
    {
        $this->aCorrerDesde($this->ana, '2026-09-30 08:12:00'); // 09:12 em Lisboa, há 11 h 48 min
        $rui = $this->tecnico();
        $this->aCorrerDesde($rui, '2026-09-30 11:00:00'); // há 9 h: ainda não

        (new AvisarCronometrosEsquecidos)->handle();
        (new AvisarCronometrosEsquecidos)->handle(); // na hora seguinte, o mesmo cronómetro não volta a avisar

        Notification::assertSentToTimes($this->ana, CronometroEsquecido::class, 1);
        Notification::assertNotSentTo($rui, CronometroEsquecido::class);

        $aviso = null;
        Notification::assertSentTo($this->ana, CronometroEsquecido::class, function (CronometroEsquecido $n) use (&$aviso) {
            $aviso = $n;

            return true;
        });
        $mail = $aviso->toMail($this->ana);
        $html = (string) $mail->render();
        $this->assertSame('O seu cronómetro está a correr há 11 horas', $mail->subject);
        $this->assertStringContainsString('Olá Ana Martins,', $html);
        $this->assertStringContainsString('desde hoje às <strong style="color:#111827;">09:12</strong>', $html);
        $this->assertStringContainsString('Manutenção UPS', $html);
        $this->assertStringContainsString(e(route('cronometro')), $html);
    }

    public function test_de_ontem_diz_ontem_e_um_cronometro_novo_volta_a_avisar(): void
    {
        $antigo = $this->aCorrerDesde($this->ana, '2026-09-29 07:30:00'); // ontem às 08:30
        (new AvisarCronometrosEsquecidos)->handle();
        Notification::assertSentTo($this->ana, CronometroEsquecido::class, fn ($n) => str_contains((string) $n->toMail($this->ana)->render(), 'desde ontem às'));

        // Parou esse e esqueceu-se de outro: é um aviso novo.
        $antigo->forceFill(['fim' => '2026-09-29 17:00:00', 'duracao_seg' => 34200])->save();
        $this->aCorrerDesde($this->ana, '2026-09-30 09:00:00');
        (new AvisarCronometrosEsquecidos)->handle();
        Notification::assertSentToTimes($this->ana, CronometroEsquecido::class, 2);
    }

    public function test_nao_avisa_contas_desativadas_ou_sem_acesso(): void
    {
        $inativo = $this->tecnico();
        $inativo->forceFill(['ativo' => false])->save();
        $semAcesso = $this->tecnico();
        DB::table('acessos')->where('utilizador_id', $semAcesso->id)->delete();
        $this->aCorrerDesde($inativo, '2026-09-30 06:00:00');
        $this->aCorrerDesde($semAcesso, '2026-09-30 06:00:00');

        (new AvisarCronometrosEsquecidos)->handle();
        Notification::assertNothingSent();
    }

    public function test_corre_de_hora_a_hora(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'tempos-cronometros-esquecidos');
        $this->assertNotNull($evento);
        $this->assertSame('0 * * * *', $evento->expression);
        $this->assertGreaterThan(0, (new AvisarCronometrosEsquecidos)->uniqueFor);
    }
}

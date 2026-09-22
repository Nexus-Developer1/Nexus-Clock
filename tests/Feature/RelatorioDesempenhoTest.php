<?php

namespace Tests\Feature;

use App\Jobs\AtualizarConsumoContratos;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Services\Tempos\Relatorios\FiltrosRelatorio;
use App\Services\Tempos\Relatorios\RelatorioContrato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Critério de aceitação da Fase 3: o relatório de um contrato com 12 meses de dados calcula-se em
// menos de 1 segundo (usa a view materializada). Volume: 40 contratos × 12 meses, ~96 mil registos.
class RelatorioDesempenhoTest extends TestCase
{
    use RefreshDatabase;

    public function test_relatorio_de_contrato_com_12_meses_abre_em_menos_de_um_segundo(): void
    {
        Carbon::setTestNow('2026-12-20 10:00:00');
        $cliente = $this->cliente('Cliente grande');
        $tecnicos = collect(range(1, 8))->map(fn () => $this->tecnico()->id)->all();

        $contratos = [];
        for ($i = 1; $i <= 40; $i++) {
            $contrato = $this->contrato($cliente, sprintf('CT-%03d', $i));
            ContratoHorasIncluidas::create(['contrato_id' => $contrato->id, 'periodo' => 'mensal', 'horas_incluidas' => 20, 'transita' => $i % 2 === 0, 'valido_de' => '2026-01-01', 'valido_ate' => '2026-12-31']);
            $contratos[] = $contrato->id;
        }

        // ~10 registos por contrato em cada dia útil de 2026, técnicos à vez. Direto em SQL (rápido).
        DB::statement(<<<'SQL'
            insert into registos_tempo (tecnico_id, cliente_id, contrato_id, inicio, fim, duracao_seg, faturavel, origem, etiquetas, created_at, updated_at)
            select (?::bigint[])[1 + (n % 8)], ?, c.id,
                   (d::date::timestamp at time zone 'Europe/Lisbon'),
                   (d::date::timestamp at time zone 'Europe/Lisbon') + make_interval(secs => 900 + (n % 12) * 900),
                   900 + (n % 12) * 900, (n % 5) <> 0, 'timesheet', '{}', now(), now()
            from unnest(?::bigint[]) as c(id)
            cross join generate_series('2026-01-01'::date, '2026-12-19'::date, interval '1 day') as d
            cross join generate_series(1, 10) as n
            where extract(isodow from d) < 6
            SQL, ['{'.implode(',', $tecnicos).'}', $cliente->id, '{'.implode(',', $contratos).'}']);

        $this->assertGreaterThan(90000, DB::table('registos_tempo')->count());
        DB::statement('analyze registos_tempo');
        (new AtualizarConsumoContratos)->handle();

        $servico = app(RelatorioContrato::class);
        $contrato = Contrato::find($contratos[7]);
        $filtros = FiltrosRelatorio::doQuery(['periodo' => 'ano', 'ref' => '2026']);
        $servico->gerar($contrato, $filtros); // aquece (ligação, caches)

        $inicio = microtime(true);
        $relatorio = $servico->gerar($contrato, $filtros);
        $ms = (microtime(true) - $inicio) * 1000;

        $this->assertCount(12, $relatorio['periodos']);
        $this->assertSame(['2026-01-01', '2026-12-31'], [$relatorio['periodos'][0]['inicio']->toDateString(), $relatorio['periodos'][11]['fim']->toDateString()]);
        fwrite(STDERR, sprintf("\n[desempenho] relatório de contrato, 12 meses, %d registos: %.0f ms\n", DB::table('registos_tempo')->count(), $ms));
        $this->assertLessThan(1000, $ms, "O relatório demorou {$ms} ms.");
    }
}

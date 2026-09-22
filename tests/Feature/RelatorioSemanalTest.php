<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Semanal;
use App\Models\Cliente;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Tempos\ResumoTempos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Relatório Semanal: grelha com uma linha por grupo (e subgrupo) e uma coluna por dia, semana ou mês,
// totais por linha e coluna, tempo e/ou valor; cada célula abre o Detalhado.
class RelatorioSemanalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private Cliente $hospital;

    private ProjetoTempo $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14/09–20/09

        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        $this->hospital = $this->cliente('Hospital');
        $this->obra = ProjetoTempo::create(['nome' => 'Obra', 'taxa_cent' => 6000]);

        $this->registo($this->ana, $this->hospital, '2026-09-14', 3600, ['projeto_id' => $this->obra->id, 'faturavel' => true, 'etiquetas' => ['a', 'b']]);
        $this->registo($this->rui, $this->hospital, '2026-09-14', 1800, ['projeto_id' => $this->obra->id, 'faturavel' => true]);
        $this->registo($this->ana, $this->hospital, '2026-09-16', 7200);
        $this->registo($this->ana, $this->hospital, '2026-08-20', 900); // noutra semana
    }

    private function grelha(string $de, string $ate, string $g1 = 'projeto', ?string $g2 = 'membro', array $filtros = []): array
    {
        return app(ResumoTempos::class)->grelha($this->admin, $filtros, CarbonImmutable::parse($de), CarbonImmutable::parse($ate), $g1, $g2);
    }

    public function test_grelha_por_dia_com_subgrupos_e_totais(): void
    {
        $g = $this->grelha('2026-09-14', '2026-09-20');

        $this->assertSame('dia', $g['escala']);
        $this->assertSame(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'], array_column($g['colunas'], 'rotulo'));
        $this->assertSame([false, false, false, false, false, true, true], array_column($g['colunas'], 'fimDeSemana'));
        $this->assertSame(['Sem projeto', 'Obra'], array_column($g['linhas'], 'nome'));

        $obra = $g['linhas'][1];
        $this->assertSame([5400, 9000], [$obra['segundos'], $obra['valor']]);
        $this->assertSame(['segundos' => 5400, 'valor' => 9000], $obra['celulas']['2026-09-14']);
        $this->assertSame(['segundos' => 0, 'valor' => 0], $obra['celulas']['2026-09-15']);
        $this->assertSame([['Ana Martins', 3600], ['Rui Costa', 1800]], array_map(fn ($f) => [$f['nome'], $f['segundos']], $obra['filhos']));

        $this->assertSame(['segundos' => 12600, 'valor' => 9000], $g['total']);
        $this->assertSame(7200, $g['totais']['2026-09-16']['segundos']);

        // Por etiqueta: cada registo conta em cada etiqueta, os totais não duplicam.
        $e = $this->grelha('2026-09-14', '2026-09-20', 'etiqueta', null);
        $this->assertSame(['Sem etiqueta' => 9000, 'a' => 3600, 'b' => 3600], collect($e['linhas'])->pluck('segundos', 'nome')->all());
        $this->assertSame(5400, $e['totais']['2026-09-14']['segundos']);
        $this->assertSame([], $e['linhas'][0]['filhos']);

        // Filtros.
        $this->assertSame(1800, $this->grelha('2026-09-14', '2026-09-20', filtros: ['membros' => [$this->rui->id]])['total']['segundos']);
    }

    public function test_por_semana_e_por_mes_em_periodos_longos(): void
    {
        $mes = $this->grelha('2026-09-01', '2026-09-30');
        $this->assertSame('semana', $mes['escala']);
        $this->assertSame(['Sem. 36', 'Sem. 37', 'Sem. 38', 'Sem. 39', 'Sem. 40'], array_column($mes['colunas'], 'rotulo'));
        $this->assertSame(['01–06/09', '07–13/09', '14–20/09', '21–27/09', '28–30/09'], array_column($mes['colunas'], 'detalhe'));
        $this->assertSame(['2026-09-14', '2026-09-20'], [$mes['colunas'][2]['de'], $mes['colunas'][2]['ate']]);
        $this->assertSame(12600, $mes['totais']['2026-09-14']['segundos']);
        $this->assertSame(['2026-09-01', '2026-09-06'], [$mes['colunas'][0]['de'], $mes['colunas'][0]['ate']]);

        $ano = $this->grelha('2026-01-01', '2026-12-31');
        $this->assertSame('mes', $ano['escala']);
        $this->assertCount(12, $ano['colunas']);
        $this->assertSame(['Ago', 'Set'], [$ano['colunas'][7]['rotulo'], $ano['colunas'][8]['rotulo']]);
        $this->assertSame([900, 12600], [$ano['totais']['2026-08']['segundos'], $ano['totais']['2026-09']['segundos']]);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$ano['colunas'][8]['de'], $ano['colunas'][8]['ate']]);
    }

    public function test_tecnico_so_ve_as_suas_horas_sem_valores(): void
    {
        $this->actingAs($this->ana)->get('/relatorios/semanal')->assertOk()->assertSee('Semanal — Nexus Tempos', false);

        Livewire::actingAs($this->ana)->withQueryParams(['mostrar' => 'ambos'])->test(Semanal::class)
            ->assertSet('mostrar', 'tempo')
            ->assertSee('3:00:00')
            ->assertDontSee('Rui Costa')
            ->assertDontSeeHtml('aria-label="Mostrar"')
            ->assertDontSee('90,00 €');
    }

    public function test_admin_mostra_valor_ordena_liga_ao_detalhado_e_exporta(): void
    {
        $pagina = Livewire::actingAs($this->admin)->test(Semanal::class)
            ->assertSee('Esta semana')
            ->assertSee('3:30:00')
            ->assertSeeInOrder(['Sem projeto', 'Obra'])
            ->assertSee('Rui Costa')
            ->call('ordenarPor', 'nome')
            ->assertSet('ordem', 'nome')
            ->assertSeeInOrder(['Obra', 'Sem projeto'])
            ->set('mostrar', 'valor')
            ->assertSee('90,00 €')
            ->call('ordenarPor', 'total')
            ->assertSet('ordem', '-total')
            ->assertSeeInOrder(['Obra', 'Sem projeto']) // por valor
            ->set('agrupar2', 'projeto')
            ->assertSet('agrupar2', '') // igual ao 1.º: fica sem subgrupo
            ->set('agrupar1', 'membro')
            ->assertSeeInOrder(['Ana Martins', 'Rui Costa']);

        // Cada célula abre o Detalhado com as datas da coluna e o grupo.
        $url = $pagina->instance()->ligacao('2026-09-14', '2026-09-14', (string) $this->rui->id);
        $this->assertStringContainsString('/relatorios/detalhado?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['periodo' => 'datas', 'de' => '2026-09-14', 'ate' => '2026-09-14', 'membros' => [(string) $this->rui->id]], $q);

        $pagina->set('agrupar1', 'projeto')->set('agrupar2', 'membro')->set('clientes', [(string) $this->hospital->id]);
        parse_str(parse_url($pagina->instance()->ligacao('2026-09-14', '2026-09-20', '', (string) $this->ana->id), PHP_URL_QUERY), $q);
        $this->assertSame(['0'], $q['projetos']); // sem projeto
        $this->assertSame([(string) $this->ana->id], $q['membros']);
        $this->assertSame([(string) $this->hospital->id], $q['clientes']);

        $pagina->set('mostrar', 'ambos')->call('exportar')->assertFileDownloaded('semanal-20260914-20260920.csv');
    }
}

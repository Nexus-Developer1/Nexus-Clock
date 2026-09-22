<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Resumo;
use App\Models\Cliente;
use App\Models\ClienteTempo;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\TaxaMembro;
use App\Models\User;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\ResumoTempos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Relatório Resumo: totais (faturável, valor, custo), filtros, dois agrupamentos, gráfico por dia ou
// mês, período à escolha. Quem não vê a equipa só vê as suas horas e sem valores.
class ResumoTemposTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private Cliente $hospital;

    private Cliente $banco;

    private ProjetoTempo $obra;

    private ProjetoTempo $interno;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14/09–20/09

        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        app(GestorEquipa::class)->sincronizar();

        $this->hospital = $this->cliente('Hospital');
        $this->banco = $this->cliente('Banco');
        // O cliente dos relatórios é o cliente (dos Tempos) do projeto: a obra é do Hospital, o interno do Banco.
        $this->obra = ProjetoTempo::create(['nome' => 'Obra', 'taxa_cent' => 6000, 'cliente_id' => ClienteTempo::create(['nome' => 'Hospital'])->id]);
        $this->interno = ProjetoTempo::create(['nome' => 'Interno', 'faturavel' => false, 'cliente_id' => ClienteTempo::create(['nome' => 'Banco'])->id]);

        $membro = fn (User $u) => MembroEquipa::where('utilizador_id', $u->id)->sole()->id;
        TaxaMembro::create(['membro_id' => $membro($this->ana), 'tipo' => 'faturavel', 'valor_cent' => 4000, 'valido_de' => '2026-01-01']);
        TaxaMembro::create(['membro_id' => $membro($this->ana), 'tipo' => 'custo', 'valor_cent' => 2000, 'valido_de' => '2026-01-01']);
        TaxaMembro::create(['membro_id' => $membro($this->rui), 'tipo' => 'custo', 'valor_cent' => 1000, 'valido_de' => '2026-09-16']);

        // Ana: 1 h na obra (60 €), 2 h sem projeto (40 €/h), 1 h interno (não faturável).
        $this->registo($this->ana, $this->hospital, '2026-09-14', 3600, ['projeto_id' => $this->obra->id, 'faturavel' => true, 'descricao' => 'Revisão', 'etiquetas' => ['urgente', 'noite']]);
        $this->registo($this->ana, $this->banco, '2026-09-15', 7200, ['faturavel' => true, 'descricao' => 'Reparação UPS']);
        $this->registo($this->ana, $this->hospital, '2026-09-15', 3600, ['projeto_id' => $this->interno->id, 'faturavel' => true, 'descricao' => 'Formação']);
        // Rui: 30 min não faturável na obra (custo 10 €/h a partir de 16/09 → 0 no dia 15).
        $this->registo($this->rui, $this->hospital, '2026-09-15', 1800, ['projeto_id' => $this->obra->id, 'faturavel' => false, 'descricao' => 'Revisão']);
        // Fora do período, anulado e a correr: não contam.
        $this->registo($this->ana, $this->hospital, '2026-09-21', 3600, ['projeto_id' => $this->obra->id]);
        $this->registo($this->ana, $this->hospital, '2026-09-16', 3600)->delete();
        RegistoTempo::create(['tecnico_id' => $this->ana->id, 'cliente_id' => $this->hospital->id, 'inicio' => '2026-09-17 08:00:00+00']);
    }

    private function gerar(array $filtros = [], string $g1 = 'projeto', ?string $g2 = null, string $de = '2026-09-14', string $ate = '2026-09-20', string $cor = 'faturabilidade'): array
    {
        return app(ResumoTempos::class)->gerar($this->admin, $filtros, CarbonImmutable::parse($de), CarbonImmutable::parse($ate), $g1, $g2, $cor);
    }

    public function test_totais_valor_custo_e_dois_agrupamentos(): void
    {
        $d = $this->gerar(g2: 'membro');

        $this->assertSame([16200, 10800, 6000 + 8000, 2000 * 4], [$d['total'], $d['faturavel'], $d['valor'], $d['custo']]);
        $this->assertSame(['Sem projeto', 'Obra', 'Interno'], array_column($d['grupos'], 'nome'));
        $this->assertSame([7200, 5400, 3600], array_column($d['grupos'], 'segundos'));

        $obra = $d['grupos'][1];
        $this->assertSame([6000, 2000], [$obra['valor'], $obra['custo']]);
        $this->assertSame([['Ana Martins', 3600], ['Rui Costa', 1800]], array_map(fn ($f) => [$f['nome'], $f['segundos']], $obra['filhos']));

        $this->assertCount(7, $d['barras']);
        $this->assertFalse($d['mensal']);
        $this->assertSame(['faturavel' => 7200, 'nao_faturavel' => 5400], $d['barras'][1]['partes']);
        $this->assertSame(12600, $d['maximo']);

        // Por etiqueta: cada registo conta em cada etiqueta, mas os totais e o gráfico não duplicam.
        $e = $this->gerar(g1: 'etiqueta');
        $this->assertSame(['Sem etiqueta' => 12600, 'noite' => 3600, 'urgente' => 3600], collect($e['grupos'])->pluck('segundos', 'nome')->sortKeys()->all());
        $this->assertSame([16200, 3600], [$e['total'], $e['barras'][0]['total']]);

        // Cores pelo 1.º agrupamento.
        $c = $this->gerar(g1: 'cliente', cor: 'grupo');
        $this->assertSame(['Sem cliente', 'Hospital', 'Banco'], array_column($c['grupos'], 'nome'));
        $this->assertSame([(string) $this->obra->cliente_id => 1800, (string) $this->interno->cliente_id => 3600, 'outros' => 7200], $c['barras'][1]['partes']);
    }

    public function test_filtros(): void
    {
        $total = fn (array $f) => $this->gerar($f)['total'];

        $this->assertSame(1800, $total(['membros' => [$this->rui->id]]));
        $this->assertSame(3600, $total(['clientes' => [$this->interno->cliente_id]]));
        $this->assertSame(7200, $total(['clientes' => [0]])); // sem cliente = sem projeto ou projeto sem cliente
        $this->assertSame(7200 + 5400, $total(['clientes' => [0, $this->obra->cliente_id]]));
        $this->assertSame(7200 + 3600, $total(['projetos' => [0, $this->interno->id]]));
        $this->assertSame(3600, $total(['etiquetas' => ['noite', 'nada']]));
        $this->assertSame(10800, $total(['estado' => 'faturavel']));
        $this->assertSame(5400, $total(['estado' => 'nao_faturavel']));
        $this->assertSame(0, $total(['estado' => 'faturado']));
        $this->assertSame(10800, $total(['estado' => 'por_faturar']));
        $this->assertSame(5400, $total(['descricao' => 'revis']));
        $this->assertSame(0, $total(['descricao' => '100%']));

        // Períodos longos agrupam por mês.
        $ano = $this->gerar(de: '2026-01-01', ate: '2026-12-31');
        $this->assertTrue($ano['mensal']);
        $this->assertCount(12, $ano['barras']);
        $this->assertSame(16200 + 3600, $ano['barras'][8]['total']);
        $this->assertSame('Setembro 2026', $ano['barras'][8]['dica']);
    }

    public function test_pagina_tecnico_so_ve_as_suas_horas_sem_valores(): void
    {
        $this->actingAs($this->ana)->get('/relatorios/resumo')->assertOk()->assertSee('Resumo — Nexus Tempos', false);

        Livewire::actingAs($this->ana)->withQueryParams(['membros' => [(string) $this->rui->id]])->test(Resumo::class)
            ->assertSee('4:00:00') // total da Ana, apesar do filtro
            ->assertDontSee('Rui Costa')
            ->assertDontSeeHtml('aria-label="Mostrar valor"')
            ->assertDontSee('60,00 €')
            ->call('exportar')
            ->assertFileDownloaded('resumo-20260914-20260920.csv');
    }

    public function test_pagina_admin_filtra_ordena_navega_e_exporta(): void
    {
        Livewire::actingAs($this->admin)->test(Resumo::class)
            ->assertSee('Esta semana')
            ->assertSee('4:30:00')
            ->assertSee('140,00 €')
            ->assertSeeInOrder(['Sem projeto', 'Obra', 'Interno'])
            ->call('ordenarPor', 'duracao')
            ->assertSet('ordem', 'duracao')
            ->assertSeeInOrder(['Interno', 'Obra', 'Sem projeto'])
            ->call('ordenarPor', 'titulo')
            ->assertSeeInOrder(['Interno', 'Obra', 'Sem projeto'])
            ->set('mostrarValor', 'lucro')
            ->assertSee('Lucro')
            ->assertSee('60,00 €') // 140 − 80
            ->set('membros', [(string) $this->rui->id])
            ->assertSee('0:30:00')
            ->assertSee('Limpar filtros')
            ->call('limparFiltros')
            ->assertSee('4:30:00')
            ->set('agrupar1', 'descricao')
            ->set('agrupar2', 'descricao')
            ->assertSet('agrupar2', '')
            ->call('anterior')
            ->assertSee('Semana passada')
            ->assertSee('Sem dados')
            ->call('escolherPeriodo', 'ano')
            ->assertSee('Este ano')
            ->assertSet('inicio', '2026-01-01')
            ->set('escolhaDe', '2026-09-20')->set('escolhaAte', '2026-09-14')
            ->call('aplicarDatas')
            ->assertHasErrors('datas')
            ->set('escolhaDe', '2026-09-15')->set('escolhaAte', '2026-09-15')
            ->call('aplicarDatas')
            ->assertSet('tipo', 'datas')
            ->assertSee('15/09 – 15/09/2026')
            ->assertSee('3:30:00')
            ->call('seguinte')
            ->assertSet('inicio', '2026-09-16')->assertSet('fim', '2026-09-16')
            ->call('exportar')
            ->assertFileDownloaded('resumo-20260916-20260916.csv');

        // URL inválido volta ao que é por omissão.
        Livewire::actingAs($this->admin)->withQueryParams(['periodo' => 'datas', 'de' => '2026-09-20', 'ate' => '2026-01-01', 'agrupar' => 'x', 'ordem' => 'y', 'membros' => ['a', '3']])->test(Resumo::class)
            ->assertSet('tipo', 'semana')
            ->assertSet('inicio', '2026-09-14')
            ->assertSet('agrupar1', 'projeto')
            ->assertSet('ordem', '-duracao')
            ->assertSet('membros', ['3']);
    }
}

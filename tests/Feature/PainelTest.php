<?php

namespace Tests\Feature;

use App\Livewire\Painel\Pagina;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\PainelTempos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Painel: tempo total, projeto e cliente com mais horas, horas por dia, distribuição por
// projeto/cliente/etiqueta e atividades mais registadas, na semana ou mês, das minhas horas ou da equipa.
class PainelTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $rui;

    private Cliente $hospital;

    private Cliente $banco;

    private Contrato $ctHospital;

    private Contrato $ctBanco;

    private ProjetoTempo $obraH;

    private ProjetoTempo $obraB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00'); // terça-feira; semana 14/09–20/09

        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        $this->hospital = $this->cliente('Hospital da Luz');
        $this->banco = $this->cliente('Banco Atlântico');
        $this->ctHospital = $this->contrato($this->hospital, 'CT-H');
        $this->ctBanco = $this->contrato($this->banco, 'CT-B');
        $this->obraH = ProjetoTempo::create(['nome' => 'Obra H']);
        $this->obraB = ProjetoTempo::create(['nome' => 'Obra B']);
    }

    private function gerar(?User $quem, string $agrupar = 'projeto', string $de = '2026-09-14', string $ate = '2026-09-20'): array
    {
        return app(PainelTempos::class)->gerar($quem?->id, $agrupar, CarbonImmutable::parse($de), CarbonImmutable::parse($ate));
    }

    public function test_totais_topo_e_horas_por_dia_so_com_registos_terminados_do_periodo(): void
    {
        $this->registo($this->ana, $this->hospital, '2026-09-14', 3600, ['projeto_id' => $this->obraH->id, 'contrato_id' => $this->ctHospital->id, 'descricao' => 'Manutenção']);
        $this->registo($this->ana, $this->hospital, '2026-09-15', 1800, ['projeto_id' => $this->obraH->id, 'contrato_id' => $this->ctHospital->id, 'descricao' => 'Manutenção']);
        $this->registo($this->ana, $this->banco, '2026-09-15', 2700, ['projeto_id' => $this->obraB->id, 'contrato_id' => $this->ctBanco->id, 'descricao' => 'Reparação']);
        $this->registo($this->ana, $this->banco, '2026-09-21', 9000, ['projeto_id' => $this->obraB->id, 'contrato_id' => $this->ctBanco->id]); // semana seguinte
        $this->registo($this->rui, $this->banco, '2026-09-16', 9000, ['projeto_id' => $this->obraB->id, 'contrato_id' => $this->ctBanco->id]); // outro técnico
        RegistoTempo::create(['tecnico_id' => $this->ana->id, 'cliente_id' => $this->banco->id, 'inicio' => '2026-09-15 08:00:00+00']); // a correr

        $dados = $this->gerar($this->ana);

        $this->assertSame(8100, $dados['total']);
        $this->assertSame(['nome' => 'Obra H', 'segundos' => 5400], $dados['topProjeto']);
        $this->assertSame(['nome' => 'Hospital da Luz', 'segundos' => 5400], $dados['topCliente']);
        $this->assertCount(7, $dados['dias']);
        $this->assertSame([3600, 4500, 0, 0, 0, 0, 0], array_column($dados['dias'], 'total'));
        $this->assertSame([(string) $this->obraH->id => 1800, (string) $this->obraB->id => 2700, 'outros' => 0], $dados['dias'][1]['partes']);
        $this->assertSame(4500, $dados['maximoDia']);
        $this->assertSame([66.7, 33.3], array_column($dados['grupos'], 'percentagem'));

        $this->assertSame('Manutenção', $dados['atividades'][0]['descricao']);
        $this->assertSame('Obra H · CT-H · Hospital da Luz', $dados['atividades'][0]['detalhe']);

        // Por contrato continua disponível.
        $this->assertSame(['CT-H', 'CT-B'], array_column($this->gerar($this->ana, 'contrato')['grupos'], 'nome'));
        $this->assertSame(5400, $dados['atividades'][0]['segundos']);

        // Equipa: soma o Rui; o banco passa a ser o principal.
        $equipa = $this->gerar(null);
        $this->assertSame(17100, $equipa['total']);
        $this->assertSame('Obra B', $equipa['topProjeto']['nome']);
    }

    public function test_agrupar_por_cliente_e_por_etiqueta_com_grupo_sem_etiqueta(): void
    {
        $this->registo($this->ana, $this->hospital, '2026-09-14', 3600, ['etiquetas' => ['urgente', 'noturno']]);
        $this->registo($this->ana, $this->banco, '2026-09-15', 1800, ['etiquetas' => ['urgente']]);
        $this->registo($this->ana, $this->banco, '2026-09-15', 600);

        $porCliente = $this->gerar($this->ana, 'cliente');
        $this->assertSame(['Hospital da Luz', 'Banco Atlântico'], array_column($porCliente['grupos'], 'nome'));
        $this->assertNull($porCliente['topProjeto']);

        $porEtiqueta = collect($this->gerar($this->ana, 'etiqueta')['grupos'])->pluck('segundos', 'nome')->all();
        $this->assertSame(['urgente' => 5400, 'noturno' => 3600, 'Sem etiqueta' => 600], $porEtiqueta);
    }

    public function test_mais_de_cinco_grupos_juntam_se_em_outros(): void
    {
        foreach (range(1, 7) as $n) {
            $this->registo($this->ana, $this->cliente('Cliente '.$n), '2026-09-14', 600 * $n);
        }

        $dados = $this->gerar($this->ana, 'cliente');

        $this->assertCount(PainelTempos::GRUPOS_COM_COR, $dados['series']);
        $this->assertCount(7, $dados['grupos']);
        $this->assertSame(600 + 1200, $dados['dias'][0]['partes']['outros']);
    }

    public function test_pagina_mostra_as_minhas_horas_e_so_quem_ve_todos_escolhe_equipa(): void
    {
        $this->registo($this->ana, $this->hospital, '2026-09-14', 3600, ['projeto_id' => $this->obraH->id, 'contrato_id' => $this->ctHospital->id]);
        $this->registo($this->rui, $this->banco, '2026-09-14', 7200, ['projeto_id' => $this->obraB->id, 'contrato_id' => $this->ctBanco->id]);

        $this->actingAs($this->ana)->get(route('painel'))
            ->assertOk()
            ->assertSee('Painel — Nexus Tempos', false)
            ->assertSee('1:00:00')
            ->assertDontSee('Obra B')
            ->assertDontSeeHtml('aria-label="De quem"');

        // Um técnico não consegue forçar a equipa pelo URL.
        Livewire::actingAs($this->ana)->withQueryParams(['quem' => 'equipa'])->test(Pagina::class)
            ->assertSet('quem', 'eu')
            ->assertSee('1:00:00');

        Livewire::actingAs($this->admin())->withQueryParams(['quem' => 'equipa'])->test(Pagina::class)
            ->assertSet('quem', 'equipa')
            ->assertSee('3:00:00')
            ->assertSee('Obra B');
    }

    public function test_periodos_atalhos_e_navegacao(): void
    {
        Livewire::actingAs($this->ana)->test(Pagina::class)
            ->assertSet('tipo', 'semana')
            ->assertSet('inicio', '2026-09-14')
            ->assertSee('Esta semana')
            ->call('anterior')
            ->assertSet('inicio', '2026-09-07')
            ->assertSee('Semana passada')
            ->call('anterior')
            ->assertSee('31/08 – 06/09/2026')
            ->call('escolherPeriodo', 'mes')
            ->assertSet('tipo', 'mes')
            ->assertSet('inicio', '2026-09-01')
            ->assertSee('Este mês')
            ->call('seguinte')
            ->assertSet('inicio', '2026-10-01')
            ->assertSee('Outubro 2026')
            ->call('escolherPeriodo', 'mes-passado')
            ->assertSet('inicio', '2026-08-01');

        // Datas inválidas no URL voltam ao início do período atual.
        Livewire::actingAs($this->ana)->withQueryParams(['de' => 'ontem', 'periodo' => 'ano', 'agrupar' => 'x'])->test(Pagina::class)
            ->assertSet('inicio', '2026-09-14')
            ->assertSet('tipo', 'semana')
            ->assertSet('agrupar', 'projeto');

        // Um dia a meio da semana alinha à segunda-feira.
        Livewire::actingAs($this->ana)->withQueryParams(['de' => '2026-09-17'])->test(Pagina::class)
            ->assertSet('inicio', '2026-09-14');
    }

    public function test_faturavel_comparacao_com_periodo_anterior_membro_e_atividade_da_equipa(): void
    {
        $interno = ProjetoTempo::create(['nome' => 'Interno', 'faturavel' => false]);
        $this->registo($this->ana, $this->hospital, '2026-09-14', 3600, ['projeto_id' => $this->obraH->id, 'faturavel' => true, 'descricao' => 'Revisão']);
        $this->registo($this->ana, $this->hospital, '2026-09-15', 1800, ['projeto_id' => $interno->id, 'faturavel' => true]);
        $this->registo($this->rui, $this->banco, '2026-09-15', 7200, ['faturavel' => false, 'descricao' => 'Instalação']);
        $this->registo($this->ana, $this->hospital, '2026-09-08', 9000); // semana anterior
        RegistoTempo::create(['tecnico_id' => $this->rui->id, 'cliente_id' => $this->banco->id, 'projeto_id' => $this->obraB->id, 'inicio' => '2026-09-17 08:13:00+00', 'descricao' => 'Baterias']); // a correr

        $servico = app(PainelTempos::class);
        $this->assertSame(['total' => 12600, 'faturavel' => 3600], $servico->totais(null, CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-20')));
        $this->assertSame(['total' => 9000, 'faturavel' => 9000], $servico->totais($this->ana->id, CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-13')));

        // Por membro (só na equipa).
        $this->assertSame(['Rui Costa' => 7200, 'Ana Martins' => 5400], collect($this->gerar(null, 'membro')['grupos'])->pluck('segundos', 'nome')->all());

        // Atividade da equipa: quem regista primeiro, depois por horas; último registo terminado.
        $equipa = collect($servico->equipa(CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-20')));
        $this->assertSame(['Rui Costa', 'Ana Martins'], $equipa->pluck('nome')->take(2)->all());
        $rui = $equipa->firstWhere('id', $this->rui->id);
        $this->assertSame(['Baterias', 'Obra B', '09:13'], [$rui['aCorrer']['descricao'], $rui['aCorrer']['projeto'], $rui['aCorrer']['desde']->format('H:i')]);
        $this->assertSame(['Instalação', '2026-09-15'], [$rui['ultimo']['descricao'], $rui['ultimo']['quando']->toDateString()]);
        $ana = $equipa->firstWhere('id', $this->ana->id);
        $this->assertNull($ana['aCorrer']);
        $this->assertSame([5400, 'Sem descrição'], [$ana['segundos'], $ana['ultimo']['descricao']]);

        $admin = $this->admin();
        $admin->update(['nome' => 'Suporte Nexus']);
        $pagina = Livewire::actingAs($admin)->withQueryParams(['quem' => 'equipa', 'agrupar' => 'membro'])->test(Pagina::class)
            ->assertSet('agrupar', 'membro')
            ->assertSee('3:30:00')
            ->assertSee('1:00:00')
            ->assertSee('▲ 1:00 vs. período anterior')
            ->assertSee('28,6% do total')
            ->assertSee('Atividade da equipa')
            ->assertSee('1 a registar')
            ->assertSee('desde 09:13')
            ->assertSeeHtml('membros%5B0%5D='.$this->rui->id)
            ->assertSeeHtml('descricao=Revis')
            ->assertSeeHtml('periodo=datas&amp;de=2026-09-15&amp;ate=2026-09-15');

        // Só eu: sem membro nem equipa; as ligações levam o filtro do próprio.
        $this->registo($admin, $this->hospital, '2026-09-16', 600, ['descricao' => 'Apoio']);
        $pagina->set('quem', 'eu')
            ->assertSet('agrupar', 'projeto')
            ->assertDontSee('Atividade da equipa')
            ->assertSeeHtml('membros%5B0%5D='.$admin->id);

        // Um técnico não agrupa por membro.
        Livewire::actingAs($this->ana)->withQueryParams(['agrupar' => 'membro'])->test(Pagina::class)
            ->assertSet('agrupar', 'projeto')
            ->assertSee('▼ 1:00 vs. período anterior');
    }
}

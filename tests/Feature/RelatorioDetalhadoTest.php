<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Detalhado;
use App\Models\Cliente;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\EdicaoEmMassa;
use App\Services\Tempos\ResumoTempos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Relatório Detalhado: registos um a um com auditoria de tempo, ordenação, páginas, acrescentar tempo
// (para outros, quem gere), alterar, duplicar, apagar, anular faturados e edição em massa (com projeto).
class RelatorioDetalhadoTest extends TestCase
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
    }

    public function test_registos_por_ordem_com_valor_e_auditoria(): void
    {
        $a = $this->registo($this->ana, $this->hospital, '2026-09-14', 3600, ['projeto_id' => $this->obra->id, 'faturavel' => true, 'descricao' => 'Revisão', 'etiquetas' => ['urgente']]);
        $b = $this->registo($this->rui, $this->hospital, '2026-09-15', 9 * 3600, ['descricao' => 'Instalação']);
        $c = $this->registo($this->ana, $this->hospital, '2026-09-16', 1800);

        $servico = app(ResumoTempos::class);
        $ids = fn (array $filtros = [], string $ordem = '-data') => $servico->registos($this->admin, $filtros, CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-20'), $ordem)->pluck('id')->all();

        $this->assertSame([$c->id, $b->id, $a->id], $ids());
        $this->assertSame([$c->id, $a->id, $b->id], $ids(ordem: 'duracao'));
        $this->assertSame([$a->id, $c->id, $b->id], $ids(ordem: 'membro'));
        $this->assertSame([$c->id, $b->id, $a->id], $ids(ordem: 'descricao')); // sem descrição primeiro
        $this->assertSame([$c->id, $b->id], $ids(['auditoria' => 'sem_projeto']));
        $this->assertSame([$c->id], $ids(['auditoria' => 'sem_descricao']));
        $this->assertSame([$c->id, $b->id], $ids(['auditoria' => 'sem_etiquetas']));
        $this->assertSame([$b->id], $ids(['auditoria' => 'longos']));

        $linha = $servico->registos($this->admin, [], CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-14'))->first();
        $this->assertSame([true, 6000], [(bool) $linha->fat, (int) $linha->valor]);
        $this->assertSame(['total' => 37800, 'faturavel' => 37800, 'valor' => 6000, 'custo' => 0, 'registos' => 3],
            $servico->totais($this->admin, [], CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-20')));
    }

    public function test_edicao_em_massa_muda_projeto(): void
    {
        $a = $this->registo($this->ana, $this->hospital, '2026-09-14', 3600);
        $b = $this->registo($this->ana, $this->hospital, '2026-09-15', 3600, ['projeto_id' => $this->obra->id]);
        $massa = app(EdicaoEmMassa::class);

        $this->assertSame(2, $massa->aplicar($this->admin, [$a->id, $b->id], ['projeto' => $this->obra->id]));
        $this->assertSame([$this->obra->id, $this->obra->id], [$a->fresh()->projeto_id, $b->fresh()->projeto_id]);

        $this->assertSame(2, $massa->aplicar($this->admin, [$a->id, $b->id], ['projeto' => 0]));
        $this->assertNull($a->fresh()->projeto_id);

        $arquivado = ProjetoTempo::create(['nome' => 'Velho']);
        $arquivado->forceFill(['arquivado_em' => now()])->save();
        try {
            $massa->aplicar($this->admin, [$a->id], ['projeto' => $arquivado->id]);
            $this->fail('Devia recusar um projeto arquivado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('O projeto «Velho» está arquivado.', $e->errors()['massa'][0]);
        }

        $this->expectException(ValidationException::class);
        $massa->aplicar($this->admin, [$a->id], ['projeto' => null]); // nada para mudar
    }

    public function test_tecnico_ve_e_mexe_so_nas_suas_horas(): void
    {
        $meu = $this->registo($this->ana, $this->hospital, '2026-09-14', 3600, ['descricao' => 'Meu trabalho']);
        $alheio = $this->registo($this->rui, $this->hospital, '2026-09-14', 7200, ['descricao' => 'Trabalho do Rui']);

        $this->actingAs($this->ana)->get('/relatorios/detalhado')->assertOk()->assertSee('Detalhado — Nexus Suporte', false);

        Livewire::actingAs($this->ana)->test(Detalhado::class)
            ->assertSee('Meu trabalho')
            ->assertDontSee('Trabalho do Rui')
            ->assertDontSeeHtml('aria-label="Mostrar valor"')
            // Acrescentar tempo: com horas reais…
            ->call('novo')
            ->assertSet('formulario.tecnico_id', (string) $this->ana->id)
            ->set('formulario.descricao', 'Visita')
            ->set('formulario.dia', '2026-09-15')
            ->set('formulario.hora_inicio', '22:00')
            ->set('formulario.hora_fim', '01:30')
            ->set('formulario.projeto_id', (string) $this->obra->id)
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSee('Registo acrescentado.')
            ->assertSee('22:00 – 01:30')
            // …e com erros.
            ->call('novo')
            ->call('guardar')
            ->assertHasErrors(['formulario.duracao'])
            ->set('formulario.duracao', 'uma hora')
            ->call('guardar')
            ->assertHasErrors(['formulario.duracao'])
            ->set('formulario.duracao', '1:30')
            // Não acrescenta para outro membro.
            ->set('formulario.tecnico_id', (string) $this->rui->id)
            ->call('guardar')
            ->assertHasErrors(['formulario.geral'])
            ->call('fecharFormulario')
            // Alterar, duplicar e apagar o seu.
            ->call('editar', $meu->id)
            ->assertSet('formulario.duracao', '1:00')
            ->set('formulario.duracao', '2:15')
            ->call('guardar')
            ->assertSee('Registo alterado.')
            ->call('duplicar', $meu->id)
            ->assertSee('Registo duplicado.')
            ->call('apagar', $meu->id)
            ->assertSee('Registo apagado.')
            // O do Rui não.
            ->call('apagar', $alheio->id)
            ->assertSet('erro', 'Não pode alterar este registo: está numa semana entregue, num mês fechado, já foi faturado, ou é de outra pessoa.');

        $novo = RegistoTempo::where('descricao', 'Visita')->sole();
        $this->assertSame([3.5 * 3600, $this->obra->id, '2026-09-15'], [(float) $novo->duracao_seg, $novo->projeto_id, $novo->dia()->toDateString()]);
        $this->assertSame(8100, RegistoTempo::where('descricao', 'Meu trabalho')->sole()->duracao_seg); // o duplicado
        $this->assertNotSoftDeleted($alheio);
    }

    public function test_admin_acrescenta_para_outros_filtra_pagina_edita_em_massa_anula_e_exporta(): void
    {
        foreach (range(1, 55) as $n) {
            $this->registo($n % 2 ? $this->ana : $this->rui, $this->hospital, '2026-09-14', 60 * $n, ['descricao' => 'Tarefa '.$n]);
        }
        $faturado = $this->registo($this->ana, $this->hospital, '2026-09-16', 3600, ['descricao' => 'Já faturado', 'faturado_em' => now()]);

        $pagina = Livewire::actingAs($this->admin)->test(Detalhado::class)
            ->assertSee('56 registos')
            ->assertSee('1–50 de 56')
            ->call('nextPage')
            ->assertSee('51–56 de 56')
            ->set('membros', [(string) $this->rui->id])
            ->assertSee('27 registos')
            ->assertDontSee('1–50') // voltou à 1.ª página e cabe numa
            ->call('limparFiltros')
            ->set('auditoria', 'sem_projeto')
            ->assertSee('56 registos')
            ->call('ordenarPor', 'duracao')
            ->assertSet('ordem', '-duracao')
            ->assertSeeInOrder(['Tarefa 55', 'Tarefa 54'])
            // Acrescentar para o Rui.
            ->call('novo')
            ->set('formulario.tecnico_id', (string) $this->rui->id)
            ->set('formulario.duracao', '0:45')
            ->set('formulario.descricao', 'Para o Rui')
            ->call('guardar')
            ->assertHasNoErrors()
            // Edição em massa: dois registos para a obra, faturáveis, com etiqueta.
            ->set('selecionados', [(string) RegistoTempo::where('descricao', 'Tarefa 1')->value('id'), (string) RegistoTempo::where('descricao', 'Tarefa 2')->value('id')])
            ->assertSee('2 selecionados')
            ->set('massaProjeto', (string) $this->obra->id)
            ->set('massaFaturavel', 'sim')
            ->set('massaAcrescentar', 'revisto')
            ->call('aplicarMassa')
            ->assertSee('2 registos alterados.')
            ->assertSet('selecionados', [])
            // Anular o faturado.
            ->set('auditoria', '')
            ->assertSee('Já faturado')
            ->call('pedirAnulacao', $faturado->id)
            ->call('anular')
            ->assertHasErrors('motivo')
            ->set('motivo', 'Faturado por engano')
            ->call('anular')
            ->assertSee('Registo anulado.')
            ->assertDontSee('Já faturado');

        $this->assertSame($this->rui->id, RegistoTempo::where('descricao', 'Para o Rui')->sole()->tecnico_id);
        $tarefa1 = RegistoTempo::where('descricao', 'Tarefa 1')->sole();
        $this->assertSame([$this->obra->id, true, ['revisto']], [$tarefa1->projeto_id, $tarefa1->faturavel, $tarefa1->etiquetas]);
        $this->assertSoftDeleted($faturado);

        $pagina->call('exportar')->assertFileDownloaded('detalhado-20260914-20260920.csv');
        $pagina->call('exportar', 'pdf')->assertFileDownloaded('detalhado-20260914-20260920.pdf');
    }
}

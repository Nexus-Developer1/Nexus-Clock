<?php

namespace Tests\Feature;

use App\Livewire\Projetos\Listagem;
use App\Models\Auditoria;
use App\Models\ClienteTempo;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\TaxaMembro;
use App\Models\User;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\GestorProjetos;
use App\Services\Tempos\GravadorRegistos;
use App\Services\Tempos\HorasProjetos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Página Projetos: lista própria com cliente, cor, acesso público/privado (membros), faturável com taxa
// própria ou a do membro, estimativa (progresso) e favoritos; horas e valor vêm dos registos com
// projeto. Só admin gere; técnico vê os públicos e os privados de que é membro, sem valores.
class ProjetosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private GestorProjetos $gestor;

    private ClienteTempo $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00');

        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        app(GestorEquipa::class)->sincronizar();
        $this->gestor = app(GestorProjetos::class);
        $this->hospital = ClienteTempo::create(['nome' => 'Hospital']);
    }

    private function erros(callable $acao): array
    {
        try {
            $acao();
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }
        $this->fail('Esperava erros de validação.');
    }

    private function membroDe(User $u): MembroEquipa
    {
        return MembroEquipa::where('utilizador_id', $u->id)->sole();
    }

    public function test_criar_valida_nome_por_cliente_cor_cliente_taxa_e_estimativa_e_audita(): void
    {
        $p = $this->gestor->criar($this->admin, ['nome' => '  Obra   Norte ', 'cliente_id' => (string) $this->hospital->id, 'taxa' => '45,5', 'estimativa' => '7:30']);

        $this->assertSame(['Obra Norte', $this->hospital->id, '#16a34a', true, true, 4550, 27000], [$p->nome, $p->cliente_id, $p->cor, $p->publico, $p->faturavel, $p->taxa_cent, $p->estimativa_seg]);
        $this->assertSame(['nome' => 'Obra Norte'], Auditoria::where('acao', 'tempo_projeto_criado')->sole()->detalhe);

        // O mesmo nome noutro cliente (ou sem cliente) pode; no mesmo cliente não.
        $this->gestor->criar($this->admin, ['nome' => 'obra norte']);
        $this->assertSame(
            ['nome' => 'Este cliente já tem um projeto chamado «OBRA NORTE».'],
            $this->erros(fn () => $this->gestor->criar($this->admin, ['nome' => 'OBRA NORTE', 'cliente_id' => $this->hospital->id])),
        );
        $this->assertSame(
            ['nome' => 'Já existe um projeto sem cliente chamado «Obra norte».'],
            $this->erros(fn () => $this->gestor->criar($this->admin, ['nome' => 'Obra norte'])),
        );

        $arquivado = ClienteTempo::create(['nome' => 'Antigo']);
        $arquivado->forceFill(['arquivado_em' => now()])->save();
        $erros = $this->erros(fn () => $this->gestor->criar($this->admin, [
            'nome' => '', 'cliente_id' => $arquivado->id, 'cor' => '#000000', 'taxa' => 'muito', 'estimativa' => 'bastante',
        ]));
        $this->assertSame(['cliente_id', 'nome', 'cor', 'taxa', 'estimativa'], array_keys($erros));
        $this->assertSame('Não percebi a estimativa «bastante». Escreva as horas, por exemplo 120 ou 7:30.', $erros['estimativa']);
        $this->assertSame(['estimativa' => 'A estimativa tem de ser maior do que zero.'], $this->erros(fn () => $this->gestor->atualizar($this->admin, $p, ['estimativa' => '0'])));

        $this->assertSame(120 * 3600, GestorProjetos::lerHoras('120'));
        $this->assertSame(27000, GestorProjetos::lerHoras('7,5'));
        $this->assertNull(GestorProjetos::lerHoras(' '));

        $this->expectException(AuthorizationException::class);
        $this->gestor->criar($this->ana, ['nome' => 'Da Ana']);
    }

    public function test_horas_e_valor_a_taxa_do_projeto_ou_do_membro_no_dia(): void
    {
        $cliente = $this->cliente();
        $comTaxa = $this->gestor->criar($this->admin, ['nome' => 'Com taxa', 'taxa' => '60']);
        $semTaxa = $this->gestor->criar($this->admin, ['nome' => 'Sem taxa']);
        $interno = $this->gestor->criar($this->admin, ['nome' => 'Interno', 'faturavel' => false]);

        // Taxa da Ana: 40 €/h até 14/09, 50 €/h a partir de 15/09.
        TaxaMembro::create(['membro_id' => $this->membroDe($this->ana)->id, 'tipo' => 'faturavel', 'valor_cent' => 4000, 'valido_de' => '2026-01-01']);
        TaxaMembro::create(['membro_id' => $this->membroDe($this->ana)->id, 'tipo' => 'faturavel', 'valor_cent' => 5000, 'valido_de' => '2026-09-15']);

        $this->registo($this->ana, $cliente, '2026-09-14', 3600, ['projeto_id' => $comTaxa->id, 'faturavel' => true]);
        $this->registo($this->ana, $cliente, '2026-09-14', 5400, ['projeto_id' => $semTaxa->id, 'faturavel' => true]);  // 1,5 h × 40
        $this->registo($this->ana, $cliente, '2026-09-15', 1800, ['projeto_id' => $semTaxa->id, 'faturavel' => true]);  // 0,5 h × 50
        $this->registo($this->ana, $cliente, '2026-09-15', 3600, ['projeto_id' => $semTaxa->id, 'faturavel' => false]); // conta horas, não valor
        $this->registo($this->admin, $cliente, '2026-09-15', 3600, ['projeto_id' => $semTaxa->id, 'faturavel' => true]); // admin sem taxa: 0
        $this->registo($this->ana, $cliente, '2026-09-15', 7200, ['projeto_id' => $interno->id, 'faturavel' => true]);
        $anulado = $this->registo($this->ana, $cliente, '2026-09-16', 3600, ['projeto_id' => $comTaxa->id]);
        $anulado->delete();
        RegistoTempo::create(['tecnico_id' => $this->ana->id, 'cliente_id' => $cliente->id, 'projeto_id' => $comTaxa->id, 'inicio' => '2026-09-17 08:00:00+00']); // a correr

        $this->assertSame([
            $comTaxa->id => ['segundos' => 3600, 'valor_cent' => 6000],
            $semTaxa->id => ['segundos' => 14400, 'valor_cent' => 6000 + 2500],
            $interno->id => ['segundos' => 7200, 'valor_cent' => 0],
        ], collect(app(HorasProjetos::class)->porProjeto([$comTaxa->id, $semTaxa->id, $interno->id]))->sortKeys()->all());
    }

    public function test_privado_so_para_admin_e_membros_e_favoritos_so_nos_visiveis(): void
    {
        $privado = $this->gestor->criar($this->admin, ['nome' => 'Segredo', 'publico' => false]);
        $publico = $this->gestor->criar($this->admin, ['nome' => 'Aberto']);

        $this->assertSame(['Aberto'], ProjetoTempo::visiveisPara($this->ana)->pluck('nome')->all());
        $this->assertSame(['Aberto', 'Segredo'], ProjetoTempo::visiveisPara($this->admin)->orderBy('nome')->pluck('nome')->all());
        $this->assertSame(['projetos' => 'Projeto não encontrado.'], $this->erros(fn () => $this->gestor->alternarFavorito($this->ana, $privado)));

        $this->gestor->atualizar($this->admin, $privado, ['membros' => [$this->membroDe($this->ana)->id]]);
        $this->assertSame(['nome' => 'Segredo', 'campos' => ['membros']], Auditoria::where('acao', 'tempo_projeto_alterado')->sole()->detalhe);
        $this->assertSame(['Aberto', 'Segredo'], ProjetoTempo::visiveisPara($this->ana)->orderBy('nome')->pluck('nome')->all());
        $this->assertSame(['membros' => 'Escolha membros da equipa.'], $this->erros(fn () => $this->gestor->atualizar($this->admin, $privado, ['membros' => [999999]])));

        $this->assertTrue($this->gestor->alternarFavorito($this->ana, $privado));
        $this->assertFalse($this->gestor->alternarFavorito($this->ana, $privado));
        $this->assertTrue($this->gestor->alternarFavorito($this->ana, $publico));
    }

    public function test_arquivar_restaurar_so_apaga_arquivados_e_registos_so_aceitam_projetos_ativos(): void
    {
        $a = $this->gestor->criar($this->admin, ['nome' => 'A']);
        $b = $this->gestor->criar($this->admin, ['nome' => 'B']);
        $cliente = $this->cliente();
        $registo = $this->registo($this->ana, $cliente, '2026-09-16', 3600, ['projeto_id' => $a->id]);

        $this->assertSame(['projetos' => 'Só se apaga um projeto arquivado: arquive primeiro «A». Nada foi apagado.'], $this->erros(fn () => $this->gestor->apagar($this->admin, [$a->id])));
        $this->assertSame(2, $this->gestor->arquivar($this->admin, [$a->id, $b->id]));
        $this->assertSame(1, $this->gestor->restaurar($this->admin, [$b->id]));

        // Um registo antigo de um projeto arquivado continua editável; escolher um arquivado, não.
        $gravador = app(GravadorRegistos::class);
        $gravador->atualizar($this->ana, $registo, ['descricao' => 'Revisão']);
        $novo = $gravador->criar($this->ana, ['cliente_id' => $cliente->id, 'dia' => '2026-09-16', 'duracao_seg' => 600, 'projeto_id' => $b->id]);
        $this->assertSame($b->id, $novo->projeto_id);
        $this->assertSame(['projeto_id' => 'O projeto «A» está arquivado.'], $this->erros(fn () => $gravador->atualizar($this->ana, $novo, ['projeto_id' => $a->id])));

        $this->assertSame(1, $this->gestor->apagar($this->admin, [$a->id]));
        $this->assertSoftDeleted($a);
        $this->assertSame($a->id, $registo->fresh()->projeto_id); // soft delete: o registo mantém a ligação
        $this->assertSame(['projeto_id' => 'O projeto não existe.'], $this->erros(fn () => $gravador->atualizar($this->ana, $novo, ['projeto_id' => $a->id])));
        $this->assertSame(4, Auditoria::whereIn('acao', ['tempo_projeto_arquivado', 'tempo_projeto_restaurado', 'tempo_projeto_apagado'])->count());
    }

    public function test_pagina_cria_filtra_ordena_favoritos_primeiro_e_tecnico_nao_ve_valores(): void
    {
        $cliente = $this->cliente();
        $admin = Livewire::actingAs($this->admin)->test(Listagem::class)
            ->assertSee('Ainda sem projetos')
            ->call('novo')
            ->set('formulario.nome', '')
            ->call('guardar')
            ->assertSee('Indique o nome do projeto.')
            ->set('formulario.nome', 'Zebra')
            ->set('formulario.cliente_id', (string) $this->hospital->id)
            ->set('formulario.cor', '#2a78d6')
            ->set('formulario.publico', false)
            ->set('formulario.membros', [(string) $this->membroDe($this->ana)->id])
            ->set('formulario.estimativa', '2')
            ->call('guardar')
            ->assertSee('Projeto «Zebra» criado.')
            ->assertSet('editarId', null);

        $zebra = ProjetoTempo::where('nome', 'Zebra')->sole();
        $this->assertSame(['#2a78d6', false, 7200, [$this->membroDe($this->ana)->id]], [$zebra->cor, $zebra->publico, $zebra->estimativa_seg, $zebra->membros->pluck('id')->all()]);

        $alfa = $this->gestor->criar($this->admin, ['nome' => 'Alfa', 'taxa' => '10']);
        $this->registo($this->ana, $cliente, '2026-09-16', 10800, ['projeto_id' => $zebra->id]);
        $this->registo($this->ana, $cliente, '2026-09-16', 3600, ['projeto_id' => $alfa->id, 'faturavel' => true]);
        $beta = $this->gestor->criar($this->admin, ['nome' => 'Beta']);
        $this->registo($this->ana, $cliente, '2026-09-16', 1800, ['projeto_id' => $beta->id]);

        $admin->call('$refresh')
            ->assertSeeInOrder(['Alfa', 'Beta', 'Zebra'])
            ->assertSee('10,00 €')
            ->assertSee('150%')
            ->call('ordenarPor', 'registado')
            ->assertSeeInOrder(['Beta', 'Alfa', 'Zebra'])
            ->call('ordenarPor', 'registado')
            ->assertSet('ordem', '-registado')
            ->assertSeeInOrder(['Zebra', 'Alfa', 'Beta'])
            ->set('filtroAcesso', 'publico')
            ->assertSee('Alfa')->assertDontSee('Zebra')
            ->call('alternarFavorito', $beta->id)
            ->assertSeeInOrder(['Beta', 'Alfa'])
            ->set('filtroAcesso', '')
            ->set('filtroCliente', (string) $this->hospital->id)
            ->assertSee('Zebra')->assertDontSee('Alfa')
            ->set('filtroCliente', 'x')
            ->assertSet('filtroCliente', '')
            ->call('editar', $zebra->id)
            ->assertSet('formulario.estimativa', '2')
            ->set('formulario.publico', true)
            ->call('guardar')
            ->assertSee('Projeto guardado.')
            ->call('arquivar', $alfa->id)
            ->assertSee('Projeto arquivado.')
            ->assertDontSee('Alfa')
            ->set('mostrar', 'arquivados')
            ->assertSee('Alfa')->assertSee('Arquivado');

        $this->assertTrue($zebra->fresh()->publico);
        $admin->call('exportar')->assertFileDownloaded('projetos-20260917.csv');

        // Técnico: sem valores nem ações; favoritos primeiro.
        Livewire::actingAs($this->ana)->test(Listagem::class)
            ->assertSee('Zebra')
            ->assertDontSee('Novo projeto')->assertDontSee('Exportar CSV')->assertDontSee('Valor')->assertDontSee('10,00 €')
            ->call('alternarFavorito', $zebra->id)
            ->assertSeeHtml('aria-pressed="true"')
            ->call('exportar')
            ->assertForbidden();

        $this->actingAs($this->ana)->get('/projetos')->assertOk()->assertSee('Projetos — Nexus Suporte', false);
    }
}

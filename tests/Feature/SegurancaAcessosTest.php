<?php

namespace Tests\Feature;

use App\Livewire\Equipa\Membros;
use App\Livewire\Projetos\Listagem as ProjetosListagem;
use App\Livewire\Relatorios\Atribuicoes;
use App\Livewire\Relatorios\Detalhado;
use App\Models\AtribuicaoTempo;
use App\Models\CategoriaDespesaTempo;
use App\Models\ClienteTempo;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\GestorDespesas;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\GestorProjetos;
use App\Services\Tempos\GravadorRegistos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

// Revisão de segurança, lote 1 (notas §42): um técnico a mandar pedidos à mão pelo browser — ids e
// campos à escolha — não lê nem grava o que a página normal não lhe deixa.
class SegurancaAcessosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private ProjetoTempo $privado;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00');

        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        app(GestorEquipa::class)->sincronizar();

        // Projeto privado só com o Rui como membro.
        $this->privado = app(GestorProjetos::class)->criar($this->admin, [
            'nome' => 'Projeto Secreto', 'publico' => false, 'nota' => 'Nota interna do secreto',
            'cliente_id' => ClienteTempo::create(['nome' => 'Cliente Discreto'])->id,
            'membros' => [MembroEquipa::where('utilizador_id', $this->rui->id)->sole()->id],
        ]);
    }

    public function test_tecnico_nao_ve_as_taxas_dos_colegas(): void
    {
        $membroRui = MembroEquipa::where('utilizador_id', $this->rui->id)->sole();
        app(GestorEquipa::class)->mudarTaxa($this->admin, $membroRui, 'custo', '31,40', '2026-01-01');

        // Pela ação: 403.
        Livewire::actingAs($this->ana)->test(Membros::class)->call('abrirTaxa', $membroRui->id, 'custo')->assertForbidden();

        // Pelo campo, à mão: bloqueado.
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->ana)->test(Membros::class)->set('taxaMembroId', $membroRui->id);
    }

    public function test_quem_gere_continua_a_ver_as_taxas(): void
    {
        $membroRui = MembroEquipa::where('utilizador_id', $this->rui->id)->sole();
        app(GestorEquipa::class)->mudarTaxa($this->admin, $membroRui, 'custo', '31,40', '2026-01-01');

        Livewire::actingAs($this->admin)->test(Membros::class)->call('abrirTaxa', $membroRui->id, 'custo')
            ->assertSet('taxaMembroId', $membroRui->id)->assertSee('31,40');
    }

    public function test_tecnico_nao_le_projetos_nem_atribuicoes_pelo_formulario(): void
    {
        Livewire::actingAs($this->ana)->test(ProjetosListagem::class)->call('editar', $this->privado->id)->assertForbidden();
        Livewire::actingAs($this->ana)->test(ProjetosListagem::class)->call('novo')->assertForbidden();

        $atribuicao = AtribuicaoTempo::forceCreate([
            'utilizador_id' => $this->rui->id, 'projeto_id' => $this->privado->id, 'de' => '2026-09-14', 'ate' => '2026-09-18',
            'horas_dia_seg' => 4 * 3600, 'fins_de_semana' => false, 'nota' => 'Atribuição do Rui', 'criado_por' => $this->admin->id,
        ]);
        Livewire::actingAs($this->ana)->test(Atribuicoes::class)->call('editar', $atribuicao->id)->assertForbidden();
        Livewire::actingAs($this->ana)->test(Atribuicoes::class)->call('nova')->assertForbidden();

        // Quem gere continua a abrir tudo.
        Livewire::actingAs($this->admin)->test(ProjetosListagem::class)->call('editar', $this->privado->id)->assertSet('formulario.nome', 'Projeto Secreto');
        Livewire::actingAs($this->admin)->test(Atribuicoes::class)->call('editar', $atribuicao->id)->assertSet('formulario.nota', 'Atribuição do Rui');
    }

    public function test_total_da_selecao_so_soma_registos_que_se_podem_ver(): void
    {
        $doRui = $this->registo($this->rui, $this->cliente(), '2026-09-15', 7 * 3600 + 1234);
        $daAna = $this->registo($this->ana, $this->cliente(), '2026-09-15', 1800);

        Livewire::actingAs($this->ana)->test(Detalhado::class)
            ->set('selecionados', [(string) $doRui->id])
            ->assertViewHas('segundosSelecionados', 0)
            ->set('selecionados', [(string) $doRui->id, (string) $daAna->id])
            ->assertViewHas('segundosSelecionados', 1800);

        Livewire::actingAs($this->admin)->test(Detalhado::class)
            ->set('selecionados', [(string) $doRui->id, (string) $daAna->id])
            ->assertViewHas('segundosSelecionados', 7 * 3600 + 1234 + 1800);
    }

    public function test_nao_se_grava_em_projetos_privados_de_que_nao_se_e_membro(): void
    {
        $gravador = app(GravadorRegistos::class);
        $dados = ['tecnico_id' => $this->ana->id, 'inicio' => '2026-09-15 08:00:00+00', 'fim' => '2026-09-15 09:00:00+00', 'projeto_id' => $this->privado->id];

        // Horas: a mesma mensagem de um projeto que não existe (não revela o nome).
        try {
            $gravador->criar($this->ana, $dados);
            $this->fail('A Ana não é membro do projeto privado.');
        } catch (ValidationException $e) {
            $this->assertSame('O projeto não existe.', $e->errors()['projeto_id'][0]);
        }
        $this->assertSame(0, RegistoTempo::count());

        // O membro grava; quem gere também (e pode lançar para outros).
        $gravador->criar($this->rui, ['tecnico_id' => $this->rui->id] + $dados);
        $gravador->criar($this->admin, $dados);
        $this->assertSame(2, RegistoTempo::count());

        // Despesas: igual.
        $despesas = app(GestorDespesas::class);
        $categoria = CategoriaDespesaTempo::first()->id;
        try {
            $despesas->criar($this->ana, ['data' => '2026-09-15', 'valor' => '10', 'categoria_id' => $categoria, 'projeto_id' => $this->privado->id]);
            $this->fail('A Ana não é membro do projeto privado.');
        } catch (ValidationException $e) {
            $this->assertSame('O projeto não existe.', $e->errors()['projeto_id'][0]);
        }
        $despesas->criar($this->rui, ['data' => '2026-09-15', 'valor' => '10', 'categoria_id' => $categoria, 'projeto_id' => $this->privado->id]);

        // Retirado do projeto, o Rui continua a poder corrigir o que já lá tinha (sem mudar de projeto).
        $registo = RegistoTempo::where('tecnico_id', $this->rui->id)->sole();
        app(GestorProjetos::class)->atualizar($this->admin, $this->privado, ['membros' => []]);
        $gravador->atualizar($this->rui->fresh(), $registo, ['descricao' => 'Corrigido']);
        $this->assertSame('Corrigido', $registo->fresh()->descricao);
    }

    public function test_acesso_retirado_trava_as_acoes_de_uma_pagina_ja_aberta(): void
    {
        // A Ana abre o cronómetro com acesso.
        $this->actingAs($this->ana);
        $html = $this->get(route('cronometro'))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $snap);
        $snapshot = html_entity_decode($snap[1], ENT_QUOTES);
        $acao = fn (string $metodo) => $this->withHeaders(['X-Livewire' => '1'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => new \stdClass, 'calls' => [['path' => '', 'method' => $metodo, 'params' => []]]]],
        ]);

        // Controlo: com acesso, as ações passam pelo middleware (agora persistente) sem problema.
        // (O Livewire::test() não passa pelos middleware HTTP — só um pedido a sério prova isto.)
        $acao('semanaSeguinte')->assertOk();

        // Tiram-lhe o acesso no portal; a página continua aberta e ela carrega em «Começar».
        DB::table('acessos')->where('utilizador_id', $this->ana->id)->delete();
        $this->actingAs($this->ana->fresh());

        $acao('comecar')->assertForbidden();
        $this->assertSame(0, RegistoTempo::count(), 'nenhum cronómetro começou');

        // E as permissões abertas a toda a gente também exigem o acesso.
        $this->assertFalse(Gate::forUser($this->ana->fresh())->allows('tempos-ver-despesas'));
        $this->assertFalse(Gate::forUser($this->ana->fresh())->allows('tempos-gerir-clientes'));
    }
}

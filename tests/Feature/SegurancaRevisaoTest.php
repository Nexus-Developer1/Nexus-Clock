<?php

namespace Tests\Feature;

use App\Livewire\Equipa\Membros;
use App\Livewire\Projetos\Listagem as ProjetosListagem;
use App\Livewire\Relatorios\Atribuicoes;
use App\Livewire\Relatorios\Despesas;
use App\Livewire\Relatorios\Detalhado;
use App\Livewire\Relatorios\Resumo;
use App\Livewire\Tempos\Cronometro;
use App\Models\AtribuicaoTempo;
use App\Models\CategoriaDespesaTempo;
use App\Models\ClienteTempo;
use App\Models\DespesaTempo;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\GestorDespesas;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\GestorProjetos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

// Segunda revisão de segurança, lote A (notas §52): o que um técnico — ou uma página aberta há muito —
// conseguia ver ou fazer a mais. Cada teste foi corrido sem a correção (e falhou) antes de a fazer.
class SegurancaRevisaoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private ProjetoTempo $privado;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14–20/09
        Notification::fake();
        Storage::fake(DespesaTempo::DISCO);

        $this->admin = $this->admin();
        config(['tempos.aprovam_despesas' => [strtolower($this->admin->email)]]);
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        app(GestorEquipa::class)->sincronizar();

        // Projeto privado só com o Rui como membro.
        $this->privado = app(GestorProjetos::class)->criar($this->admin, [
            'nome' => 'Projeto Secreto', 'publico' => false,
            'cliente_id' => ClienteTempo::create(['nome' => 'Cliente Discreto'])->id,
            'membros' => [MembroEquipa::where('utilizador_id', $this->rui->id)->sole()->id],
        ]);
    }

    // --- Atribuições ---

    public function test_atribuicoes_nao_revelam_projetos_privados(): void
    {
        AtribuicaoTempo::forceCreate([
            'utilizador_id' => $this->rui->id, 'projeto_id' => $this->privado->id, 'de' => '2026-09-14', 'ate' => '2026-09-18',
            'horas_dia_seg' => 4 * 3600, 'fins_de_semana' => false, 'nota' => 'Nota secreta da atribuição', 'criado_por' => $this->admin->id,
        ]);
        $this->registo($this->rui, $this->cliente(), '2026-09-15', 3600, ['projeto_id' => $this->privado->id]);

        // Nos três agrupamentos, com a lista das atribuições do período, e com o id à mão no filtro.
        foreach ([['agrupar' => 'membro', 'depois' => 'projeto'], ['agrupar' => 'projeto', 'depois' => 'membro'], ['agrupar' => 'cliente', 'depois' => 'projeto'], ['projetos' => [(string) $this->privado->id]]] as $parametros) {
            Livewire::actingAs($this->ana)->withQueryParams($parametros)->test(Atribuicoes::class)
                ->assertDontSee('Projeto Secreto')
                ->assertDontSee('Nota secreta da atribuição');
        }
        Livewire::actingAs($this->ana)->withQueryParams(['agrupar' => 'projeto'])->test(Atribuicoes::class)
            ->assertSee('Outros projetos (privados)')
            ->assertSee('4:00'); // as horas contam na mesma

        // Quem é membro, e o admin, veem o nome.
        Livewire::actingAs($this->rui)->test(Atribuicoes::class)->assertSee('Projeto Secreto')->assertSee('Nota secreta da atribuição');
        Livewire::actingAs($this->admin)->test(Atribuicoes::class)->assertSee('Projeto Secreto');
    }

    public function test_formulario_das_atribuicoes_nao_abre_pelo_campo(): void
    {
        // Sem passar por nova()/editar(): mudar o campo à mão abria o formulário com todos os projetos.
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->ana)->test(Atribuicoes::class)->set('editarId', 0);
    }

    // --- Despesas ---

    public function test_aprovar_so_a_versao_que_se_viu(): void
    {
        $gestor = app(GestorDespesas::class);
        $d = $gestor->criar($this->ana, ['data' => '2026-09-15', 'valor' => '25', 'categoria_id' => CategoriaDespesaTempo::firstOrFail()->id]);
        $vista = $d->versao();

        // A página leva a versão no botão.
        Livewire::actingAs($this->admin)->test(Despesas::class)->assertSeeHtml('aprovar('.$d->id.', \''.$vista.'\')');

        // Entretanto a Ana muda o valor; o Paulo carrega em «Aprovar» na linha antiga.
        $gestor->atualizar($this->ana, $d->fresh(), ['valor' => '250']);
        Livewire::actingAs($this->admin)->test(Despesas::class)
            ->call('aprovar', $d->id, $vista)
            ->assertSet('erro', 'Esta despesa foi alterada entretanto: veja-a de novo antes de aprovar.');
        $this->assertSame('pendente', $d->fresh()->estado);

        // Com a versão atual, aprova.
        Livewire::actingAs($this->admin)->test(Despesas::class)->call('aprovar', $d->id, $d->fresh()->versao())->assertSet('erro', null);
        $this->assertSame(['aprovada', 25000], [$d->fresh()->estado, $d->fresh()->valor_cent]);
    }

    public function test_alteracao_com_leitura_antiga_nao_passa_depois_de_aprovada(): void
    {
        $gestor = app(GestorDespesas::class);
        $d = $gestor->criar($this->ana, ['data' => '2026-09-15', 'valor' => '10', 'categoria_id' => CategoriaDespesaTempo::firstOrFail()->id]);

        // A lê pendente; o Paulo aprova; A grava a sua cópia (a corrida entre dois pedidos).
        $leitura = DespesaTempo::findOrFail($d->id);
        $gestor->decidir($this->admin, $d->fresh(), 'aprovada');

        try {
            $gestor->atualizar($this->ana, $leitura, ['valor' => '999']);
            $this->fail('A alteração passou por cima da aprovação.');
        } catch (AuthorizationException) {
        }
        try {
            $gestor->apagar($this->ana, DespesaTempo::findOrFail($d->id)->forceFill(['estado' => 'pendente']));
            $this->fail('O apagar passou por cima da aprovação.');
        } catch (AuthorizationException) {
        }
        $this->assertSame(['aprovada', 1000], [$d->fresh()->estado, $d->fresh()->valor_cent]);
        $this->assertNull(DespesaTempo::find($d->id)?->deleted_at);
    }

    public function test_despesas_nao_revelam_projetos_privados(): void
    {
        $d = app(GestorDespesas::class)->criar($this->rui, ['data' => '2026-09-15', 'valor' => '10', 'projeto_id' => $this->privado->id,
            'categoria_id' => CategoriaDespesaTempo::firstOrFail()->id, 'nota' => 'Nota da despesa do Rui 7731']);

        Livewire::actingAs($this->ana)->test(Despesas::class)
            ->assertSee('Nota da despesa do Rui 7731')->assertSee('Projeto privado')->assertDontSee('Projeto Secreto')
            ->call('ver', $d->id)->assertSee('Projeto privado')->assertDontSee('Projeto Secreto');
        Livewire::actingAs($this->ana)->withQueryParams(['projetos' => [(string) $this->privado->id]])->test(Despesas::class)
            ->assertDontSee('Nota da despesa do Rui 7731');

        Livewire::actingAs($this->rui)->test(Despesas::class)->assertSee('Projeto Secreto');
    }

    public function test_recibo_trocado_ou_retirado_apaga_o_ficheiro_antigo(): void
    {
        // Lote B (notas §53): quem troca um recibo mandado por engano julga que o antigo desapareceu.
        $gestor = app(GestorDespesas::class);
        $disco = Storage::disk(DespesaTempo::DISCO);
        $pdf = fn (string $nome) => UploadedFile::fake()->createWithContent($nome, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        $dados = ['data' => '2026-09-15', 'valor' => '10', 'categoria_id' => CategoriaDespesaTempo::firstOrFail()->id];

        $d = $gestor->criar($this->ana, $dados, $pdf('engano.pdf'));
        $primeiro = $d->recibo_caminho;
        $this->assertTrue($disco->exists($primeiro));

        // Pendente: trocar apaga o antigo.
        $gestor->atualizar($this->ana, $d->fresh(), [], $pdf('certo.pdf'));
        $segundo = $d->fresh()->recibo_caminho;
        $this->assertFalse($disco->exists($primeiro));
        $this->assertTrue($disco->exists($segundo));

        // Rejeitada e corrigida com outro recibo: também.
        $gestor->decidir($this->admin, $d->fresh(), 'rejeitada', 'Ilegível');
        $gestor->atualizar($this->ana, $d->fresh(), [], $pdf('legivel.pdf'));
        $terceiro = $d->fresh()->recibo_caminho;
        $this->assertFalse($disco->exists($segundo));

        // Retirar o recibo: apaga-o.
        $gestor->atualizar($this->ana, $d->fresh(), [], null, retirarRecibo: true);
        $this->assertNull($d->fresh()->recibo_caminho);
        $this->assertFalse($disco->exists($terceiro));

        // Apagar a despesa guarda o recibo (pode voltar a ser precisa).
        $outra = $gestor->criar($this->ana, $dados, $pdf('guardar.pdf'));
        $gestor->apagar($this->ana, $outra->fresh());
        $this->assertTrue($disco->exists($outra->recibo_caminho));
    }

    // --- Relatórios de tempo ---

    public function test_etiquetas_do_filtro_sao_so_as_de_quem_ve(): void
    {
        $this->registo($this->rui, $this->cliente(), '2026-09-15', 3600, ['etiquetas' => ['etiqueta-do-rui']]);
        $this->registo($this->ana, $this->cliente(), '2026-09-15', 3600, ['etiquetas' => ['etiqueta-da-ana']]);

        Livewire::actingAs($this->ana)->test(Resumo::class)->assertSee('etiqueta-da-ana')->assertDontSee('etiqueta-do-rui');
        Livewire::actingAs($this->admin)->test(Resumo::class)->assertSee('etiqueta-da-ana')->assertSee('etiqueta-do-rui');
    }

    public function test_quem_nao_ve_valores_nao_ordena_por_eles(): void
    {
        Livewire::actingAs($this->ana)->withQueryParams(['ordem' => '-valor'])->test(Detalhado::class)
            ->assertSet('ordem', '-data')->call('ordenarPor', 'valor')->assertSet('ordem', '-data');
        Livewire::actingAs($this->ana)->withQueryParams(['ordem' => '-valor'])->test(Resumo::class)
            ->assertSet('ordem', '-duracao')->set('ordem', 'valor')->assertSet('ordem', '-duracao');
        Livewire::actingAs($this->ana)->withQueryParams(['ordem' => 'valor'])->test(ProjetosListagem::class)
            ->assertSet('ordem', 'nome')->call('ordenarPor', 'valor')->assertSet('ordem', 'nome');

        // Quem vê os valores continua a ordenar.
        Livewire::actingAs($this->admin)->withQueryParams(['ordem' => '-valor'])->test(Detalhado::class)->assertSet('ordem', '-valor');
        Livewire::actingAs($this->admin)->withQueryParams(['ordem' => 'valor'])->test(ProjetosListagem::class)->assertSet('ordem', 'valor');

        // Equipa: o filtro «com/sem taxa» é de quem gere (dizia quem tem taxa definida).
        app(GestorEquipa::class)->mudarTaxa($this->admin, MembroEquipa::where('utilizador_id', $this->rui->id)->sole(), 'custo', '30', '2026-01-01');
        Livewire::actingAs($this->ana)->withQueryParams(['custo' => 'com'])->test(Membros::class)->assertSee('Ana Martins');
        Livewire::actingAs($this->admin)->withQueryParams(['custo' => 'com'])->test(Membros::class)->assertDontSee('Ana Martins');
    }

    public function test_edicao_em_massa_nao_diz_nada_dos_registos_dos_outros(): void
    {
        $doRui = $this->registo($this->rui, $this->cliente('Hospital do Rui'), '2026-09-15', 3600);

        Livewire::actingAs($this->ana)->test(Detalhado::class)
            ->set('selecionados', [(string) $doRui->id])
            ->call('apagarMassa')
            ->assertSet('erro', 'Algum dos registos escolhidos não existe ou não é seu. Nada foi alterado.')
            ->assertDontSee('Hospital do Rui');
        $this->assertNotNull(RegistoTempo::find($doRui->id));
    }

    // --- Páginas abertas: cada pedido volta a verificar ---

    public function test_pagina_de_projeto_aberta_deixa_de_mostrar_quando_se_sai_do_projeto(): void
    {
        $this->actingAs($this->rui);
        $snapshot = $this->snapshot($this->get(route('projetos.ver', $this->privado))->assertOk()->getContent());

        app(GestorProjetos::class)->atualizar($this->admin, $this->privado, ['membros' => [], 'nota' => 'Segredo depois de sair 92371']);
        $this->actingAs($this->rui->fresh());

        $resposta = $this->atualizar($snapshot);
        $resposta->assertNotFound();
        $this->assertStringNotContainsString('Segredo depois de sair 92371', (string) $resposta->getContent());
    }

    public function test_painel_da_equipa_aberto_deixa_de_mostrar_a_quem_deixou_de_ser_admin(): void
    {
        $this->actingAs($this->admin);
        $snapshot = $this->snapshot($this->get(route('painel', ['quem' => 'equipa']))->assertOk()->getContent());

        DB::table('acessos')->where('utilizador_id', $this->admin->id)->update(['papel' => 'tecnico']);
        $this->registo($this->rui, $this->cliente(), '2026-09-16', 3600, ['descricao' => 'Atividade depois da despromoção 52813']);
        $this->actingAs($this->admin->fresh());

        $resposta = $this->atualizar($snapshot)->assertOk();
        $this->assertStringNotContainsString('Atividade depois da despromoção 52813', (string) $resposta->json('components.0.effects.html'));
    }

    public function test_sem_acesso_nenhuma_acao_passa_mesmo_sem_o_middleware(): void
    {
        // O Livewire::test() não passa pelos middleware HTTP: prova a segunda linha (o gancho do Livewire).
        $pagina = Livewire::actingAs($this->ana)->test(Cronometro::class);
        DB::table('acessos')->where('utilizador_id', $this->ana->id)->delete();
        $this->actingAs($this->ana->fresh());

        $pagina->call('comecar')->assertForbidden();
        $this->assertSame(0, RegistoTempo::count());
    }

    // --- Exportações ---

    public function test_exportacoes_tem_limite_por_minuto(): void
    {
        $pagina = Livewire::actingAs($this->ana)->test(Detalhado::class);
        for ($i = 0; $i < 20; $i++) {
            $pagina->call('exportar')->assertFileDownloaded('detalhado-20260914-20260920.csv');
        }
        $pagina->call('exportar')->assertStatus(429);
    }

    public function test_zip_dos_recibos_vai_por_um_link_assinado_e_com_teto(): void
    {
        $recibo = UploadedFile::fake()->createWithContent('fatura.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        app(GestorDespesas::class)->criar($this->ana, ['data' => '2026-09-15', 'valor' => '10', 'categoria_id' => CategoriaDespesaTempo::firstOrFail()->id], $recibo);

        // A ação não devolve o ficheiro (ia em base64 dentro da resposta): manda para um link assinado.
        $url = Livewire::actingAs($this->admin)->test(Despesas::class)->call('descarregarRecibos')->effects['redirect'];
        $this->assertStringContainsString('signature=', $url);

        $this->actingAs($this->ana)->get($url)->assertNotFound();                               // o ZIP é de quem o pediu
        $this->actingAs($this->admin)->get(preg_replace('/&?signature=[^&]+/', '', $url))->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk()->assertDownload('recibos-20260914-20260920.zip');

        // Teto de tamanho.
        config(['tempos.zip_recibos_max_mb' => 0]);
        Livewire::actingAs($this->admin)->test(Despesas::class)->call('descarregarRecibos')
            ->assertSet('erro', 'Os recibos destas despesas passam de 0 MB: escolha um período mais curto ou filtre.');
    }

    // --- Operação ---

    public function test_cabecalhos_de_seguranca(): void
    {
        $this->actingAs($this->ana)->get(route('cronometro'))->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_dados_de_demonstracao_pedem_confirmacao_em_producao(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('tempos:demo')->expectsOutputToContain('--em-producao')->assertExitCode(1);
        $this->artisan('tempos:demo --apagar')->expectsOutputToContain('--em-producao')->assertExitCode(1);
        $this->assertSame(0, RegistoTempo::count());
    }

    public function test_portal_sql_nao_devolve_acessos_retirados(): void
    {
        $tempos = DB::table('aplicacoes')->where('chave', 'tempos')->value('id');
        $infra = DB::table('aplicacoes')->insertGetId(['chave' => 'nexus-infra', 'nome' => 'Nexus Infra', 'url' => 'https://exemplo.test', 'activa' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('acessos')->insert(['utilizador_id' => $this->ana->id, 'aplicacao_id' => $infra, 'papel' => 'admin', 'created_at' => now(), 'updated_at' => now()]);

        // Tiraram o acesso à Ana no portal; alguém volta a correr o instalador.
        DB::table('acessos')->where('utilizador_id', $this->ana->id)->where('aplicacao_id', $tempos)->delete();
        DB::unprepared(file_get_contents(base_path('deploy/portal.sql')));
        $this->assertFalse($this->ana->fresh()->temAcesso());

        // Numa instalação de raiz (sem a aplicação no portal), dá os acessos a partir da Nexus Infra.
        DB::table('acessos')->where('aplicacao_id', $tempos)->delete();
        DB::table('aplicacoes')->where('id', $tempos)->delete();
        DB::unprepared(file_get_contents(base_path('deploy/portal.sql')));
        $this->assertTrue($this->ana->fresh()->temAcesso());
    }

    public function test_configuracao_endurecida(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'), 'o disco privado não se serve por URL');
        $this->assertSame('throttle:10,1', config('livewire.temporary_file_upload.middleware'));
        $this->assertSame(['required', 'file', 'max:10240'], config('livewire.temporary_file_upload.rules'));
    }

    // --- auxiliares ---

    private function snapshot(string $html): string
    {
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    private function atualizar(string $snapshot, array $updates = [])
    {
        return $this->withHeaders(['X-Livewire' => '1'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => (object) $updates, 'calls' => []]],
        ]);
    }
}

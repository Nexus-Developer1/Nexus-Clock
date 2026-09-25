<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Despesas;
use App\Models\Auditoria;
use App\Models\CategoriaDespesaTempo;
use App\Models\ClienteTempo;
use App\Models\DespesaTempo;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Tempos\GestorDespesas;
use App\Services\Tempos\RelatorioDespesas;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

// Despesas dos Tempos: cada um lança as suas (com recibo no disco privado); quem gere lança para
// outros, aprova e rejeita (com motivo) e gere as categorias; o relatório filtra, soma e junta recibos.
class RelatorioDespesasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private ProjetoTempo $obra;

    private CategoriaDespesaTempo $refeicoes;

    private CategoriaDespesaTempo $combustiveis;

    private GestorDespesas $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14/09–20/09
        Storage::fake(DespesaTempo::DISCO);

        $this->admin = $this->admin();
        $this->admin->update(['nome' => 'Suporte Nexus']);
        // Nestes testes, o admin é também quem aprova (o papel do Paulo Gouveia em produção, notas §44).
        config(['tempos.aprovam_despesas' => [strtolower($this->admin->email)]]);
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        $cliente = ClienteTempo::create(['nome' => 'Hospital']);
        $this->obra = ProjetoTempo::create(['nome' => 'Obra', 'cliente_id' => $cliente->id]);
        $this->refeicoes = CategoriaDespesaTempo::where('nome', 'Refeições')->sole();
        $this->combustiveis = CategoriaDespesaTempo::where('nome', 'Combustíveis')->sole();
        $this->gestor = app(GestorDespesas::class);
    }

    /** Recibos com conteúdo a sério: o serviço lê os bytes, não o nome nem o tipo declarado (notas §45). */
    private function pdf(string $nome = 'fatura.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }

    private function jpg(string $nome = 'talão.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
    }

    private function despesa(User $autor, array $dados, ?UploadedFile $recibo = null): DespesaTempo
    {
        return $this->gestor->criar($autor, $dados + ['data' => '2026-09-15', 'categoria_id' => $this->refeicoes->id, 'valor' => '10'], $recibo);
    }

    private function semana(): array
    {
        return [CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-20')];
    }

    public function test_categorias_iniciais_existem(): void
    {
        $this->assertSame(7, CategoriaDespesaTempo::ativas()->count());
    }

    public function test_criar_valida_e_guarda_recibo_no_disco_privado(): void
    {
        try {
            $this->gestor->criar($this->ana, ['data' => '2026-09-30', 'categoria_id' => '', 'valor' => '0', 'projeto_id' => 999]);
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame(['data', 'projeto_id', 'categoria_id', 'valor'], array_keys($e->errors()));
        }

        try {
            $this->despesa($this->ana, [], UploadedFile::fake()->create('virus.exe', 10));
            $this->fail('Devia recusar o tipo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recibo', $e->errors());
        }

        $d = $this->despesa($this->ana, ['valor' => '12,50', 'faturavel' => true, 'nota' => ' Almoço na obra ', 'projeto_id' => $this->obra->id],
            $this->pdf());

        $this->assertSame([$this->ana->id, 1250, true, 'Almoço na obra', 'pendente', 'fatura.pdf'],
            [$d->utilizador_id, $d->valor_cent, $d->faturavel, $d->nota, $d->estado, $d->recibo_nome]);
        $this->assertStringStartsWith(DespesaTempo::PASTA_RECIBOS.'/', $d->recibo_caminho);
        Storage::disk(DespesaTempo::DISCO)->assertExists($d->recibo_caminho);
        $this->assertSame(1, Auditoria::where('acao', 'tempo_despesa_criada')->count());
    }

    public function test_tecnico_nao_lanca_para_outros_nem_altera_as_dos_outros(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->despesa($this->ana, ['utilizador_id' => $this->rui->id]);
    }

    public function test_regras_de_alteracao_aprovacao_e_rejeicao(): void
    {
        $d = $this->despesa($this->ana, []);
        $doRui = $this->despesa($this->admin, ['utilizador_id' => $this->rui->id]);
        $this->assertSame($this->rui->id, $doRui->utilizador_id);

        try {
            $this->gestor->atualizar($this->ana, $doRui, ['valor' => '5']);
            $this->fail('Não é dela.');
        } catch (AuthorizationException) {
        }

        try {
            $this->gestor->decidir($this->ana, $d, 'aprovada');
            $this->fail('Técnico não aprova.');
        } catch (AuthorizationException) {
        }

        try {
            $this->gestor->decidir($this->admin, $d, 'rejeitada', '  ');
            $this->fail('Rejeitar exige motivo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('motivo', $e->errors());
        }

        // Rejeitada → a dona corrige → volta a pendente.
        $this->gestor->decidir($this->admin, $d, 'rejeitada', 'Falta o recibo');
        $this->assertSame(['rejeitada', 'Falta o recibo', $this->admin->id], [$d->estado, $d->motivo_rejeicao, $d->decidido_por]);
        $this->gestor->atualizar($this->ana, $d, ['valor' => '11']);
        $this->assertSame(['pendente', null, null], [$d->fresh()->estado, $d->fresh()->motivo_rejeicao, $d->fresh()->decidido_por]);

        // Aprovada → fechada para TODA a gente, incluindo quem gere e quem aprova (notas §44): para a
        // corrigir, quem aprova volta-a a pendente primeiro.
        $this->gestor->decidir($this->admin, $d, 'aprovada');
        $this->assertFalse($this->gestor->podeAlterar($this->ana, $d));
        $this->assertFalse($this->gestor->podeAlterar($this->admin, $d));
        try {
            $this->gestor->apagar($this->ana, $d);
            $this->fail('Aprovada não se apaga.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('aprovada', $e->getMessage());
        }
        try {
            $this->gestor->atualizar($this->admin, $d, ['nota' => 'Visto']);
            $this->fail('Aprovada está fechada também para quem gere.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('voltar a pôr pendente', $e->getMessage());
        }
        $this->assertSame('aprovada', $d->fresh()->estado);

        $this->gestor->decidir($this->admin, $d, 'pendente');
        $this->assertNull($d->fresh()->decidido_em);
        $this->gestor->apagar($this->ana, $d);
        $this->assertSoftDeleted($d);

        $this->assertSame(
            ['tempo_despesa_alterada' => 1, 'tempo_despesa_apagada' => 1, 'tempo_despesa_aprovada' => 1, 'tempo_despesa_reaberta' => 1, 'tempo_despesa_rejeitada' => 1],
            Auditoria::where('acao', 'like', 'tempo_despesa_%')->where('acao', '!=', 'tempo_despesa_criada')
                ->selectRaw('acao, count(*) as n')->groupBy('acao')->orderBy('acao')->pluck('n', 'acao')->map(fn ($n) => (int) $n)->all()
        );
    }

    public function test_categorias_so_quem_gere_sem_repetidas_e_arquivadas_nao_servem_para_novas(): void
    {
        try {
            $this->gestor->criarCategoria($this->admin, 'refeições');
            $this->fail('Repetida.');
        } catch (ValidationException) {
        }

        $c = $this->gestor->criarCategoria($this->admin, '  Formação   externa ');
        $this->assertSame('Formação externa', $c->nome);

        $d = $this->despesa($this->ana, ['categoria_id' => $c->id]);
        $this->gestor->alternarCategoria($this->admin, $c);
        $this->assertNotNull($c->fresh()->arquivada_em);

        // Quem já a tinha pode continuar; novas não.
        $this->gestor->atualizar($this->ana, $d, ['categoria_id' => $c->id, 'valor' => '20']);
        try {
            $this->despesa($this->ana, ['categoria_id' => $c->id]);
            $this->fail('Categoria arquivada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('categoria_id', $e->errors());
        }

        $this->expectException(AuthorizationException::class);
        $this->gestor->criarCategoria($this->ana, 'Outra');
    }

    public function test_relatorio_filtra_soma_e_junta_recibos(): void
    {
        $this->despesa($this->ana, ['valor' => '12,50', 'faturavel' => true, 'projeto_id' => $this->obra->id, 'nota' => 'Almoço'],
            $this->jpg());
        $this->despesa($this->ana, ['valor' => '40', 'categoria_id' => $this->combustiveis->id, 'nota' => 'Gasóleo']);
        $r = $this->despesa($this->admin, ['utilizador_id' => $this->rui->id, 'valor' => '7,30', 'data' => '2026-09-16']);
        $this->gestor->decidir($this->admin, $r, 'aprovada');
        $this->despesa($this->ana, ['data' => '2026-09-10', 'valor' => '99']); // fora do período

        $servico = app(RelatorioDespesas::class);
        [$de, $ate] = $this->semana();

        $this->assertSame(['total' => 5980, 'faturavel' => 1250, 'registos' => 3, 'pendentes' => 2, 'recibos' => 1], $servico->totais([], $de, $ate));
        $this->assertSame(5250, $servico->totais(['membros' => [$this->ana->id]], $de, $ate)['total']);
        $this->assertSame(1250, $servico->totais(['clientes' => [$this->obra->cliente_id]], $de, $ate)['total']);
        $this->assertSame(4730, $servico->totais(['projetos' => [0]], $de, $ate)['total']);
        $this->assertSame(4000, $servico->totais(['categorias' => [$this->combustiveis->id]], $de, $ate)['total']);
        $this->assertSame(730, $servico->totais(['estado' => 'aprovada'], $de, $ate)['total']);
        $this->assertSame(4000, $servico->totais(['nota' => 'gasó'], $de, $ate)['total']);

        $this->assertSame([4000, 1250, 730], $servico->consulta([], $de, $ate, '-valor')->pluck('valor_cent')->all());
        $this->assertSame(['Ana Martins', 'Ana Martins', 'Rui Costa'], $servico->consulta([], $de, $ate, 'membro')->get()->pluck('utilizador.nome')->all());

        $zip = $servico->zipRecibos($servico->consulta([], $de, $ate)->get());
        $arquivo = new ZipArchive;
        $arquivo->open($zip);
        $this->assertSame(1, $arquivo->numFiles);
        $this->assertMatchesRegularExpression('/^2026-09-15_Ana-Martins_12,50_\d+_talão\.jpg$/u', $arquivo->getNameIndex(0));
        $arquivo->close();
        @unlink($zip);

        $this->assertNull($servico->zipRecibos($servico->consulta(['membros' => [$this->rui->id]], $de, $ate)->get()));
    }

    public function test_pagina_admin_lanca_aprova_rejeita_e_exporta(): void
    {
        $pagina = Livewire::actingAs($this->admin)->test(Despesas::class)
            ->assertSee('Esta semana')
            ->assertSee('Sem despesas')
            ->call('nova')
            ->assertSet('formulario.data', '2026-09-17')
            ->call('guardar')
            ->assertHasErrors(['formulario.categoria_id', 'formulario.valor'])
            ->set('formulario.utilizador_id', (string) $this->ana->id)
            ->set('formulario.categoria_id', (string) $this->refeicoes->id)
            ->set('formulario.valor', '8,40')
            ->set('formulario.nota', 'Jantar')
            ->set('recibo', $this->pdf())
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSet('editarId', null)
            ->assertSee('Despesa acrescentada.')
            ->assertSee('Ana Martins')
            ->assertSee('Jantar')
            ->assertSee('Descarregar recibos (1)');

        $d = DespesaTempo::sole();
        $this->assertSame([$this->ana->id, 840, 'fatura.pdf'], [$d->utilizador_id, $d->valor_cent, $d->recibo_nome]);

        $pagina->call('aprovar', $d->id)
            ->assertSee('Despesa aprovada.')
            ->call('reabrir', $d->id)
            ->call('pedirRejeicao', $d->id)
            ->call('rejeitar')
            ->assertHasErrors('motivo')
            ->set('motivo', 'Sem NIF')
            ->call('rejeitar')
            ->assertSet('rejeitarId', null)
            ->assertSee('Rejeitada: Sem NIF')
            ->call('editar', $d->id)
            ->assertSet('formulario.valor', '8,40')
            ->set('retirarRecibo', true)
            ->call('guardar')
            ->assertSee('Despesa guardada.')
            ->set('categoriasAbertas', true)
            ->set('novaCategoria', 'Formação')
            ->call('acrescentarCategoria')
            ->assertHasNoErrors()
            ->assertSet('novaCategoria', '')
            ->call('ordenarPor', 'valor')
            ->assertSet('ordem', '-valor')
            ->call('exportar')
            ->assertFileDownloaded('despesas-20260914-20260920.csv')
            ->call('exportar', 'pdf')
            ->assertFileDownloaded('despesas-20260914-20260920.pdf')
            ->call('descarregarRecibos')
            ->assertSet('erro', 'Não há recibos nas despesas mostradas.');

        $this->assertSame(['rejeitada', null], [$d->fresh()->estado, $d->fresh()->recibo_caminho]);
        $this->assertTrue(CategoriaDespesaTempo::where('nome', 'Formação')->exists());
    }

    public function test_pagina_descarrega_zip_dos_recibos(): void
    {
        $this->despesa($this->ana, [], $this->pdf());

        // Vai por um link assinado (notas §52), que descarrega o ZIP.
        $url = Livewire::actingAs($this->admin)->test(Despesas::class)->call('descarregarRecibos')->effects['redirect'];
        $this->actingAs($this->admin)->get($url)->assertOk()->assertDownload('recibos-20260914-20260920.zip');
    }

    public function test_tecnico_ve_as_de_todos_mas_nao_decide_nem_altera_as_dos_outros(): void
    {
        $minha = $this->despesa($this->ana, ['nota' => 'Minha']);
        $doRui = $this->despesa($this->rui, ['nota' => 'Do Rui']);

        $this->actingAs($this->ana)->get('/relatorios/despesas')->assertOk()->assertSee('Despesas — Nexus Suporte', false);

        // A pedido (notas §41): o técnico vê as despesas de toda a equipa, com a coluna Membro e o filtro Equipa.
        Livewire::actingAs($this->ana)->test(Despesas::class)
            ->assertSee('Minha')->assertSee('Do Rui')->assertSee('Rui Costa')
            ->assertSee('for="filtro-membros"', false)
            ->assertDontSee('Categorias de despesa');

        // O filtro Equipa funciona para ele.
        Livewire::actingAs($this->ana)->withQueryParams(['membros' => [(string) $this->rui->id]])->test(Despesas::class)
            ->assertSee('Do Rui')->assertDontSee('Minha');

        // Ver não é mexer: não decide, nem abre o formulário, nem apaga as dos outros.
        Livewire::actingAs($this->ana)->test(Despesas::class)
            ->call('aprovar', $minha->id)
            ->assertSet('erro', 'Só quem aprova as despesas pode fazer isto.')
            ->call('editar', $doRui->id)
            ->assertForbidden();
        Livewire::actingAs($this->ana)->test(Despesas::class)->call('apagar', $doRui->id)->assertSet('erro', fn ($e) => $e !== null);

        $this->assertSame('pendente', $minha->fresh()->estado);
        $this->assertNotNull($doRui->fresh());
    }

    public function test_recibo_para_quem_tem_acesso_ao_suporte(): void
    {
        $d = $this->despesa($this->ana, [], UploadedFile::fake()->createWithContent('fatura.pdf', '%PDF-1.4'));
        $sem = $this->despesa($this->ana, []);

        $this->actingAs($this->ana)->get(route('despesas.recibo', $d))->assertOk()->assertDownload('fatura.pdf');
        $this->actingAs($this->admin)->get(route('despesas.recibo', $d))->assertOk();
        // Os colegas veem as despesas da equipa, e com elas os recibos (notas §41).
        $this->actingAs($this->rui)->get(route('despesas.recibo', $d))->assertOk();
        // Quem não tem o Suporte atribuído no portal não chega lá.
        $this->actingAs($this->utilizador(null))->get(route('despesas.recibo', $d))->assertRedirect(config('app.portal_url'));
        $this->actingAs($this->ana)->get(route('despesas.recibo', $sem))->assertNotFound();
        auth()->logout();
        $this->get(route('despesas.recibo', $d))->assertRedirect();
    }

    public function test_detalhe_da_despesa_so_de_leitura_ao_carregar_na_linha(): void
    {
        $d = $this->despesa($this->ana, ['projeto_id' => $this->obra->id, 'valor' => '23,50', 'faturavel' => true, 'nota' => "Portagens A28\nida e volta"],
            $this->pdf('portagem.pdf'));

        // A dona vê tudo, sem abrir o formulário de alterar.
        Livewire::actingAs($this->ana)->test(Despesas::class)
            ->assertSee('wire:click="ver('.$d->id.')"', false)
            ->call('ver', $d->id)
            ->assertSet('verId', $d->id)
            ->assertSet('editarId', null)
            ->assertSee('Despesa de 15/09/2026')
            // (depois de um call o assertSeeInOrder lê a resposta em JSON, com € e quebras escapados)
            ->assertSee('23,50 €')->assertSee('Faturável ao cliente')->assertSee('Pendente')->assertSee('Obra')->assertSee('Hospital')
            ->assertSee('Refeições')->assertSee('Portagens A28')->assertSee('ida e volta')->assertSee('portagem.pdf')
            ->assertSee('Lançada por Ana Martins')
            ->assertSee('Alterar')->assertDontSee('>Aprovar</button>', false)
            ->call('fecharDetalhe')->assertSet('verId', null)->assertDontSee('Despesa de 15/09/2026');

        // Quem gere decide a partir do detalhe; o detalhe fica aberto e mostra a decisão.
        Livewire::actingAs($this->admin)->test(Despesas::class)
            ->call('ver', $d->id)
            ->assertSee('Aprovar')
            ->call('aprovar', $d->id)
            ->assertSet('verId', $d->id)
            ->assertSee('Aprovada por Suporte Nexus');

        // Rejeitada: o motivo aparece em destaque.
        $this->gestor->decidir($this->admin, $d, 'rejeitada', 'Falta o talão.');
        Livewire::actingAs($this->ana)->test(Despesas::class)->call('ver', $d->id)
            ->assertSee('Motivo da rejeição')->assertSee('Falta o talão.');
    }

    public function test_detalhe_da_despesa_de_um_colega_e_so_de_leitura(): void
    {
        $daAna = $this->despesa($this->ana, ['nota' => 'Nota da Ana']);

        // O colega vê o detalhe (notas §41), mas sem nenhuma ação: nem alterar, nem apagar, nem decidir.
        Livewire::actingAs($this->rui)->test(Despesas::class)
            ->call('ver', $daAna->id)
            ->assertSee('Despesa de 15/09/2026')->assertSee('Nota da Ana')
            ->assertDontSee('wire:click="editar('.$daAna->id.')"', false)
            ->assertDontSee('wire:click="apagar('.$daAna->id.')"', false)
            ->assertDontSee('wire:click="aprovar('.$daAna->id.')"', false);

        // Com o acesso a ver despesas fechado outra vez, o id mexido pelo browser não mostra nada.
        Gate::define('tempos-ver-despesas', fn () => false);
        Livewire::actingAs($this->rui)->test(Despesas::class)->call('ver', $daAna->id)->assertForbidden();
        Livewire::actingAs($this->rui)->test(Despesas::class)
            ->set('verId', $daAna->id)
            ->assertSet('verId', null)
            ->assertDontSee('Nota da Ana')->assertDontSee('Despesa de 15/09/2026');
        Gate::define('tempos-ver-despesas', fn () => true);

        // Apagada entretanto: o detalhe fecha-se sozinho.
        $pagina = Livewire::actingAs($this->ana)->test(Despesas::class)->call('ver', $daAna->id)->assertSee('Nota da Ana');
        $this->gestor->apagar($this->ana, $daAna);
        $pagina->call('$refresh')->assertSet('verId', null)->assertDontSee('Despesa de 15/09/2026');
    }
}

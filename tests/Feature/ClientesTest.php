<?php

namespace Tests\Feature;

use App\Livewire\Clientes\Detalhe;
use App\Livewire\Clientes\Listagem;
use App\Livewire\Clientes\Novo;
use App\Models\Auditoria;
use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\GestorClientes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Página Clientes (lista própria dos Tempos): criar na página «Novo cliente» (nome, email, emails em
// cópia, morada, nota, moeda), alterar na janela da listagem, arquivar, restaurar e apagar só
// arquivados. Desde 2026-09-22 gere quem quiser, admin ou técnico (notas §33). Cada nome abre a
// página do cliente (dados, totais e projetos — só os que quem vê pode ver; notas §35).
class ClientesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private GestorClientes $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->admin();
        $this->gestor = app(GestorClientes::class);
    }

    public function test_criar_exige_nome_unico_sem_distinguir_maiusculas_e_audita(): void
    {
        $cliente = $this->gestor->criar($this->admin, ['nome' => '  Hospital   Exemplo ']);

        $this->assertSame(['Hospital Exemplo', 'EUR', []], [$cliente->nome, $cliente->moeda, $cliente->emails_cc]);
        $this->assertSame(['nome' => 'Hospital Exemplo'], Auditoria::where('acao', 'tempo_cliente_criado')->sole()->detalhe);

        foreach (['' => 'Indique o nome do cliente.', 'HOSPITAL exemplo' => 'Já existe um cliente chamado «HOSPITAL exemplo».'] as $nome => $mensagem) {
            try {
                $this->gestor->criar($this->admin, ['nome' => $nome]);
                $this->fail('Devia recusar «'.$nome.'».');
            } catch (ValidationException $e) {
                $this->assertSame($mensagem, $e->errors()['nome'][0]);
            }
        }
        $this->assertSame(1, ClienteTempo::count());
    }

    public function test_alterar_valida_email_emails_em_copia_e_moeda(): void
    {
        $cliente = $this->gestor->criar($this->admin, ['nome' => 'Banco Exemplo']);

        $casos = [
            [['email' => 'isto-nao'], 'email', 'Email inválido.'],
            [['emails_cc' => 'a@x.pt, b@x.pt, c@x.pt, d@x.pt'], 'emails_cc', 'No máximo 3 emails em cópia.'],
            [['emails_cc' => 'a@x.pt; errado'], 'emails_cc', 'Email inválido: errado.'],
            [['moeda' => 'XYZ'], 'moeda', 'Escolha uma moeda da lista.'],
        ];
        foreach ($casos as [$dados, $campo, $mensagem]) {
            try {
                $this->gestor->atualizar($this->admin, $cliente->fresh(), $dados);
                $this->fail('Devia recusar '.$campo);
            } catch (ValidationException $e) {
                $this->assertSame($mensagem, $e->errors()[$campo][0]);
            }
        }

        $this->gestor->atualizar($this->admin, $cliente->fresh(), [
            'nome' => 'Banco Exemplo SA', 'email' => 'geral@banco.pt', 'emails_cc' => 'a@banco.pt, b@banco.pt',
            'morada' => "Rua A, 1\n1000-001 Lisboa", 'nota' => '  ', 'moeda' => 'USD',
        ]);

        $cliente->refresh();
        $this->assertSame(['Banco Exemplo SA', 'geral@banco.pt', ['a@banco.pt', 'b@banco.pt'], "Rua A, 1\n1000-001 Lisboa", null, 'USD'],
            [$cliente->nome, $cliente->email, $cliente->emails_cc, $cliente->morada, $cliente->nota, $cliente->moeda]);
    }

    public function test_arquivar_restaurar_e_so_apaga_arquivados_tudo_ou_nada(): void
    {
        $a = $this->gestor->criar($this->admin, ['nome' => 'A']);
        $b = $this->gestor->criar($this->admin, ['nome' => 'B']);

        $this->assertSame(1, $this->gestor->arquivar($this->admin, [$a->id]));
        $this->assertTrue($a->fresh()->estaArquivado());
        $this->assertSame(['B'], ClienteTempo::ativos()->pluck('nome')->all());

        try {
            $this->gestor->apagar($this->admin, [$a->id, $b->id]);
            $this->fail('B não está arquivado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('arquive primeiro «B»', $e->errors()['clientes'][0]);
        }
        $this->assertNotSoftDeleted($a);

        $this->gestor->apagar($this->admin, [$a->id]);
        $this->assertSoftDeleted($a);

        // Com A apagado, o nome volta a estar livre.
        $this->gestor->criar($this->admin, ['nome' => 'a']);

        $this->gestor->arquivar($this->admin, [$b->id]);
        $this->gestor->restaurar($this->admin, [$b->id]);
        $this->assertFalse($b->fresh()->estaArquivado());
    }

    public function test_tecnico_tambem_gere_clientes(): void
    {
        $tecnico = $this->tecnico();
        $this->gestor->criar($this->admin, ['nome' => 'Hospital Exemplo']);

        // A pedido (2026-09-22): a página Clientes dá ao técnico as mesmas ações que ao admin.
        $this->actingAs($tecnico)->get(route('clientes'))->assertOk()
            ->assertSee('Hospital Exemplo')->assertSee('Novo cliente')->assertSee('Mais opções');

        $this->actingAs($tecnico)->get(route('clientes.novo'))->assertOk();

        $cliente = $this->gestor->criar($tecnico, ['nome' => 'Clínica do Técnico']);
        $this->assertSame($tecnico->id, $cliente->criado_por);
    }


    public function test_pagina_do_cliente_mostra_dados_projetos_e_horas_de_quem_ve(): void
    {
        $tecnico = $this->tecnico();
        $cliente = $this->gestor->criar($this->admin, [
            'nome' => 'Hospital Exemplo', 'email' => 'geral@hospital.pt', 'emails_cc' => 'a@hospital.pt, b@hospital.pt',
            'morada' => "Av. Lusíada 100\n1500-650 Lisboa", 'nota' => 'Só de manhã.', 'moeda' => 'EUR',
        ]);
        $publico = ProjetoTempo::create(['nome' => 'Rede Wi-Fi', 'cliente_id' => $cliente->id]);
        $privado = ProjetoTempo::create(['nome' => 'Auditoria', 'cliente_id' => $cliente->id, 'publico' => false]);
        $arquivado = ProjetoTempo::create(['nome' => 'Migração antiga', 'cliente_id' => $cliente->id, 'arquivado_em' => now()]);
        $infra = $this->cliente('Infra');
        $this->registo($tecnico, $infra, '2026-09-14', 3600, ['projeto_id' => $publico->id, 'faturavel' => true]);
        $this->registo($tecnico, $infra, '2026-09-14', 1800, ['projeto_id' => $publico->id, 'faturavel' => false]);
        $this->registo($this->admin, $infra, '2026-09-15', 7200, ['projeto_id' => $privado->id, 'faturavel' => true]);
        // Cronómetro a correr: sem duração, não conta.
        RegistoTempo::create(['tecnico_id' => $tecnico->id, 'cliente_id' => $infra->id, 'projeto_id' => $publico->id, 'inicio' => '2026-09-17 08:00:00+00']);

        // Na listagem o nome é um link para a página.
        $this->actingAs($tecnico)->get(route('clientes'))->assertOk()->assertSee(route('clientes.ver', $cliente));

        // O técnico não é membro do projeto privado: não o vê e as horas dele ficam de fora.
        $this->actingAs($tecnico)->get(route('clientes.ver', $cliente))->assertOk()
            ->assertSee('Hospital Exemplo — Nexus Suporte', false)
            ->assertSeeInOrder(['geral@hospital.pt', 'a@hospital.pt', 'b@hospital.pt', 'Av. Lusíada 100', 'Só de manhã.'])
            ->assertSee('Rede Wi-Fi')->assertSee('Migração antiga')->assertDontSee('Auditoria')
            ->assertSee('1:30:00')->assertSee('1:00:00')->assertSee('67% do total');

        // O admin vê tudo.
        $this->actingAs($this->admin)->get(route('clientes.ver', $cliente))->assertOk()
            ->assertSee('Auditoria')->assertSee('3:30:00')->assertSee('3:00:00');

        // Alterar na própria página.
        Livewire::actingAs($this->admin)->test(Detalhe::class, ['cliente' => $cliente])
            ->call('abrirFormulario')->assertSet('formulario.nome', 'Hospital Exemplo')
            ->set('formulario.nome', 'Hospital Novo')->call('guardar')
            ->assertSet('editar', false)->assertSet('cliente.nome', 'Hospital Novo');
        $this->assertSame('Hospital Novo', $cliente->fresh()->nome);

        // Um cliente arquivado abre; um apagado dá 404.
        $this->gestor->arquivar($this->admin, [$cliente->id]);
        $this->actingAs($this->admin)->get(route('clientes.ver', $cliente))->assertOk()->assertSee('Arquivado');
        $this->gestor->apagar($this->admin, [$cliente->id]);
        $this->actingAs($this->admin)->get(route('clientes.ver', $cliente))->assertNotFound();
    }

    public function test_pagina_de_criar_grava_todos_os_campos_e_volta_a_listagem(): void
    {
        $this->actingAs($this->admin)->get(route('clientes.novo'))->assertOk()->assertSee('Novo cliente — Nexus Suporte', false);

        Livewire::actingAs($this->admin)->test(Novo::class)
            // Sem nome não grava.
            ->call('guardar')
            ->assertHasErrors('formulario.nome')
            // Os outros campos são validados como na janela de alterar.
            ->set('formulario.nome', 'Hospital Exemplo')
            ->set('formulario.email', 'isto-nao')
            ->call('guardar')
            ->assertHasErrors('formulario.email')
            ->set('formulario.email', 'geral@hospital.pt')
            ->set('formulario.emails_cc', 'a@hospital.pt, b@hospital.pt')
            ->set('formulario.morada', "Rua A, 1\n1000-001 Lisboa")
            ->set('formulario.nota', 'Contacto: Ana')
            ->set('formulario.moeda', 'GBP')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('clientes'));

        $cliente = ClienteTempo::sole();
        $this->assertSame(
            ['Hospital Exemplo', 'geral@hospital.pt', ['a@hospital.pt', 'b@hospital.pt'], "Rua A, 1\n1000-001 Lisboa", 'Contacto: Ana', 'GBP'],
            [$cliente->nome, $cliente->email, $cliente->emails_cc, $cliente->morada, $cliente->nota, $cliente->moeda]
        );
        $this->assertSame(['nome' => 'Hospital Exemplo'], Auditoria::where('acao', 'tempo_cliente_criado')->sole()->detalhe);

        // O nome repetido é recusado na página (não fica um segundo cliente).
        Livewire::actingAs($this->admin)->test(Novo::class)
            ->set('formulario.nome', 'hospital exemplo')
            ->call('guardar')
            ->assertHasErrors('formulario.nome')
            ->assertNoRedirect();

        $this->assertSame(1, ClienteTempo::count());

        // A listagem mostra-o e o botão leva à página de criar.
        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->assertSee('Hospital Exemplo')
            ->assertSeeHtml(route('clientes.novo'));
    }

    public function test_pagina_filtra_pesquisa_altera_e_arquiva_varios(): void
    {
        $hospital = $this->gestor->criar($this->admin, ['nome' => 'Hospital Exemplo']);
        $banco = $this->gestor->criar($this->admin, ['nome' => 'Banco Exemplo']);

        $pagina = Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('pesquisa', 'banc')->assertSee('Banco Exemplo')->assertDontSee('Hospital Exemplo')
            ->set('pesquisa', '')
            ->call('editar', $hospital->id)
            ->assertSee('Alterar cliente')
            ->assertSet('formulario.nome', 'Hospital Exemplo')
            ->set('formulario.email', 'mau')->call('guardar')
            ->assertHasErrors('formulario.email')
            ->set('formulario.email', 'geral@hospital.pt')->set('formulario.moeda', 'GBP')->call('guardar')
            ->assertSee('Cliente guardado.')
            ->assertSet('editarId', null)
            ->set('selecionados', [(string) $hospital->id, (string) $banco->id])
            ->call('arquivar')
            ->assertSee('2 clientes arquivados.')
            ->assertSee('Ainda sem clientes')
            ->set('mostrar', 'arquivados')
            ->assertSee('Banco Exemplo')->assertSee('Arquivado')
            ->call('apagar', $banco->id)
            ->assertSee('Cliente apagado.');

        $this->assertSame(['geral@hospital.pt', 'GBP'], [$hospital->fresh()->email, $hospital->fresh()->moeda]);
        $this->assertSoftDeleted($banco);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Clientes\Listagem;
use App\Livewire\Clientes\Novo;
use App\Models\Auditoria;
use App\Models\ClienteTempo;
use App\Models\User;
use App\Services\Tempos\GestorClientes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Página Clientes (lista própria dos Tempos): criar na página «Novo cliente» (nome, email, emails em
// cópia, morada, nota, moeda), alterar na janela da listagem, arquivar, restaurar e apagar só
// arquivados. Só admin gere; técnico vê.
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

    public function test_tecnico_so_ve(): void
    {
        $tecnico = $this->tecnico();
        $this->gestor->criar($this->admin, ['nome' => 'Hospital Exemplo']);

        $this->actingAs($tecnico)->get(route('clientes'))->assertOk()
            ->assertSee('Hospital Exemplo')->assertDontSee('Novo cliente')->assertDontSee('Mais opções');

        // A página de criar está fechada aos técnicos.
        $this->actingAs($tecnico)->get(route('clientes.novo'))->assertForbidden();

        $this->expectException(AuthorizationException::class);
        $this->gestor->criar($tecnico, ['nome' => 'Outro']);
    }

    public function test_pagina_de_criar_grava_todos_os_campos_e_volta_a_listagem(): void
    {
        $this->actingAs($this->admin)->get(route('clientes.novo'))->assertOk()->assertSee('Novo cliente — Nexus Tempos', false);

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

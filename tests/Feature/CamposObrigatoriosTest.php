<?php

namespace Tests\Feature;

use App\Livewire\Equipa\Regras;
use App\Livewire\Relatorios\Despesas;
use App\Livewire\Relatorios\Detalhado;
use App\Livewire\Tempos\Cronometro;
use App\Models\Auditoria;
use App\Models\CategoriaDespesaTempo;
use App\Models\ClienteTempo;
use App\Models\DespesaTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\CamposObrigatorios;
use App\Services\Tempos\GestorClientes;
use App\Services\Tempos\GestorDespesas;
use App\Services\Tempos\GestorProjetos;
use App\Services\Tempos\GravadorRegistos;
use App\Support\Nif;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Campos obrigatórios nos registos (como os «Required fields» do Clockify, notas §58): quem gere a equipa
// escolhe, em Equipa › Regras, se o projeto, a descrição e as etiquetas são obrigatórios. Vale para toda
// a gente, admins incluídos. O cronómetro começa sem eles; para parar ou gravar, têm de estar preenchidos.
class CamposObrigatoriosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private ProjetoTempo $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->obra = ProjetoTempo::create(['nome' => 'Obra']);
    }

    private function erros(callable $acao): array
    {
        try {
            $acao();
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }

        return [];
    }

    public function test_por_omissao_nada_e_obrigatorio(): void
    {
        $this->assertSame([], app(CamposObrigatorios::class)->ativos());
        app(GravadorRegistos::class)->criar($this->ana, ['dia' => '2026-09-29', 'duracao_seg' => 3600]);
        $this->assertSame(1, RegistoTempo::count());
    }

    public function test_campos_obrigatorios_valem_para_todos_ao_gravar(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, 'registos', ['projeto', 'descricao', 'etiquetas']);
        $gravador = app(GravadorRegistos::class);

        foreach ([$this->ana, $this->admin] as $quem) {
            $this->assertSame([
                'projeto_id' => 'O projeto é obrigatório.',
                'descricao' => 'A descrição é obrigatória.',
                'etiquetas' => 'Indique pelo menos uma etiqueta.',
            ], $this->erros(fn () => $gravador->criar($quem, ['dia' => '2026-09-29', 'duracao_seg' => 3600])));
        }

        $gravador->criar($this->ana, ['dia' => '2026-09-29', 'duracao_seg' => 3600, 'projeto_id' => $this->obra->id, 'descricao' => 'Manutenção', 'etiquetas' => ['remoto']]);
        $this->assertSame(1, RegistoTempo::count());
    }

    public function test_cronometro_comeca_sem_eles_mas_so_para_com_eles(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, 'registos', ['projeto']);

        $pagina = Livewire::actingAs($this->ana)->test(Cronometro::class)->call('comecar')->assertSet('erro', null);
        $this->assertSame(1, RegistoTempo::whereNull('fim')->count(), 'começou sem projeto');

        Carbon::setTestNow('2026-09-30 10:30:00');
        $pagina->call('parar')->assertSet('erro', 'O projeto é obrigatório.');
        $this->assertSame(1, RegistoTempo::whereNull('fim')->count(), 'continua a correr');

        $pagina->set('barraProjeto', (string) $this->obra->id)->call('parar')->assertSet('erro', null);
        $this->assertSame(0, RegistoTempo::whereNull('fim')->count());
        $this->assertSame($this->obra->id, RegistoTempo::sole()->projeto_id);
    }

    public function test_so_quem_gere_a_equipa_muda_e_fica_na_auditoria(): void
    {
        Livewire::actingAs($this->admin)->test(Regras::class)
            ->set('campos.registos', ['projeto', 'descricao'])
            ->call('guardar')
            ->assertSee('Regras guardadas.');
        $this->assertSame(['projeto', 'descricao'], app(CamposObrigatorios::class)->ativos());
        $this->assertSame(['projeto', 'descricao'], Auditoria::where('acao', 'tempo_campos_obrigatorios')->sole()->detalhe['depois']);

        // O técnico vê as regras, mas não as muda.
        $this->actingAs($this->ana)->get(route('equipa.regras'))->assertOk()->assertSee('Campos obrigatórios');
        Livewire::actingAs($this->ana)->test(Regras::class)->set('campos.registos', [])->call('guardar')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(CamposObrigatorios::class)->definir($this->ana, 'registos', []);
    }

    public function test_campo_desconhecido_nao_entra(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, 'registos', ['projeto', 'tecnico_id', 'descricao', 'projeto']);
        $this->assertSame(['projeto', 'descricao'], app(CamposObrigatorios::class)->ativos());
    }

    public function test_formulario_marca_os_obrigatorios(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, 'registos', ['projeto']);

        // Sem os comentários que o Livewire mete à volta dos @if.
        $html = preg_replace('/<!--.*?-->/s', '', Livewire::actingAs($this->ana)->test(Detalhado::class)->call('novo')->html());
        $this->assertStringContainsString('for="registo-projeto">Projeto <span class="text-perigo-500">*</span>', $html);
        $this->assertStringNotContainsString('for="registo-descricao">Descrição <span class="text-perigo-500">*</span>', $html);
    }

    // --- Despesas, clientes e projetos ---

    public function test_despesas_com_recibo_e_projeto_obrigatorios(): void
    {
        Notification::fake();
        Storage::fake(DespesaTempo::DISCO);
        $regras = app(CamposObrigatorios::class);
        $regras->definir($this->admin, 'despesas', ['recibo', 'projeto']);
        $gestor = app(GestorDespesas::class);
        $dados = ['data' => '2026-09-29', 'valor' => '12,50', 'categoria_id' => CategoriaDespesaTempo::firstOrFail()->id];
        $recibo = fn () => UploadedFile::fake()->createWithContent('fatura.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $this->assertSame(['projeto_id' => 'O projeto é obrigatório.', 'recibo' => 'O recibo é obrigatório.'], $this->erros(fn () => $gestor->criar($this->ana, $dados)));
        $this->assertSame(0, DespesaTempo::count());
        $this->assertSame([], Storage::disk(DespesaTempo::DISCO)->allFiles(), 'sem ficheiros esquecidos');

        $d = $gestor->criar($this->ana, $dados + ['projeto_id' => $this->obra->id], $recibo());

        // Retirar o recibo, com ele obrigatório, não passa.
        $this->assertSame(['recibo' => 'O recibo é obrigatório.'], $this->erros(fn () => $gestor->atualizar($this->ana, $d->fresh(), [], null, retirarRecibo: true)));
        $this->assertNotNull($d->fresh()->recibo_caminho);

        // O formulário marca-os.
        $html = preg_replace('/<!--.*?-->/s', '', Livewire::actingAs($this->ana)->test(Despesas::class)->call('nova')->html());
        $this->assertStringContainsString('for="despesa-projeto">Projeto <span class="text-perigo-500">*</span>', $html);
        $this->assertStringContainsString('<span class="campo-label">Recibo <span class="text-perigo-500">*</span></span>', $html);
    }

    public function test_clientes_com_email_morada_e_nif(): void
    {
        $gestor = app(GestorClientes::class);
        app(CamposObrigatorios::class)->definir($this->admin, 'clientes', ['email', 'morada', 'nif']);

        $this->assertSame([
            'email' => 'O email é obrigatório.',
            'morada' => 'A morada é obrigatória.',
            'nif' => 'O NIF é obrigatório.',
        ], $this->erros(fn () => $gestor->criar($this->ana, ['nome' => 'Hospital'])));

        // O NIF português tem de bater certo com o dígito de controlo; um estrangeiro leva o código do país.
        $this->assertSame(['nif' => 'NIF inválido.'], $this->erros(fn () => $gestor->criar($this->ana, ['nome' => 'Hospital', 'email' => 'geral@h.pt', 'morada' => 'Lisboa', 'nif' => '501964842'])));
        $c = $gestor->criar($this->ana, ['nome' => 'Hospital', 'email' => 'geral@h.pt', 'morada' => 'Lisboa', 'nif' => 'PT 501 964 843']);
        $this->assertSame('501964843', $c->nif);
        $this->assertSame('GB123456789', $gestor->criar($this->ana, ['nome' => 'Retail', 'email' => 'a@r.uk', 'morada' => 'Londres', 'nif' => 'gb 123456789'])->nif);

        // Sem a regra, o NIF é opcional, mas se vier tem de ser válido.
        app(CamposObrigatorios::class)->definir($this->admin, 'clientes', []);
        $gestor->criar($this->ana, ['nome' => 'Sem NIF']);
        $this->assertSame(['nif' => 'NIF inválido.'], $this->erros(fn () => $gestor->criar($this->ana, ['nome' => 'Outro', 'nif' => '12AB'])));

        $this->assertTrue(Nif::valido('501964843'));
        $this->assertFalse(Nif::valido('123456788')); // o 123456789 tem mesmo o dígito de controlo certo
    }

    public function test_projetos_com_cliente_e_estimativa(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, 'projetos', ['cliente', 'estimativa']);
        $gestor = app(GestorProjetos::class);

        $this->assertSame([
            'cliente_id' => 'O cliente é obrigatório.',
            'estimativa' => 'A estimativa de horas é obrigatória.',
        ], $this->erros(fn () => $gestor->criar($this->admin, ['nome' => 'Obra nova'])));

        $cliente = ClienteTempo::create(['nome' => 'Hospital']);
        $p = $gestor->criar($this->admin, ['nome' => 'Obra nova', 'cliente_id' => $cliente->id, 'estimativa' => '40']);
        $this->assertSame(40 * 3600, $p->estimativa_seg);
    }

    public function test_pagina_regras_tem_uma_seccao_por_sitio(): void
    {
        Livewire::actingAs($this->admin)->test(Regras::class)
            ->assertSee('Registos de horas')->assertSee('Despesas')->assertSee('Clientes')->assertSee('Projetos')
            ->set('campos.despesas', ['recibo'])
            ->set('campos.clientes', ['nif'])
            ->set('campos.projetos', ['cliente'])
            ->call('guardar');

        $regras = app(CamposObrigatorios::class);
        $this->assertSame([[], ['recibo'], ['nif'], ['cliente']], [$regras->ativos('registos'), $regras->ativos('despesas'), $regras->ativos('clientes'), $regras->ativos('projetos')]);
    }
}

<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Ligação à suite: sem sessão vai-se ao portal; com sessão mas sem acesso aos tempos, volta-se à
// escolha de aplicações; conta desativada é posta fora; o papel vem do portal; e o migrate:fresh
// recusa-se a correr fora das bases descartáveis (a base de produção é partilhada).
class SuiteAcessoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_sessao_vai_ao_portal(): void
    {
        $this->get('/')->assertRedirect(config('app.portal_url'));
    }

    public function test_sem_acesso_a_esta_aplicacao_volta_ao_portal(): void
    {
        $this->actingAs($this->utilizador(null))->get('/')->assertRedirect(config('app.portal_url'));
    }

    public function test_com_acesso_entra(): void
    {
        $this->actingAs($this->tecnico())->get('/')->assertOk();
    }

    public function test_aplicacao_desativada_no_portal_nao_deixa_entrar(): void
    {
        $tecnico = $this->tecnico();
        DB::table('aplicacoes')->where('chave', config('app.chave'))->update(['activa' => false]);

        $this->actingAs($tecnico)->get('/')->assertRedirect(config('app.portal_url'));
    }

    public function test_conta_desativada_e_posta_fora(): void
    {
        $tecnico = $this->tecnico();
        $tecnico->update(['ativo' => false]);

        $this->actingAs($tecnico)->get('/')->assertRedirect(config('app.portal_url'));
        $this->assertGuest();
    }

    public function test_papel_vem_do_portal_e_valor_desconhecido_vale_tecnico(): void
    {
        $this->assertTrue($this->admin()->ehAdminTempos());
        $this->assertFalse($this->tecnico()->ehAdminTempos());
        $this->assertSame('tecnico', $this->utilizador('superadmin')->papelTempos());
    }

    public function test_reabrir_exige_ser_admin_e_estar_na_lista(): void
    {
        config(['tempos.pode_reabrir' => ['chefe@nxs.pt']]);

        $this->assertTrue($this->admin('Chefe@nxs.pt')->podeReabrirTempos());
        $this->assertFalse($this->admin()->podeReabrirTempos());
        $this->assertFalse($this->utilizador('tecnico', 'chefe2@nxs.pt')->podeReabrirTempos());
    }

    public function test_migrate_fresh_recusa_fora_das_bases_descartaveis(): void
    {
        $original = config('database.connections.pgsql.database');
        config(['database.connections.pgsql.database' => 'nexus_ops']);
        (new AppServiceProvider($this->app))->boot();
        config(['database.connections.pgsql.database' => $original]);

        try {
            $this->artisan('migrate:fresh')->assertExitCode(Command::FAILURE);
        } finally {
            FreshCommand::prohibit(false);
        }
    }
}

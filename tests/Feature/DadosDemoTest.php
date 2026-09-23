<?php

namespace Tests\Feature;

use App\Models\AtribuicaoTempo;
use App\Models\CategoriaDespesaTempo;
use App\Models\Cliente;
use App\Models\ClienteTempo;
use App\Models\DespesaTempo;
use App\Models\GrupoEquipa;
use App\Models\LembreteEquipa;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\TaxaMembro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Comando tempos:demo — dados de demonstração só nas tabelas dos Tempos, em cima das pessoas que já
// existem na Nexus Infra, e apagados exatamente (e só eles) com --apagar.
class DadosDemoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-22 14:00:00'); // terça-feira, 15:00 em Lisboa
        Storage::fake('local');

        $this->admin = $this->admin();
        $this->tecnico();
        $this->tecnico();
        $this->utilizador(null); // sem acesso aos Tempos: não recebe horas

        $this->cliente('Hospital Real'); // da Nexus Infra: fica intacto e fora dos registos
    }

    public function test_cria_dados_em_todas_as_tabelas_dos_tempos_e_so_nelas(): void
    {
        $antes = $this->contagensDaNexusInfra();

        $this->artisan('tempos:demo')->assertExitCode(0);

        $this->assertSame($antes, $this->contagensDaNexusInfra(), 'as tabelas da Nexus Infra não podem mudar');
        // Clientes variados, para a listagem e a página de cada um mostrarem todos os casos.
        $this->assertSame(9, ClienteTempo::count());
        $this->assertSame(1, ClienteTempo::arquivados()->count());
        $this->assertSame(['EUR', 'GBP', 'USD'], ClienteTempo::distinct()->orderBy('moeda')->pluck('moeda')->all());
        $this->assertSame(1, ClienteTempo::whereNull('email')->count());
        $this->assertSame(1, ClienteTempo::whereNotIn('id', ProjetoTempo::whereNotNull('cliente_id')->select('cliente_id'))->count(), 'um cliente sem projetos');
        $this->assertSame(13, ProjetoTempo::count());
        $this->assertSame(2, ProjetoTempo::arquivados()->count());
        $this->assertSame(3, MembroEquipa::count());
        $this->assertSame(6, TaxaMembro::count());
        $this->assertSame(2, GrupoEquipa::count());
        $this->assertSame(3, LembreteEquipa::count());
        $this->assertSame(0, LembreteEquipa::where('ativo', true)->count(), 'desligados: a demonstração não manda emails');
        $this->assertSame(10, DespesaTempo::count());
        $this->assertSame(7, CategoriaDespesaTempo::count(), 'usa as categorias da migração, não cria novas');
        $this->assertSame(5, AtribuicaoTempo::count());
        $this->assertGreaterThan(200, RegistoTempo::count());
        $this->assertTrue(Storage::disk('local')->exists('dados-demo.json'));
    }

    public function test_registos_sao_dos_tempos_sem_ligacao_a_nexus_infra(): void
    {
        $this->artisan('tempos:demo')->assertExitCode(0);

        $semAcesso = User::where('nome', 'like', 'Pessoa de teste %')->whereNotIn('id', User::comAcessoAosTempos()->select('id'))->first();
        $this->assertSame(0, RegistoTempo::where('tecnico_id', $semAcesso->id)->count());

        foreach (RegistoTempo::all() as $r) {
            $this->assertSame([null, null, null], [$r->cliente_id, $r->contrato_id, $r->intervencao_id], 'registo '.$r->id);
            $this->assertNotNull($r->projeto_id);
            if ($r->fim) {
                $this->assertSame($r->duracao_seg, (int) $r->fim->diffInSeconds($r->inicio, true));
                $this->assertLessThanOrEqual(3 * 3600, $r->duracao_seg);
                $this->assertLessThanOrEqual(Carbon::now(), $r->fim, 'nada no futuro');
            }
        }

        // Um cronómetro a correr para quem administra; ninguém passa das 9 h por dia.
        $aCorrer = RegistoTempo::whereNull('fim')->get();
        $this->assertCount(1, $aCorrer);
        $this->assertSame($this->admin->id, $aCorrer[0]->tecnico_id);

        $maximo = RegistoTempo::whereNotNull('fim')
            ->selectRaw("tecnico_id, (inicio at time zone 'Europe/Lisbon')::date as dia, sum(duracao_seg) as total")
            ->groupBy('tecnico_id', 'dia')->orderByDesc('total')->first();
        $this->assertLessThanOrEqual(9 * 3600, (int) $maximo->total);
    }

    public function test_nao_corre_duas_vezes_sem_apagar(): void
    {
        $this->artisan('tempos:demo')->assertExitCode(0);
        $registos = RegistoTempo::count();

        $this->artisan('tempos:demo')->assertExitCode(1);
        $this->assertSame($registos, RegistoTempo::count());
    }

    public function test_apagar_remove_exatamente_o_que_criou(): void
    {
        // Dados a sério que já lá estavam e têm de ficar.
        $cliente = Cliente::first();
        $meu = $this->registo($this->admin, $cliente, '2026-09-21', 3600, ['descricao' => 'Registo a sério']);
        CategoriaDespesaTempo::where('nome', 'Material')->delete(); // uma categoria em falta: o comando cria-a e apaga-a depois
        $categorias = CategoriaDespesaTempo::pluck('id')->all();
        $projeto = ProjetoTempo::create(['nome' => 'Projeto a sério']);
        $antes = $this->contagensDaNexusInfra();

        $this->artisan('tempos:demo')->assertExitCode(0);
        $this->assertSame(7, CategoriaDespesaTempo::count(), 'só cria as categorias que faltam');

        $this->artisan('tempos:demo --apagar')->assertExitCode(0);

        $this->assertSame($antes, $this->contagensDaNexusInfra());
        $this->assertSame([$meu->id], RegistoTempo::withTrashed()->pluck('id')->all());
        $this->assertSame([$projeto->id], ProjetoTempo::withTrashed()->pluck('id')->all());
        $this->assertSame($categorias, CategoriaDespesaTempo::pluck('id')->all());
        $this->assertSame(0, ClienteTempo::withTrashed()->count());
        $this->assertSame(0, DespesaTempo::withTrashed()->count());
        $this->assertSame(0, AtribuicaoTempo::count());
        $this->assertSame(0, TaxaMembro::count());
        $this->assertSame(0, GrupoEquipa::count());
        $this->assertSame(0, LembreteEquipa::count());
        $this->assertSame(0, DB::table('grupo_membro')->count());
        $this->assertSame(0, DB::table('projeto_membro')->count());
        $this->assertSame(3, MembroEquipa::count(), 'as linhas da equipa são as pessoas a sério: ficam');
        $this->assertFalse(Storage::disk('local')->exists('dados-demo.json'));

        // Apagar sem haver nada é inofensivo, e depois pode-se criar de novo.
        $this->artisan('tempos:demo --apagar')->assertExitCode(0);
        $this->artisan('tempos:demo')->assertExitCode(0);
    }

    /** @return array<string, int> */
    private function contagensDaNexusInfra(): array
    {
        return collect(['utilizadores', 'acessos', 'clientes', 'contratos', 'intervencoes', 'equipamentos', 'locais', 'auditoria'])
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
    }
}

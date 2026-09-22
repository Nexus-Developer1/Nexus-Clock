<?php

namespace Tests;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\RegistoTempo;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Tripwire: o RefreshDatabase apaga tudo. Se, por alguma razão (variáveis de ambiente do
        // SO a vencer o phpunit.xml), a base não for a de teste, aborta antes de tocar em nada.
        if (config('database.connections.pgsql.database') !== 'tempos_testing') {
            throw new \RuntimeException('Os testes têm de correr na BD tempos_testing.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Os testes precisam de criar clientes, contratos e intervenções (tabelas da Nexus Infra).
        config(['tempos.escrever_tabelas_da_nexus_infra' => true]);
    }

    // Pessoa da suite com acesso a esta aplicação no papel indicado ('admin' | 'tecnico').
    // Sem papel (null) = existe na suite mas o portal não lhe deu acesso aos tempos.
    private static int $seq = 0;

    protected function utilizador(?string $papel = 'tecnico', ?string $email = null): User
    {
        $n = ++self::$seq;
        $user = User::create([
            'nome' => 'Pessoa de teste '.$n,
            'email' => $email ?? 'pessoa'.$n.'@nxs.pt',
            'password' => 'x',
            'papel' => 'tecnico',
            'ativo' => true,
        ]);

        if ($papel !== null) {
            $aplicacaoId = DB::table('aplicacoes')->where('chave', config('app.chave'))->value('id')
                ?? DB::table('aplicacoes')->insertGetId([
                    'chave' => config('app.chave'), 'nome' => 'Suporte', 'url' => 'http://localhost', 'activa' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            DB::table('acessos')->insert([
                'utilizador_id' => $user->id, 'aplicacao_id' => $aplicacaoId, 'papel' => $papel,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    protected function tecnico(): User
    {
        return $this->utilizador('tecnico');
    }

    protected function admin(?string $email = null): User
    {
        return $this->utilizador('admin', $email);
    }

    protected function cliente(string $nome = ''): Cliente
    {
        return Cliente::create(['nome' => $nome ?: 'Cliente de teste '.(++self::$seq), 'ativo' => true]);
    }

    protected function contrato(Cliente $cliente, string $numero = ''): Contrato
    {
        return Contrato::create([
            'numero' => $numero ?: 'CT-'.(++self::$seq), 'cliente_id' => $cliente->id,
            'data_inicio' => '2026-01-01', 'data_fim' => '2027-12-31', 'estado' => 'ativo', 'tipo' => 'preventiva',
        ]);
    }

    // Intervenção num equipamento do cliente (com local). Sem cliente = equipamento por associar.
    protected function intervencao(?Cliente $cliente, ?Contrato $contrato = null, string $estado = 'em_curso'): Intervencao
    {
        $localId = $cliente ? Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede '.(++self::$seq)])->id : null;
        $equipamento = Equipamento::create(['local_id' => $localId, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-'.(++self::$seq)]);

        return Intervencao::create([
            'equipamento_id' => $equipamento->id, 'contrato_id' => $contrato?->id,
            'tipo' => 'corretiva', 'estado' => $estado,
        ]);
    }

    /**
     * Registo de timesheet criado DIRETAMENTE (sem passar pelo GravadorRegistos) — para preparar
     * cenários. Os testes das regras de escrita usam o gravador.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function registo(User $tecnico, Cliente $cliente, string $dia, int $segundos, array $extra = []): RegistoTempo
    {
        $inicio = RegistoTempo::inicioDoDia($dia);

        $registo = new RegistoTempo(array_merge([
            'tecnico_id' => $tecnico->id,
            'cliente_id' => $cliente->id,
            'inicio' => $inicio,
            'fim' => $inicio->addSeconds($segundos),
            'duracao_seg' => $segundos,
        ], $extra));
        foreach (['fechado_em', 'faturado_em', 'submetido_em'] as $coluna) {
            if (array_key_exists($coluna, $extra)) {
                $registo->{$coluna} = $extra[$coluna];
            }
        }
        $registo->save();

        return $registo;
    }
}

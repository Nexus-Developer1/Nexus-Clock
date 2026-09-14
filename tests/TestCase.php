<?php

namespace Tests;

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

    // Pessoa da suite com acesso a esta aplicação no papel indicado ('admin' | 'tecnico').
    // Sem papel (null) = existe na suite mas o portal não lhe deu acesso aos tempos.
    private static int $seqUtilizador = 0;

    protected function utilizador(?string $papel = 'tecnico', ?string $email = null): User
    {
        $n = ++self::$seqUtilizador;
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
                    'chave' => config('app.chave'), 'nome' => 'Tempos', 'url' => 'http://localhost', 'activa' => true,
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
}

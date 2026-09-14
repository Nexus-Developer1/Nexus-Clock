<?php

namespace Database\Seeders;

use App\Enums\EstadoSemanaTempo;
use App\Jobs\AtualizarConsumoContratos;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\RegistoTempo;
use App\Models\SemanaTempo;
use App\Models\Tarifa;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dados de exemplo para a base de DESENVOLVIMENTO (tempos_dev): pessoas com acesso aos tempos,
 * clientes, contratos, intervenções, tarifas, horas incluídas e ~3 meses de timesheet.
 *
 * Cria linhas em tabelas da Nexus Infra, por isso recusa-se a correr fora das bases descartáveis.
 * Determinístico (semente fixa): quem corre duas vezes vê os mesmos números.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $base = config('database.connections.'.config('database.default').'.database');
        if (! in_array($base, AppServiceProvider::BASES_DESCARTAVEIS, true)) {
            throw new RuntimeException("O seeder dos tempos só corre em bases de desenvolvimento, não em «{$base}».");
        }

        config(['tempos.escrever_tabelas_da_nexus_infra' => true]);
        mt_srand(2026);

        // --- Pessoas e acessos (no portal) ---
        $aplicacaoId = DB::table('aplicacoes')->insertGetId([
            'chave' => config('app.chave'), 'nome' => 'Tempos', 'descricao' => 'Registo de horas dos técnicos',
            'url' => config('app.url'), 'icone' => 'relogio', 'activa' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $pessoa = function (string $nome, string $email, string $papel) use ($aplicacaoId): User {
            $user = User::create(['nome' => $nome, 'email' => $email, 'password' => 'password', 'papel' => $papel === 'admin' ? 'admin' : 'tecnico', 'ativo' => true]);
            DB::table('acessos')->insert(['utilizador_id' => $user->id, 'aplicacao_id' => $aplicacaoId, 'papel' => $papel, 'created_at' => now(), 'updated_at' => now()]);

            return $user;
        };

        $pessoa('Suporte Nexus', 'suporte@nxs.pt', 'admin');
        $tecnicos = [
            $pessoa('Ana Martins', 'ana.martins@nexus.test', 'tecnico'),
            $pessoa('Bruno Costa', 'bruno.costa@nexus.test', 'tecnico'),
            $pessoa('Carla Sousa', 'carla.sousa@nexus.test', 'tecnico'),
        ];

        // --- Clientes, contratos e intervenções (tabelas da Nexus Infra) ---
        $clientes = collect(['Hospital da Luz Exemplo', 'Banco Atlântico Exemplo', 'Data Center Norte', 'Câmara Municipal Exemplo'])
            ->map(fn (string $nome, int $i) => Cliente::create(['nome' => $nome, 'nif' => '50000000'.$i, 'ativo' => true]));

        $contratos = [
            // cliente, número, horas incluídas (período, horas, transita)
            [$clientes[0], 'CT-2026-001', ['mensal', 10, true]],
            [$clientes[1], 'CT-2026-002', ['trimestral', 24, false]],
            [$clientes[2], 'CT-2026-003', ['anual', 120, false]],
            [$clientes[3], 'CT-2026-004', null], // sem horas incluídas: tudo é faturável
        ];

        $inicioAno = CarbonImmutable::parse('2026-01-01');
        $intervencoes = [];

        foreach ($contratos as [$cliente, $numero, $horas]) {
            $contrato = Contrato::create([
                'numero' => $numero, 'cliente_id' => $cliente->id, 'data_inicio' => $inicioAno, 'data_fim' => '2027-12-31',
                'estado' => 'ativo', 'tipo' => 'full-service',
            ]);

            if ($horas) {
                ContratoHorasIncluidas::create([
                    'contrato_id' => $contrato->id, 'periodo' => $horas[0], 'horas_incluidas' => $horas[1],
                    'transita' => $horas[2], 'valido_de' => $inicioAno,
                ]);
            }

            $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
            foreach (['em_curso', 'concluida'] as $estado) {
                $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'fabricante' => 'Riello', 'modelo' => 'MST 80', 'numero_serie' => 'SN-'.$numero.'-'.$estado, 'estado' => 'operacional']);
                $intervencoes[] = Intervencao::create(['equipamento_id' => $equipamento->id, 'contrato_id' => $contrato->id, 'tipo' => 'preventiva', 'estado' => $estado, 'tecnico_id' => $tecnicos[0]->id]);
            }
        }

        // O contrato 1 muda de condições a meio do ano (mais horas a partir de julho).
        $primeiro = ContratoHorasIncluidas::where('contrato_id', $intervencoes[0]->contrato_id)->first();
        $primeiro->update(['valido_ate' => '2026-06-30']);
        ContratoHorasIncluidas::create(['contrato_id' => $primeiro->contrato_id, 'periodo' => 'mensal', 'horas_incluidas' => 15, 'transita' => true, 'valido_de' => '2026-07-01']);

        // --- Tarifas: global, uma por cliente, uma por contrato, uma por técnico ---
        Tarifa::create(['preco_hora_cent' => 4500, 'custo_hora_cent' => 2200, 'valido_de' => '2025-01-01', 'valido_ate' => '2025-12-31']);
        Tarifa::create(['preco_hora_cent' => 4800, 'custo_hora_cent' => 2300, 'valido_de' => '2026-01-01']);
        Tarifa::create(['ambito_tipo' => 'cliente', 'ambito_id' => $clientes[1]->id, 'preco_hora_cent' => 5500, 'custo_hora_cent' => 2300, 'valido_de' => '2026-01-01']);
        Tarifa::create(['ambito_tipo' => 'contrato', 'ambito_id' => $intervencoes[4]->contrato_id, 'preco_hora_cent' => 6000, 'custo_hora_cent' => 2300, 'valido_de' => '2026-01-01']);
        Tarifa::create(['ambito_tipo' => 'tecnico', 'ambito_id' => $tecnicos[2]->id, 'preco_hora_cent' => 5200, 'custo_hora_cent' => 2800, 'valido_de' => '2026-01-01']);

        // --- Timesheet: 13 semanas até à semana passada, dias úteis, 1–3 linhas por dia ---
        $segundaActual = CarbonImmutable::now(config('tempos.fuso'))->startOfWeek();
        $etiquetas = [[], [], ['remoto'], ['deslocação']];

        foreach ($tecnicos as $tecnico) {
            for ($semana = 13; $semana >= 1; $semana--) {
                $segunda = $segundaActual->subWeeks($semana);

                for ($d = 0; $d < 5; $d++) {
                    $dia = $segunda->addDays($d);
                    $inicio = RegistoTempo::inicioDoDia($dia);

                    // array_rand devolve um int (não uma lista) quando se pede só um.
                    foreach ((array) array_rand($intervencoes, mt_rand(1, 3)) as $indice) {
                        $intervencao = $intervencoes[$indice];
                        $segundos = mt_rand(2, 16) * 15 * 60; // 0:30 a 4:00
                        $contrato = Contrato::find($intervencao->contrato_id);

                        RegistoTempo::create([
                            'tecnico_id' => $tecnico->id, 'cliente_id' => $contrato->cliente_id, 'contrato_id' => $contrato->id,
                            'intervencao_id' => $intervencao->id, 'inicio' => $inicio, 'fim' => $inicio->addSeconds($segundos),
                            'duracao_seg' => $segundos, 'faturavel' => mt_rand(1, 10) > 2, 'descricao' => 'Manutenção '.$contrato->numero,
                            'etiquetas' => $etiquetas[mt_rand(0, 3)], 'origem' => 'timesheet',
                        ]);
                    }
                }

                // Semanas antigas submetidas; as duas últimas ficam em rascunho.
                if ($semana > 2) {
                    SemanaTempo::create(['tecnico_id' => $tecnico->id, 'semana_inicio' => $segunda, 'estado' => EstadoSemanaTempo::Submetida, 'submetida_em' => $segunda->addDays(7)]);
                }
            }
        }

        AtualizarConsumoContratos::dispatchSync();
    }
}

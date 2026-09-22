<?php

namespace App\Console\Commands;

use App\Enums\OrigemRegistoTempo;
use App\Jobs\AtualizarConsumoContratos;
use App\Models\AtribuicaoTempo;
use App\Models\CategoriaDespesaTempo;
use App\Models\ClienteTempo;
use App\Models\DespesaTempo;
use App\Models\GrupoEquipa;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\TaxaMembro;
use App\Models\User;
use App\Services\Tempos\GestorEquipa;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Dados de demonstração para ver as páginas dos Tempos preenchidas (cronómetro, calendário, painel,
 * relatórios, projetos, equipa, clientes, despesas, atribuições) — e para os apagar a seguir.
 *
 * Escreve SÓ nas tabelas dos Tempos, em cima das pessoas com acesso (a única coisa que lê da Nexus
 * Infra). Os IDs de tudo o que cria ficam em
 * storage/app/private/dados-demo.json; `--apagar` remove exatamente esses e mais nada.
 *
 * Determinístico (semente fixa) e cauteloso: não corre duas vezes sem apagar primeiro.
 */
class DadosDemo extends Command
{
    protected $signature = 'tempos:demo {--apagar : Apaga os dados de demonstração criados antes}';

    protected $description = 'Cria (ou apaga, com --apagar) dados de demonstração nas tabelas dos Tempos';

    private const FICHEIRO = 'dados-demo.json';

    private const SEMANAS = 8;

    private const MAX_PESSOAS = 8;

    /** @var array<string, list<int>> IDs criados, por tabela. */
    private array $criados = [];

    public function handle(GestorEquipa $equipa): int
    {
        if ($this->option('apagar')) {
            return $this->apagar();
        }

        if (Storage::disk('local')->exists(self::FICHEIRO)) {
            $registo = json_decode(Storage::disk('local')->get(self::FICHEIRO), true);
            $this->error('Já há dados de demonstração (criados em '.($registo['criado_em'] ?? '?').'). Apague-os primeiro com --apagar.');

            return self::FAILURE;
        }

        $pessoas = User::comAcessoAosTempos()->orderBy('nome')->limit(self::MAX_PESSOAS)->get();
        if ($pessoas->isEmpty()) {
            $this->error('Não há ninguém com acesso aos Tempos: não há a quem atribuir horas.');

            return self::FAILURE;
        }

        mt_srand(2026);
        $fuso = config('tempos.fuso');
        $agora = CarbonImmutable::now($fuso);

        DB::transaction(function () use ($equipa, $pessoas, $fuso, $agora) {
            $equipa->sincronizar();
            $membros = MembroEquipa::whereIn('utilizador_id', $pessoas->pluck('id'))->get()->keyBy('utilizador_id');
            $admin = $pessoas->first(fn (User $u) => $u->ehAdminTempos()) ?? $pessoas->first();

            $clientesTempos = $this->criarClientesTempos();
            $projetos = $this->criarProjetos($clientesTempos, $membros, $agora);
            $this->criarTaxas($membros, $admin);
            $this->criarGrupos($membros);
            $this->criarRegistos($pessoas, $projetos, $fuso, $agora, $admin);
            $this->criarDespesas($pessoas, $projetos, $admin, $agora);
            $this->criarAtribuicoes($pessoas, $projetos, $admin, $agora);

            Storage::disk('local')->put(self::FICHEIRO, json_encode([
                'criado_em' => $agora->toDateTimeString(),
                'tabelas' => $this->criados,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        });

        AtualizarConsumoContratos::dispatchSync();

        $this->info('Dados de demonstração criados para '.$pessoas->count().' pessoas.');
        $this->table(['Tabela', 'Linhas'], collect($this->criados)->map(fn (array $ids, string $t) => [$t, count($ids)])->values()->all());
        $this->line('Para os apagar: php artisan tempos:demo --apagar');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ apagar

    private function apagar(): int
    {
        if (! Storage::disk('local')->exists(self::FICHEIRO)) {
            $this->warn('Não há dados de demonstração para apagar (não existe '.self::FICHEIRO.').');

            return self::SUCCESS;
        }

        $registo = json_decode(Storage::disk('local')->get(self::FICHEIRO), true);
        $tabelas = $registo['tabelas'] ?? [];
        $apagados = [];

        DB::transaction(function () use ($tabelas, &$apagados) {
            // Pela ordem inversa das dependências. forceDelete nos modelos com soft delete: os dados
            // de demonstração não devem ficar a "existir apagados".
            $apagados['registos_tempo'] = RegistoTempo::withTrashed()->whereIn('id', $tabelas['registos_tempo'] ?? [])->forceDelete();
            $apagados['despesas_tempos'] = DespesaTempo::withTrashed()->whereIn('id', $tabelas['despesas_tempos'] ?? [])->forceDelete();
            $apagados['atribuicoes_tempos'] = AtribuicaoTempo::whereIn('id', $tabelas['atribuicoes_tempos'] ?? [])->delete();
            $apagados['taxas_membros'] = TaxaMembro::whereIn('id', $tabelas['taxas_membros'] ?? [])->delete();
            $apagados['grupos_equipa'] = GrupoEquipa::whereIn('id', $tabelas['grupos_equipa'] ?? [])->delete();
            $apagados['projetos_tempos'] = ProjetoTempo::withTrashed()->whereIn('id', $tabelas['projetos_tempos'] ?? [])->forceDelete();
            $apagados['clientes_tempos'] = ClienteTempo::withTrashed()->whereIn('id', $tabelas['clientes_tempos'] ?? [])->forceDelete();
            $apagados['categorias_despesa_tempos'] = CategoriaDespesaTempo::whereIn('id', $tabelas['categorias_despesa_tempos'] ?? [])->delete();
        });

        Storage::disk('local')->delete(self::FICHEIRO);
        AtualizarConsumoContratos::dispatchSync();

        $this->info('Dados de demonstração apagados.');
        $this->table(['Tabela', 'Linhas'], collect($apagados)->map(fn (int $n, string $t) => [$t, $n])->values()->all());

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ criação

    /** @return list<ClienteTempo> */
    private function criarClientesTempos(): array
    {
        $dados = [
            ['Hospital da Luz (demo)', 'Av. Lusíada 100, 1500-650 Lisboa', 'facturacao@hospitaldaluz.demo'],
            ['Banco Atlântico (demo)', 'Rua do Ouro 12, 1100-060 Lisboa', 'compras@bancoatlantico.demo'],
            ['Data Center Norte (demo)', 'Zona Industrial da Maia, lote 7, 4470-605 Maia', 'operacoes@dcnorte.demo'],
        ];

        $lista = [];
        foreach ($dados as [$nome, $morada, $email]) {
            $c = new ClienteTempo(['nome' => $nome, 'morada' => $morada, 'email' => $email, 'nota' => 'Cliente de demonstração — apagar com tempos:demo --apagar']);
            $c->save();
            $this->criados['clientes_tempos'][] = $c->id;
            $lista[] = $c;
        }

        return $lista;
    }

    /**
     * @param  list<ClienteTempo>  $clientes
     * @param  Collection<int, MembroEquipa>  $membros
     * @return list<array{projeto: ProjetoTempo, cliente: int, descricoes: list<string>}>
     */
    private function criarProjetos(array $clientes, Collection $membros, CarbonImmutable $agora): array
    {
        // nome, índice do cliente (null = sem cliente), cor, faturável, taxa €/h, estimativa h, público, arquivado
        $dados = [
            ['Manutenção preventiva UPS', 0, '#16a34a', true, null, 120, true, false, ['Manutenção preventiva UPS piso 2', 'Teste de baterias e registo de medições', 'Verificação de alarmes e ventilação', 'Relatório de intervenção']],
            ['Substituição de baterias', 0, '#eb6834', true, null, 40, true, false, ['Substituição de baterias UPS bloco B', 'Deslocação e descarga de material', 'Teste de autonomia após substituição']],
            ['Suporte remoto', 1, '#2a78d6', true, 5500, null, true, false, ['Suporte remoto — alarme de bypass', 'Análise de logs da UPS', 'Chamada com o cliente e diagnóstico', 'Configuração de SNMP']],
            ['Instalação data center', 2, '#7c3aed', true, null, 200, false, false, ['Instalação de UPS modular', 'Cablagem e quadro de distribuição', 'Arranque e testes de carga', 'Reunião de obra']],
            ['Formação interna', null, '#64748b', false, null, null, true, false, ['Formação: novas UPS modulares', 'Reunião de equipa', 'Organização do armazém']],
            ['Auditoria energética', 1, '#eda100', true, 6000, 30, true, true, ['Levantamento de cargas', 'Medições e relatório de auditoria']],
        ];

        $lista = [];
        foreach ($dados as [$nome, $clienteIdx, $cor, $faturavel, $taxa, $estimativa, $publico, $arquivado, $descricoes]) {
            $p = new ProjetoTempo([
                'nome' => $nome,
                'cliente_id' => $clienteIdx === null ? null : $clientes[$clienteIdx]->id,
                'cor' => $cor,
                'publico' => $publico,
                'faturavel' => $faturavel,
                'taxa_cent' => $taxa,
                'estimativa_seg' => $estimativa ? $estimativa * 3600 : null,
                'nota' => 'Projeto de demonstração',
            ]);
            if ($arquivado) {
                $p->arquivado_em = $agora->subWeeks(3);
            }
            $p->save();
            if (! $publico) {
                $p->membros()->sync($membros->take(3)->pluck('id')->all());
            }
            $this->criados['projetos_tempos'][] = $p->id;
            $lista[] = ['projeto' => $p, 'cliente' => $clienteIdx ?? 0, 'descricoes' => $descricoes];
        }

        return $lista;
    }

    /** @param Collection<int, MembroEquipa> $membros */
    private function criarTaxas(Collection $membros, User $admin): void
    {
        foreach ($membros as $membro) {
            foreach (['faturavel' => mt_rand(42, 60) * 100, 'custo' => mt_rand(18, 30) * 100] as $tipo => $valor) {
                if (TaxaMembro::where('membro_id', $membro->id)->where('tipo', $tipo)->exists()) {
                    continue; // já tem taxa a sério: não se mexe
                }
                $t = TaxaMembro::create(['membro_id' => $membro->id, 'tipo' => $tipo, 'valor_cent' => $valor, 'valido_de' => '2026-01-01', 'criado_por' => $admin->id]);
                $this->criados['taxas_membros'][] = $t->id;
            }
        }
    }

    /** @param Collection<int, MembroEquipa> $membros */
    private function criarGrupos(Collection $membros): void
    {
        $metade = (int) ceil($membros->count() / 2);
        foreach (['Equipa Norte (demo)' => $membros->take($metade), 'Equipa Sul (demo)' => $membros->skip($metade)] as $nome => $quem) {
            $g = GrupoEquipa::create(['nome' => $nome]);
            $g->membros()->sync($quem->pluck('id')->all());
            $this->criados['grupos_equipa'][] = $g->id;
        }
    }

    /**
     * Semanas de trabalho realistas: blocos seguidos a partir das 09h, almoço, fim entre as 17h30 e
     * as 18h30, alguns dias sem nada. Na semana atual só até agora; quem corre o comando fica com
     * um cronómetro a andar (se não tiver já um).
     *
     * @param  Collection<int, User>  $pessoas
     * @param  list<array{projeto: ProjetoTempo, cliente: int, descricoes: list<string>}>  $projetos
     */
    private function criarRegistos(Collection $pessoas, array $projetos, string $fuso, CarbonImmutable $agora, User $admin): void
    {
        $ativos = array_values(array_filter($projetos, fn (array $p) => ! $p['projeto']->estaArquivado()));
        $arquivados = array_values(array_filter($projetos, fn (array $p) => $p['projeto']->estaArquivado()));
        $etiquetas = [[], [], [], ['remoto'], ['deslocação'], ['urgente'], ['remoto', 'urgente']];
        $duracoes = [1800, 2700, 3600, 3600, 5400, 7200, 9000, 10800];
        $segundaAtual = $agora->startOfWeek();

        foreach ($pessoas->values() as $i => $pessoa) {
            $semanaDeFerias = $i === 1 ? self::SEMANAS - 3 : null; // a segunda pessoa esteve de férias

            for ($s = self::SEMANAS; $s >= 0; $s--) {
                if ($s === $semanaDeFerias) {
                    continue;
                }
                $segunda = $segundaAtual->subWeeks($s);

                for ($d = 0; $d < 5; $d++) {
                    $dia = $segunda->addDays($d);
                    if ($dia->gt($agora) || mt_rand(1, 100) <= 7) {
                        continue; // futuro, ou um dia sem registos
                    }

                    $cursor = $dia->setTime(9, 0)->addMinutes(mt_rand(0, 6) * 5);
                    $fimDoDia = $dia->setTime(17, 30)->addMinutes(mt_rand(0, 12) * 5);
                    $almoco = false;
                    $total = 0;

                    while ($cursor->lt($fimDoDia) && $total < 9 * 3600) {
                        if (! $almoco && $cursor->gte($dia->setTime(13, 0))) {
                            $almoco = true;
                            if ($cursor->lt($dia->setTime(14, 0))) {
                                $cursor = $dia->setTime(14, 0)->addMinutes(mt_rand(0, 3) * 5);
                            }
                        }

                        // Um bloco nunca atravessa o almoço, nem passa muito do fim do dia, nem (hoje)
                        // chega a agora — fica meia hora para o cronómetro a correr. Nem passa das 9 h.
                        $limite = ($almoco ? $fimDoDia->addHour() : $dia->setTime(13, 0))->min($agora->subMinutes(30));
                        $segundos = min(
                            $duracoes[mt_rand(0, count($duracoes) - 1)],
                            (int) floor($cursor->diffInSeconds($limite, false) / 900) * 900,
                            9 * 3600 - $total,
                        );
                        if ($segundos < 1800) {
                            if (! $almoco) {
                                $cursor = $dia->setTime(13, 0); // vai almoçar

                                continue;
                            }
                            break;
                        }
                        $fim = $cursor->addSeconds($segundos);

                        // Projetos arquivados só têm horas antigas.
                        $escolha = $s >= 5 && $arquivados && mt_rand(1, 100) <= 15
                            ? $arquivados[mt_rand(0, count($arquivados) - 1)]
                            : $ativos[mt_rand(0, count($ativos) - 1)];

                        $this->gravarRegisto($pessoa, $escolha, $cursor, $fim, $segundos, [
                            'etiquetas' => $etiquetas[mt_rand(0, count($etiquetas) - 1)],
                            'origem' => mt_rand(1, 100) <= 60 ? OrigemRegistoTempo::Cronometro : OrigemRegistoTempo::Timesheet,
                        ]);

                        $total += $segundos;
                        $cursor = $fim->addMinutes(mt_rand(0, 3) * 5);
                    }
                }
            }
        }

        // Cronómetro a correr para quem administra (é quem normalmente está a ver a demonstração).
        if (! RegistoTempo::query()->doTecnico($admin)->whereNull('fim')->exists()) {
            $inicio = $agora->subMinutes(mt_rand(8, 40));
            $this->gravarRegisto($admin, $ativos[0], $inicio, null, null, ['origem' => OrigemRegistoTempo::Cronometro]);
        }
    }

    /**
     * @param  array{projeto: ProjetoTempo, cliente: int, descricoes: list<string>}  $escolha
     * @param  array<string, mixed>  $extra
     */
    private function gravarRegisto(User $pessoa, array $escolha, CarbonImmutable $inicio, ?CarbonImmutable $fim, ?int $segundos, array $extra): void
    {
        $descricoes = $escolha['descricoes'];
        $r = new RegistoTempo([
            'tecnico_id' => $pessoa->id,
            'projeto_id' => $escolha['projeto']->id,
            'inicio' => $inicio->utc(),
            'fim' => $fim?->utc(),
            'duracao_seg' => $segundos,
            'faturavel' => $escolha['projeto']->faturavel && mt_rand(1, 100) <= 85,
            'descricao' => $descricoes[mt_rand(0, count($descricoes) - 1)],
            'etiquetas' => $extra['etiquetas'] ?? [],
            'origem' => $extra['origem'] ?? OrigemRegistoTempo::Timesheet,
        ]);
        $r->criado_por = $pessoa->id;
        $r->alterado_por = $pessoa->id;
        $r->save();

        $this->criados['registos_tempo'][] = $r->id;
    }

    /**
     * @param  Collection<int, User>  $pessoas
     * @param  list<array{projeto: ProjetoTempo, cliente: int, descricoes: list<string>}>  $projetos
     */
    private function criarDespesas(Collection $pessoas, array $projetos, User $admin, CarbonImmutable $agora): void
    {
        // As categorias iniciais da migração; se alguma tiver sido apagada, cria-se (e apaga-se depois).
        $categorias = [];
        foreach (['Portagens e estacionamento', 'Alojamento', 'Refeições', 'Material', 'Combustíveis'] as $nome) {
            $c = CategoriaDespesaTempo::whereRaw('lower(nome) = lower(?)', [$nome])->first();
            if (! $c) {
                $c = CategoriaDespesaTempo::create(['nome' => $nome]);
                $this->criados['categorias_despesa_tempos'][] = $c->id;
            }
            $categorias[] = $c;
        }

        // categoria, valor em cêntimos, nota, faturável, estado
        $dados = [
            [0, 1320, 'Portagens — Maia', true, 'aprovada'],
            [2, 1850, 'Almoço em deslocação', false, 'aprovada'],
            [1, 8900, 'Hotel, 1 noite — instalação data center', true, 'pendente'],
            [3, 12640, 'Baterias 12V 9Ah (4 un.)', true, 'aprovada'],
            [0, 2750, 'Portagens A1 e estacionamento', true, 'pendente'],
            [2, 2400, 'Jantar em deslocação', false, 'rejeitada'],
            [3, 3590, 'Cabos e terminais', true, 'pendente'],
            [4, 6100, 'Combustível — semana de instalação', true, 'aprovada'],
            [1, 9500, 'Hotel, 1 noite — Porto', true, 'pendente'],
            [3, 1590, 'Fita isoladora e abraçadeiras', false, 'aprovada'],
        ];

        $ativos = array_values(array_filter($projetos, fn (array $p) => ! $p['projeto']->estaArquivado()));
        foreach ($dados as $i => [$cat, $valor, $nota, $faturavel, $estado]) {
            $d = new DespesaTempo;
            $d->utilizador_id = $pessoas[$i % $pessoas->count()]->id;
            $d->data = $agora->subDays(mt_rand(1, 6 * 7))->toDateString();
            $d->projeto_id = $ativos[$i % count($ativos)]['projeto']->id;
            $d->categoria_id = $categorias[$cat]->id;
            $d->valor_cent = $valor;
            $d->faturavel = $faturavel;
            $d->nota = $nota;
            $d->estado = $estado;
            if ($estado !== 'pendente') {
                $d->decidido_por = $admin->id;
                $d->decidido_em = $agora->subDays(mt_rand(0, 3));
                $d->motivo_rejeicao = $estado === 'rejeitada' ? 'Sem recibo.' : null;
            }
            $d->criado_por = $d->utilizador_id;
            $d->alterado_por = $d->utilizador_id;
            $d->save();
            $this->criados['despesas_tempos'][] = $d->id;
        }
    }

    /**
     * @param  Collection<int, User>  $pessoas
     * @param  list<array{projeto: ProjetoTempo, cliente: int, descricoes: list<string>}>  $projetos
     */
    private function criarAtribuicoes(Collection $pessoas, array $projetos, User $admin, CarbonImmutable $agora): void
    {
        $segunda = $agora->startOfWeek();
        $ativos = array_values(array_filter($projetos, fn (array $p) => ! $p['projeto']->estaArquivado()));

        // pessoa, projeto, semana de início (0 = esta), dias, horas/dia, nota
        $dados = [
            [0, 0, 0, 5, 4, 'Manutenções do trimestre'],
            [1, 3, 0, 10, 8, 'Instalação no data center — semanas 1 e 2'],
            [2, 2, 1, 5, 2, 'Piquete de suporte remoto'],
            [0, 1, 1, 3, 6, 'Substituição de baterias — bloco B'],
            [3, 3, 2, 5, 8, 'Instalação no data center — arranque'],
        ];

        foreach ($dados as [$p, $proj, $semana, $dias, $horas, $nota]) {
            $a = new AtribuicaoTempo;
            $a->utilizador_id = $pessoas[$p % $pessoas->count()]->id;
            $a->projeto_id = $ativos[$proj % count($ativos)]['projeto']->id;
            $a->de = $segunda->addWeeks($semana)->toDateString();
            $a->ate = $segunda->addWeeks($semana)->addDays($dias - 1 + ($dias > 5 ? 2 : 0))->toDateString();
            $a->horas_dia_seg = $horas * 3600;
            $a->fins_de_semana = false;
            $a->nota = $nota;
            $a->criado_por = $admin->id;
            $a->alterado_por = $admin->id;
            $a->save();
            $this->criados['atribuicoes_tempos'][] = $a->id;
        }
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\EnviarRelatoriosPartilhados;
use App\Livewire\Relatorios\Partilhado;
use App\Livewire\Relatorios\Partilhados;
use App\Livewire\Relatorios\Resumo;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Models\RelatorioPartilhado;
use App\Models\User;
use App\Notifications\RelatorioPartilhadoEmail;
use App\Services\Tempos\GestorPartilhados;
use App\Services\Tempos\ResumoTempos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

// Partilhar o Resumo por link (como o "Share report" do Clockify): nome, visibilidade, período sempre
// atual, bloquear datas e envio por email; página pública só de leitura com as permissões de quem
// partilhou; lista em Partilhados; envio agendado.
class RelatoriosPartilhadosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private Cliente $hospital;

    private GestorPartilhados $gestor;

    private array $parametros = [
        'tipo' => 'semana', 'inicio' => '2026-09-07', 'fim' => '', 'membros' => [], 'clientes' => [], 'projetos' => [],
        'etiquetas' => [], 'estado' => '', 'descricao' => '', 'agrupar1' => 'membro', 'agrupar2' => '', 'cor' => 'faturabilidade',
        'mostrarValor' => 'faturavel', 'ordem' => '-duracao', 'estimativa' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; esta semana 14/09–20/09

        $this->admin = $this->admin();
        $this->admin->update(['nome' => 'Suporte Nexus']);
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->rui = $this->tecnico();
        $this->rui->update(['nome' => 'Rui Costa']);
        $this->hospital = $this->cliente('Hospital');
        $this->gestor = app(GestorPartilhados::class);

        $obra = ProjetoTempo::create(['nome' => 'Obra', 'cliente_id' => ClienteTempo::create(['nome' => 'Hospital'])->id]);
        $this->registo($this->ana, $this->hospital, '2026-09-08', 3600, ['projeto_id' => $obra->id]);  // semana passada
        $this->registo($this->rui, $this->hospital, '2026-09-08', 7200, ['projeto_id' => $obra->id]);  // semana passada
        $this->registo($this->ana, $this->hospital, '2026-09-15', 1800, ['projeto_id' => $obra->id]);  // esta semana
    }

    private function partilhar(User $quem, array $dados = [], array $parametros = []): RelatorioPartilhado
    {
        return $this->gestor->criar($quem, 'resumo', $dados + ['nome' => 'Horas'], $parametros + $this->parametros);
    }

    private function erros(callable $acao): array
    {
        try {
            $acao();
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }
        $this->fail('Esperava erros de validação.');
    }

    public function test_criar_valida_e_audita_e_so_o_autor_ou_quem_gere_altera(): void
    {
        $r = $this->partilhar($this->ana, ['nome' => '  Horas   da Ana ', 'email_ativo' => true, 'email_destinatarios' => 'A@x.pt; b@x.pt, a@x.pt', 'email_hora' => '9']);

        $this->assertSame(40, strlen($r->token));
        $this->assertSame(['Horas da Ana', true, true, false, ['a@x.pt', 'b@x.pt'], 9], [$r->nome, $r->publico, $r->sempre_atual, $r->bloquear_datas, $r->email_destinatarios, $r->email_hora]);
        $this->assertSame(['nome' => 'Horas da Ana', 'publico' => true], Auditoria::where('acao', 'tempo_relatorio_partilhado')->sole()->detalhe);

        $this->assertSame(['nome' => 'O nome tem de ter entre 2 e 250 caracteres.'], $this->erros(fn () => $this->partilhar($this->ana, ['nome' => 'x'])));
        $this->assertSame(['email_destinatarios' => 'Indique pelo menos um email.'], $this->erros(fn () => $this->partilhar($this->ana, ['email_ativo' => true, 'email_destinatarios' => ''])));
        $this->assertSame(['email_destinatarios' => 'Email inválido: nada.'], $this->erros(fn () => $this->partilhar($this->ana, ['email_destinatarios' => 'nada'])));
        $this->assertSame(['email_frequencia' => 'Escolha a frequência.', 'email_hora' => 'Escolha a hora.'], $this->erros(fn () => $this->partilhar($this->ana, ['email_frequencia' => 'anual', 'email_hora' => 30])));
        $this->assertSame(['email_destinatarios' => 'No máximo 10 destinatários.'], $this->erros(fn () => $this->partilhar($this->ana, ['email_destinatarios' => implode(',', array_map(fn ($n) => "p$n@x.pt", range(1, 11)))])));

        // Datas à escolha nunca são "sempre atuais".
        $this->assertFalse($this->partilhar($this->ana, ['sempre_atual' => true], ['tipo' => 'datas', 'inicio' => '2026-09-01', 'fim' => '2026-09-10'])->sempre_atual);

        // Alterar, novo link e apagar: o autor ou quem gere a equipa.
        $antigo = $r->token;
        $this->gestor->novoLink($this->admin, $r);
        $this->assertNotSame($antigo, $r->fresh()->token);
        $this->gestor->atualizar($this->ana, $r, ['publico' => false]);
        $this->assertFalse($r->fresh()->publico);

        $this->expectException(AuthorizationException::class);
        $this->gestor->apagar($this->rui, $r);
    }

    public function test_periodo_sempre_atual_ou_fixo(): void
    {
        $atual = $this->partilhar($this->ana);
        $this->assertSame(['tipo' => 'semana', 'inicio' => '2026-09-14', 'fim' => ''], $atual->periodo());
        $this->assertSame('Esta semana', $atual->rotuloPeriodo());

        $fixo = $this->partilhar($this->ana, ['sempre_atual' => false]);
        $this->assertSame(['tipo' => 'semana', 'inicio' => '2026-09-07', 'fim' => ''], $fixo->periodo());
        $this->assertSame('07/09/2026 – 13/09/2026', $fixo->rotuloPeriodo());

        $mes = $this->partilhar($this->ana, [], ['tipo' => 'mes', 'inicio' => '2026-08-01']);
        $this->assertSame('2026-09-01', $mes->periodo()['inicio']);
    }

    public function test_criar_a_partir_do_resumo(): void
    {
        $pagina = Livewire::actingAs($this->admin)->withQueryParams(['clientes' => [(string) $this->hospital->id], 'agrupar' => 'membro'])->test(Resumo::class)
            ->assertSeeHtml('aria-label="Partilhar"')
            ->call('abrirPartilha')
            ->assertSee('Partilhar relatório')
            ->assertSee('Abrir sempre em «Esta semana»')
            ->assertSet('partilha.email_destinatarios', $this->admin->email)
            ->set('partilha.nome', 'a')
            ->call('guardarPartilha')
            ->assertHasErrors('partilha.nome')
            ->set('partilha.nome', 'Semana da equipa')
            ->set('partilha.publico', '0')
            ->set('partilha.bloquear_datas', true)
            ->set('partilha.email_ativo', true)
            ->assertSee('Todas as segundas-feiras')
            ->call('guardarPartilha')
            ->assertHasNoErrors()
            ->assertSee('Link criado.')
            ->assertSee('Ver em Partilhados');

        $r = RelatorioPartilhado::sole();
        $this->assertSame($r->url(), $pagina->get('linkCriado'));
        $this->assertSame([false, true, true, true, [$this->admin->email]], [$r->publico, $r->sempre_atual, $r->bloquear_datas, $r->email_ativo, $r->email_destinatarios]);
        $this->assertSame([[(string) $this->hospital->id], 'membro', '2026-09-14'], [$r->parametros['clientes'], $r->parametros['agrupar1'], $r->parametros['inicio']]);
        $this->assertSame($this->admin->id, $r->criado_por);
    }

    public function test_pagina_publica_so_de_leitura_com_as_permissoes_de_quem_partilhou(): void
    {
        // Admin partilha a semana passada da equipa, com datas bloqueadas.
        $equipa = $this->partilhar($this->admin, ['nome' => 'Equipa', 'sempre_atual' => false, 'bloquear_datas' => true]);

        $this->get('/partilhado/'.$equipa->token)
            ->assertOk()
            ->assertSee('<title>Equipa — Nexus Suporte</title>', false)
            ->assertSee('partilhado por Suporte Nexus')
            ->assertSee('3:00:00')
            ->assertSee('Rui Costa')
            ->assertDontSee('aria-label="Partilhar"', false)
            ->assertDontSee('Filtros');

        // Quem abre não muda filtros, período bloqueado nem o valor mostrado.
        Livewire::withQueryParams(['membros' => [(string) $this->ana->id], 'de' => '2026-09-14', 'valor' => 'custo'])
            ->test(Partilhado::class, ['token' => $equipa->token])
            ->assertSet('membros', [])
            ->assertSet('inicio', '2026-09-07')
            ->assertSet('mostrarValor', 'faturavel')
            ->assertSee('3:00:00')
            ->call('seguinte')
            ->assertSet('inicio', '2026-09-07')
            ->set('agrupar1', 'cliente')
            ->assertSee('Hospital')
            ->call('exportar')
            ->assertFileDownloaded('resumo-20260907-20260913.csv');

        // Sem bloquear: pode navegar; "sempre atual" abre nesta semana.
        $livre = $this->partilhar($this->admin, ['nome' => 'Livre']);
        Livewire::test(Partilhado::class, ['token' => $livre->token])
            ->assertSet('inicio', '2026-09-14')
            ->assertSee('0:30:00')
            ->call('anterior')
            ->assertSet('inicio', '2026-09-07')
            ->assertSee('3:00:00');

        // Um técnico só partilha as suas horas.
        $daAna = $this->partilhar($this->ana, ['nome' => 'Da Ana', 'sempre_atual' => false]);
        $this->get('/partilhado/'.$daAna->token)->assertOk()->assertSee('1:00:00')->assertDontSee('Rui Costa');

        // Quem abre o link não cria partilhas.
        Livewire::test(Partilhado::class, ['token' => $livre->token])->call('abrirPartilha')->assertForbidden();
    }

    public function test_privado_link_invalido_e_autor_sem_acesso(): void
    {
        $privado = $this->partilhar($this->admin, ['nome' => 'Privado', 'publico' => false]);

        $this->get('/partilhado/'.$privado->token)->assertRedirect(config('app.portal_url'));
        $this->actingAs($this->utilizador(null))->get('/partilhado/'.$privado->token)->assertForbidden();
        $this->actingAs($this->rui)->get('/partilhado/'.$privado->token)->assertOk()->assertSee('Privado');
        auth()->logout();

        $this->get('/partilhado/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/partilhado/curto')->assertNotFound();

        $publico = $this->partilhar($this->ana, ['nome' => 'Da Ana']);
        $this->ana->update(['ativo' => false]);
        $this->get('/partilhado/'.$publico->token)->assertNotFound();
    }

    public function test_pagina_partilhados_lista_altera_e_apaga(): void
    {
        $daAna = $this->partilhar($this->ana, ['nome' => 'Relatório da Ana']);
        $doAdmin = $this->partilhar($this->admin, ['nome' => 'Relatório do admin', 'email_ativo' => true, 'email_destinatarios' => 'x@y.pt']);

        $this->actingAs($this->ana)->get('/relatorios/partilhados')->assertOk()->assertSee('Partilhados — Nexus Suporte', false);

        Livewire::actingAs($this->ana)->test(Partilhados::class)
            ->assertSee('Relatório da Ana')
            ->assertDontSee('Relatório do admin')
            ->assertDontSee('Criado por')
            ->call('editar', $daAna->id)
            ->set('formulario.nome', 'Renomeado')
            ->set('formulario.publico', '0')
            ->call('guardar')
            ->assertSee('Relatório guardado.')
            ->assertSee('Renomeado')
            ->assertSee('Privado')
            ->call('novoLink', $daAna->id)
            ->assertSee('Novo link criado.')
            ->call('apagar', $daAna->id)
            ->assertSee('Relatório partilhado apagado.')
            ->assertSee('Ainda sem relatórios partilhados');

        Livewire::actingAs($this->admin)->test(Partilhados::class)
            ->assertSee('Relatório do admin')
            ->assertSee('Criado por')
            ->assertSee('1 destinatário')
            ->set('pesquisa', 'nada')
            ->assertSee('Nenhum relatório com esse nome');

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->rui)->test(Partilhados::class)->call('apagar', $doAdmin->id);
    }

    public function test_envio_por_email_na_hora_e_no_dia_certos(): void
    {
        Notification::fake();
        $semanal = $this->partilhar($this->admin, ['nome' => 'Semanal', 'email_ativo' => true, 'email_destinatarios' => 'a@x.pt, b@x.pt', 'email_hora' => 9, 'email_frequencia' => 'semanal', 'sempre_atual' => false]);
        $diario = $this->partilhar($this->admin, ['nome' => 'Diário', 'email_ativo' => true, 'email_destinatarios' => 'c@x.pt', 'email_hora' => 9, 'email_frequencia' => 'diaria']);
        $this->partilhar($this->admin, ['nome' => 'Outra hora', 'email_ativo' => true, 'email_destinatarios' => 'd@x.pt', 'email_hora' => 7, 'email_frequencia' => 'diaria']);

        // Quinta, 09h de Lisboa: só o diário.
        Carbon::setTestNow('2026-09-17 08:05:00'); // UTC
        (new EnviarRelatoriosPartilhados)->handle(app(ResumoTempos::class));
        Notification::assertSentOnDemandTimes(RelatorioPartilhadoEmail::class, 1);
        Notification::assertSentOnDemand(RelatorioPartilhadoEmail::class, function (RelatorioPartilhadoEmail $n, array $canais, AnonymousNotifiable $quem) {
            return $quem->routes['mail'] === ['c@x.pt'] && $n->nome === 'Diário' && $n->periodo === '14/09/2026 – 20/09/2026' && $n->total === 1800;
        });

        // Outra vez no mesmo dia: nada.
        (new EnviarRelatoriosPartilhados)->handle(app(ResumoTempos::class));
        Notification::assertSentOnDemandTimes(RelatorioPartilhadoEmail::class, 1);

        // Segunda seguinte, 09h: o semanal (período guardado) e o diário.
        Carbon::setTestNow('2026-09-21 08:10:00');
        (new EnviarRelatoriosPartilhados)->handle(app(ResumoTempos::class));
        Notification::assertSentOnDemandTimes(RelatorioPartilhadoEmail::class, 3);
        Notification::assertSentOnDemand(RelatorioPartilhadoEmail::class, fn (RelatorioPartilhadoEmail $n, array $c, AnonymousNotifiable $quem) => $n->nome === 'Semanal'
            && $quem->routes['mail'] === ['a@x.pt', 'b@x.pt'] && $n->total === 10800 && $n->valor === 0
            && array_column($n->grupos, 'nome') === ['Rui Costa', 'Ana Martins']);
        $this->assertNotNull($semanal->fresh()->email_enviado_em);
        $this->assertNotNull($diario->fresh()->email_enviado_em);
    }

    // ---- Blindagem do link (notas §40): quem abre o link a mandar pedidos à mão, como um atacante.

    /** Um segundo cliente, com horas na mesma semana, que nunca pode aparecer num link filtrado ao Hospital. */
    private function outroCliente(): void
    {
        $cofre = ProjetoTempo::create(['nome' => 'Cofre', 'cliente_id' => ClienteTempo::create(['nome' => 'Banco'])->id]);
        $this->registo($this->rui, $this->hospital, '2026-09-09', 5400, ['projeto_id' => $cofre->id, 'descricao' => 'Trabalho do banco']);
    }

    private function linkSoDoHospital(): RelatorioPartilhado
    {
        $hospital = ClienteTempo::where('nome', 'Hospital')->value('id');

        return $this->partilhar($this->admin, ['nome' => 'Só Hospital', 'sempre_atual' => false, 'bloquear_datas' => true],
            ['clientes' => [(string) $hospital], 'agrupar1' => 'projeto']);
    }

    public function test_link_nao_deixa_limpar_filtros_nem_chamar_acoes_herdadas(): void
    {
        $this->outroCliente();
        $link = $this->linkSoDoHospital();

        $this->get('/partilhado/'.$link->token)->assertOk()->assertSee('3:00:00')->assertDontSee('Cofre');

        // O «Limpar filtros» herdado do Resumo abria o relatório a todos os clientes.
        Livewire::test(Partilhado::class, ['token' => $link->token])->call('limparFiltros')->assertForbidden();

        // Lista fechada: TUDO o que o Livewire deixaria chamar e não está em ACOES dá 403 — incluindo o
        // que o Resumo venha a ganhar no futuro (este teste apanha-o).
        $instancia = Livewire::test(Partilhado::class, ['token' => $link->token])->instance();
        $chamaveis = array_diff(Utils::getPublicMethodsDefinedBySubClass($instancia), ['render'], Partilhado::ACOES);
        $this->assertContains('limparFiltros', $chamaveis);
        $this->assertContains('guardarPartilha', $chamaveis);
        foreach ($chamaveis as $metodo) {
            $argumentos = array_map(fn (ReflectionParameter $p) => match (true) {
                $p->isDefaultValueAvailable() => $p->getDefaultValue(),
                $p->allowsNull() => null,
                default => match ((string) $p->getType()) {
                    'int' => 0, 'float' => 0.0, 'bool' => false, 'array' => [], default => '',
                },
            }, (new ReflectionMethod($instancia, $metodo))->getParameters());

            Livewire::test(Partilhado::class, ['token' => $link->token])->call($metodo, ...$argumentos)->assertForbidden();
        }

        // As ações da lista continuam a funcionar.
        Livewire::test(Partilhado::class, ['token' => $link->token])
            ->call('ordenarPor', 'titulo')->assertOk()
            ->call('exportar')->assertFileDownloaded('resumo-20260907-20260913.csv');
    }

    public function test_link_calcula_sempre_com_os_filtros_do_autor_mesmo_com_as_propriedades_mexidas(): void
    {
        $this->outroCliente();
        $link = $this->linkSoDoHospital();
        $hospital = ClienteTempo::where('nome', 'Hospital')->value('id');

        // Mudar os filtros pelo browser não passa (é reposto a cada alteração)...
        Livewire::test(Partilhado::class, ['token' => $link->token])
            ->set('clientes', [])->set('membros', [(string) $this->ana->id])
            ->assertSee('3:00:00')->assertDontSee('Cofre');

        // ... e mesmo que alguma via deixe as propriedades vazias, os cálculos não as usam: vêm do que
        // o autor guardou (segunda linha de defesa).
        $instancia = Livewire::test(Partilhado::class, ['token' => $link->token])->instance();
        $instancia->clientes = [];
        $instancia->projetos = [];
        $instancia->membros = [];
        $filtros = (fn () => $this->filtrosDoServico())->call($instancia);
        $this->assertSame([(int) $hospital], $filtros['clientes']);
        $this->assertNull($filtros['membros'], 'o autor vê a equipa e não filtrou pessoas');
    }

    public function test_link_nunca_mostra_custo_nem_lucro(): void
    {
        // O admin partilha com o seletor em «Lucro»: guarda-se o faturável.
        $comLucro = $this->partilhar($this->admin, ['nome' => 'Margem'], ['mostrarValor' => 'lucro']);
        $this->assertSame('faturavel', $comLucro->fresh()->parametros['mostrarValor']);
        $this->assertSame('faturavel', $this->gestor->atualizar($this->admin, $comLucro, [], ['mostrarValor' => 'custo'] + $this->parametros)->fresh()->parametros['mostrarValor']);

        // Um link antigo com «Custo» gravado mostra só o faturável.
        $antigo = $this->partilhar($this->admin, ['nome' => 'Antigo']);
        $antigo->forceFill(['parametros' => ['mostrarValor' => 'custo'] + $this->parametros])->save();
        $this->get('/partilhado/'.$antigo->token)->assertOk()
            ->assertSee('>Valor</span>', false)->assertDontSee('>Custo</span>', false)->assertDontSee('>Lucro</span>', false);
        Livewire::test(Partilhado::class, ['token' => $antigo->token])
            ->assertSet('mostrarValor', 'faturavel')
            ->set('mostrarValor', 'lucro')->assertSet('mostrarValor', 'faturavel');

        // «Sem valor» guardado continua sem valor.
        $semValor = $this->partilhar($this->admin, ['nome' => 'Sem valor'], ['mostrarValor' => 'nao']);
        Livewire::test(Partilhado::class, ['token' => $semValor->token])->assertSet('mostrarValor', 'nao');
    }

    public function test_link_limita_as_acoes_e_as_exportacoes(): void
    {
        $link = $this->partilhar($this->admin, ['nome' => 'Limites']);
        $pagina = Livewire::test(Partilhado::class, ['token' => $link->token]);

        for ($i = 0; $i < Partilhado::MAXIMO_EXPORTACOES; $i++) {
            $pagina->call('exportar', 'csv')->assertOk();
        }
        $pagina->call('exportar', 'pdf')->assertStatus(429);

        // O limite é por link: outro link não é afetado.
        $outro = $this->partilhar($this->admin, ['nome' => 'Outro']);
        Livewire::test(Partilhado::class, ['token' => $outro->token])->call('exportar')->assertOk();
    }
}

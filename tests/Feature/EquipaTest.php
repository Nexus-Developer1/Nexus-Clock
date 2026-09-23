<?php

namespace Tests\Feature;

use App\Enums\PapelEquipa;
use App\Jobs\EnviarLembretesEquipa;
use App\Livewire\Equipa\Grupos;
use App\Livewire\Equipa\Lembretes;
use App\Livewire\Equipa\Limitados;
use App\Livewire\Equipa\Membros;
use App\Models\GrupoEquipa;
use App\Models\LembreteEquipa;
use App\Models\MembroEquipa;
use App\Models\User;
use App\Notifications\LembreteHoras;
use App\Services\Tempos\GestorEquipa;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Página Equipa: membros plenos (pessoas com acesso no portal), limitados (criados aqui), papéis ao
// estilo do Clockify (um só proprietário), taxas faturável e de custo com histórico, grupos e
// lembretes por email a quem registou menos horas. Só admin gere; o técnico vê sem taxas.
class EquipaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private GestorEquipa $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00'); // terça-feira, 11:00 em Lisboa

        $this->admin = $this->admin();
        $this->admin->update(['nome' => 'Suporte Nexus']);
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->gestor = app(GestorEquipa::class);
        $this->gestor->sincronizar();
    }

    private function membroDe(User $u): MembroEquipa
    {
        return MembroEquipa::where('utilizador_id', $u->id)->sole();
    }

    public function test_sincronizar_cria_um_membro_por_pessoa_com_acesso_com_papel_do_portal(): void
    {
        $this->utilizador(null); // sem acesso aos Tempos: não entra
        $this->gestor->sincronizar();

        $this->assertSame(2, MembroEquipa::count());
        $this->assertSame(PapelEquipa::Administrador, $this->membroDe($this->admin)->papel);
        $this->assertSame(PapelEquipa::Membro, $this->membroDe($this->ana)->papel);
    }

    public function test_so_ha_um_proprietario_e_limitados_nao_podem_ser(): void
    {
        $bruno = $this->tecnico();
        $this->gestor->sincronizar();

        $this->gestor->mudarPapel($this->admin, $this->membroDe($this->ana), 'proprietario');
        $this->gestor->mudarPapel($this->admin, $this->membroDe($bruno), 'proprietario');

        $this->assertSame(PapelEquipa::Administrador, $this->membroDe($this->ana)->papel);
        $this->assertSame(PapelEquipa::Proprietario, $this->membroDe($bruno)->papel);

        $limitado = $this->gestor->criarLimitado($this->admin, 'Subcontratado');
        $this->expectExceptionMessage('Um membro limitado não pode ser proprietário.');
        $this->gestor->mudarPapel($this->admin, $limitado, 'proprietario');
    }

    public function test_taxas_com_historico_por_data(): void
    {
        $membro = $this->membroDe($this->ana);

        $this->gestor->mudarTaxa($this->admin, $membro, 'faturavel', '45,50', '2026-01-01');
        $this->gestor->mudarTaxa($this->admin, $membro, 'faturavel', '50', '2026-09-01');
        $this->gestor->mudarTaxa($this->admin, $membro, 'custo', '20');
        $this->gestor->mudarTaxa($this->admin, $membro, 'faturavel', '', '2026-12-01'); // sem taxa a partir de dezembro

        $membro->load('taxas');
        $this->assertSame(4550, $membro->taxaEm('faturavel', Carbon::parse('2026-08-31')));
        $this->assertSame(5000, $membro->taxaEm('faturavel', Carbon::parse('2026-09-15')));
        $this->assertNull($membro->taxaEm('faturavel', Carbon::parse('2026-12-02')));
        $this->assertNull($membro->taxaEm('faturavel', Carbon::parse('2025-12-31')));
        $this->assertSame(2000, $membro->taxaEm('custo', Carbon::parse('2026-09-15')));

        try {
            $this->gestor->mudarTaxa($this->admin, $membro, 'custo', 'abc');
            $this->fail('Valor ilegível.');
        } catch (ValidationException $e) {
            $this->assertStringStartsWith('Valor inválido', $e->errors()['valor'][0]);
        }
    }

    public function test_tecnico_nao_gere_a_equipa(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->gestor->mudarTaxa($this->ana, $this->membroDe($this->ana), 'faturavel', '100');
    }

    public function test_grupos_nome_unico_membros_e_apagar_limpa_os_lembretes(): void
    {
        $suporte = $this->gestor->criarGrupo($this->admin, 'Suporte');
        $obras = $this->gestor->criarGrupo($this->admin, 'Obras');

        try {
            $this->gestor->criarGrupo($this->admin, 'suporte');
            $this->fail('Nome repetido.');
        } catch (ValidationException $e) {
            $this->assertSame('Já existe um grupo chamado «suporte».', $e->errors()['nome'][0]);
        }

        $limitado = $this->gestor->criarLimitado($this->admin, 'Subcontratado', 'sub@empresa.pt');
        $this->gestor->definirMembrosDoGrupo($this->admin, $suporte, [$this->membroDe($this->ana)->id, $limitado->id]);
        $this->assertTrue($this->gestor->alternarGrupo($this->admin, $this->membroDe($this->admin), $suporte));
        $this->assertFalse($this->gestor->alternarGrupo($this->admin, $limitado, $suporte));
        $this->assertSame(2, $suporte->membros()->count());

        $lembrete = $this->gestor->guardarLembrete($this->admin, null, ['destinatarios' => 'grupos', 'grupos' => [$suporte->id, $obras->id], 'periodo' => 'dia', 'horas_minimas' => '8', 'dias' => [1, 2, 3, 4, 5], 'hora' => 9]);
        $this->gestor->apagarGrupo($this->admin, $obras);

        $this->assertSame([$suporte->id], $lembrete->fresh()->grupos);
    }

    public function test_lembrete_valida_os_campos(): void
    {
        try {
            $this->gestor->guardarLembrete($this->admin, null, ['destinatarios' => 'grupos', 'grupos' => [], 'periodo' => 'dia', 'horas_minimas' => '30', 'dias' => [], 'hora' => 25]);
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame(['grupos', 'horas_minimas', 'dias', 'hora'], array_keys($e->errors()));
        }
    }

    public function test_lembrete_avisa_so_quem_registou_menos_do_minimo_no_dia_anterior_uma_vez_por_dia(): void
    {
        Notification::fake();
        $bruno = $this->tecnico();
        $this->gestor->sincronizar();
        $cliente = $this->cliente();
        $this->registo($this->ana, $cliente, '2026-09-14', 5 * 3600);
        $this->registo($bruno, $cliente, '2026-09-14', 8 * 3600);

        $this->gestor->guardarLembrete($this->admin, null, ['destinatarios' => 'todos', 'periodo' => 'dia', 'horas_minimas' => '8', 'dias' => [2], 'hora' => 11]);

        (new EnviarLembretesEquipa)->handle();
        (new EnviarLembretesEquipa)->handle(); // segunda vez na mesma hora: não repete

        Notification::assertSentToTimes($this->ana, LembreteHoras::class, 1);
        Notification::assertSentTo($this->admin, LembreteHoras::class, fn ($n) => $n->segundos === 0 && $n->periodo === 'dia 14/09');
        Notification::assertNotSentTo($bruno, LembreteHoras::class);

        $html = (new LembreteHoras('dia 14/09', 5 * 3600, 8 * 3600))->toMail($this->ana)->render();
        $this->assertStringContainsString('registou <strong style="color:#111827;">5:00</strong> horas', $html);
    }

    public function test_lembrete_de_grupo_fora_da_hora_ou_desligado_nao_sai(): void
    {
        Notification::fake();
        $grupo = $this->gestor->criarGrupo($this->admin, 'Suporte');
        $this->gestor->definirMembrosDoGrupo($this->admin, $grupo, [$this->membroDe($this->ana)->id]);

        $this->gestor->guardarLembrete($this->admin, null, ['destinatarios' => 'grupos', 'grupos' => [$grupo->id], 'periodo' => 'semana', 'horas_minimas' => '40', 'dias' => [2], 'hora' => 12]);
        (new EnviarLembretesEquipa)->handle();
        Notification::assertNothingSent();

        Carbon::setTestNow('2026-09-15 11:05:00'); // 12:05 em Lisboa
        LembreteEquipa::query()->update(['ativo' => false]);
        (new EnviarLembretesEquipa)->handle();
        Notification::assertNothingSent();

        LembreteEquipa::query()->update(['ativo' => true]);
        (new EnviarLembretesEquipa)->handle();
        Notification::assertSentTo($this->ana, LembreteHoras::class, fn ($n) => $n->periodo === 'semana de 07/09 a 13/09');
        Notification::assertNotSentTo($this->admin, LembreteHoras::class);

        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'tempos-lembretes-equipa');
        $this->assertSame('0 * * * *', $evento->expression);
    }

    public function test_campos_de_trabalho_inicio_da_semana_dias_capacidade_e_gestor(): void
    {
        $ana = $this->membroDe($this->ana);
        $gestor = $this->membroDe($this->admin);

        $this->gestor->atualizarCampo($this->admin, $ana, 'inicio_semana', '7');
        $this->gestor->alternarDiaTrabalho($this->admin, $ana, 6);
        $this->gestor->alternarDiaTrabalho($this->admin, $ana, 1);
        $this->gestor->atualizarCampo($this->admin, $ana, 'capacidade', '7:30');

        $ana->refresh();
        $this->assertSame([7, [2, 3, 4, 5, 6], 27000, 'Ter–Sáb'], [$ana->inicio_semana, $ana->dias_trabalho, $ana->capacidade_diaria_seg, $ana->rotuloDiasTrabalho()]);

        $casos = [
            ['inicio_semana', '9', 'Escolha o dia em que começa a semana.'],
            ['dias_trabalho', [], 'Tem de haver pelo menos um dia de trabalho.'],
            ['gestor_id', (string) $gestor->id, 'O gestor atribuído tem de ter o papel Gestor de equipa.'],
        ];
        foreach ($casos as [$campo, $valor, $mensagem]) {
            try {
                $this->gestor->atualizarCampo($this->admin, $ana->fresh(), $campo, $valor);
                $this->fail('Devia recusar '.$campo);
            } catch (ValidationException $e) {
                $this->assertSame($mensagem, $e->errors()[$campo][0]);
            }
        }

        $this->gestor->mudarPapel($this->admin, $gestor, 'gestor_equipa');
        $this->gestor->atualizarCampo($this->admin, $ana->fresh(), 'gestor_id', (string) $gestor->id);
        $this->assertSame($gestor->id, $ana->fresh()->gestor_id);

        try {
            $this->gestor->atualizarCampo($this->admin, $gestor->fresh(), 'gestor_id', (string) $gestor->id);
            $this->fail('Não pode ser gestor de si próprio.');
        } catch (ValidationException $e) {
            $this->assertSame('Uma pessoa não pode ser a própria gestora de equipa.', $e->errors()['gestor_id'][0]);
        }

        // Deixar de ser gestor de equipa tira a atribuição.
        $this->gestor->mudarPapel($this->admin, $gestor->fresh(), 'administrador');
        $this->assertNull($ana->fresh()->gestor_id);
    }

    public function test_menu_filtros_mostra_campos_como_filtro_e_coluna_e_filtra_por_eles(): void
    {
        $bruno = $this->tecnico();
        $bruno->update(['nome' => 'Bruno Costa']);
        $this->gestor->sincronizar();
        $this->gestor->alternarDiaTrabalho($this->admin, $this->membroDe($bruno), 6);
        $this->gestor->atualizarCampo($this->admin, $this->membroDe($bruno), 'capacidade', '8');

        $pagina = Livewire::actingAs($this->admin)->test(Membros::class)
            ->assertSee('Taxa faturável (€/h)')
            ->assertDontSeeHtml('aria-label="Capacidade diária"')
            ->assertDontSee('Trabalha à segunda')
            ->call('alternarCampo', 'capacidade')
            ->call('alternarCampo', 'dias_trabalho')
            ->call('alternarCampo', 'faturavel')
            ->assertSeeHtml('aria-label="Capacidade diária"')
            ->assertSee('Trabalha à sábado')
            ->assertDontSee('Taxa faturável (€/h)')
            ->assertSee('Seg–Sáb')
            ->set('filtroDia', '6')
            ->assertSee('Bruno Costa')->assertDontSee('Ana Martins')
            ->set('filtroDia', '')
            ->set('filtroCapacidade', 'sem')
            ->assertSee('Ana Martins')->assertDontSee('Bruno Costa')
            ->call('alternarCampo', 'capacidade') // esconder limpa o filtro
            ->assertSet('filtroCapacidade', '')
            ->assertSee('Bruno Costa')
            ->call('mudarCampo', $this->membroDe($this->ana)->id, 'capacidade', 'uma hora')
            ->assertSee('Não percebi a duração «uma hora»')
            ->call('camposPorOmissao')
            ->assertSet('campos', Membros::CAMPOS_POR_OMISSAO);

        $this->assertSame(['faturavel', 'custo', 'papel', 'grupo'], session('equipa-campos'));
    }

    // --- Páginas ---

    public function test_pagina_membros_muda_taxa_papel_e_grupo_e_filtra(): void
    {
        $grupo = $this->gestor->criarGrupo($this->admin, 'Suporte');
        $ana = $this->membroDe($this->ana);

        Livewire::actingAs($this->admin)->test(Membros::class)
            ->assertSee('Ana Martins')->assertSee('Suporte Nexus')->assertSee('(você)')
            ->call('abrirTaxa', $ana->id, 'faturavel')
            ->assertSee('Taxa faturável')
            ->set('taxaValor', '45,5')->call('guardarTaxa')
            ->assertSee('Taxa faturável guardada.')
            ->assertSee('45,50')
            ->call('mudarPapel', $ana->id, 'gestor_equipa')
            ->assertSee('Ana Martins passa a Gestor de equipa.')
            ->call('alternarGrupo', $ana->id, $grupo->id)
            ->set('filtroGrupo', (string) $grupo->id)
            ->assertSee('Ana Martins')->assertDontSee('Suporte Nexus')
            ->set('filtroGrupo', '')
            ->set('filtroFaturavel', 'sem')
            ->assertDontSee('Ana Martins')
            ->set('filtroFaturavel', '')
            ->set('pesquisa', $this->admin->email)
            ->assertSee('Suporte Nexus')->assertDontSee('Ana Martins');

        $this->assertSame([PapelEquipa::GestorEquipa, [$grupo->id]], [$ana->fresh()->papel, $ana->fresh()->grupos->pluck('id')->all()]);
    }

    public function test_pagina_limitados_acrescenta_altera_e_apaga(): void
    {
        $pagina = Livewire::actingAs($this->admin)->test(Limitados::class)
            ->assertSee('Sem membros limitados')
            ->set('novoNome', '')->call('acrescentarLimitado')->assertHasErrors('novo.nome')
            ->set('novoNome', 'João Subcontratado')->set('novoEmail', 'joao@empresa.pt')->call('acrescentarLimitado')
            ->assertSee('Membro limitado «João Subcontratado» acrescentado.')
            ->assertDontSee('Ana Martins');

        $joao = MembroEquipa::limitados()->sole();
        $pagina->call('editarLimitado', $joao->id)
            ->set('editarNome', 'João Silva')->call('guardarLimitado')
            ->assertSee('Membro guardado.')->assertSee('João Silva')
            ->call('apagarLimitado', $joao->id)
            ->assertSee('Membro «João Silva» apagado.');

        $this->assertSoftDeleted($joao);
    }

    public function test_pagina_grupos_cria_e_escolhe_membros(): void
    {
        Livewire::actingAs($this->admin)->test(Grupos::class)
            ->assertSee('Ainda sem grupos')
            ->set('novoNome', 'Suporte')->call('acrescentar')
            ->assertSee('Alterar grupo')
            ->set('membros', [(string) $this->membroDe($this->ana)->id])
            ->set('nome', 'Suporte técnico')
            ->call('guardar')
            ->assertSee('Grupo guardado.')
            ->assertSee('Suporte técnico')
            ->assertSee('Ana Martins');

        $this->assertSame(['Ana Martins'], GrupoEquipa::sole()->membros->map->nomeVisivel()->all());
    }

    public function test_pagina_lembretes_cria_e_desliga(): void
    {
        Livewire::actingAs($this->admin)->test(Lembretes::class)
            ->assertSee('Ainda sem lembretes')
            ->call('novo')
            ->set('formulario.horas_minimas', '7,5')
            ->call('guardar')
            ->assertSee('Lembrete guardado.')
            ->assertSee('Menos de 7,5 h no dia anterior')
            ->assertSee('Seg–Sex às 09:00');

        $lembrete = LembreteEquipa::sole();
        Livewire::actingAs($this->admin)->test(Lembretes::class)->call('alternar', $lembrete->id)->assertSee('Desligado');
        $this->assertFalse($lembrete->fresh()->ativo);
    }

    public function test_tecnico_ve_a_equipa_e_os_lembretes_sem_taxas_nem_acoes(): void
    {
        $this->actingAs($this->ana)->get(route('equipa'))->assertOk()
            ->assertSee('Suporte Nexus')
            ->assertDontSee('Taxa faturável')->assertDontSee('Mudar')->assertDontSee('Exportar CSV');

        // Lembretes: vê a lista (antes dava 403 — notas §36), mas sem criar, alterar, desligar nem apagar.
        $lembrete = app(GestorEquipa::class)->guardarLembrete($this->admin, null, ['destinatarios' => 'todos', 'grupos' => [], 'periodo' => 'dia', 'horas_minimas' => '8', 'dias' => ['1', '2', '3', '4', '5'], 'hora' => '9', 'ativo' => true]);
        $this->actingAs($this->ana)->get(route('equipa.lembretes'))->assertOk()
            ->assertSee('Menos de 8 h no dia anterior')->assertSee('Ativo')
            ->assertDontSee('Novo lembrete')->assertDontSee('Alterar lembrete')->assertDontSee('Apagar lembrete');
        Livewire::actingAs($this->ana)->test(Lembretes::class)->call('novo')->assertForbidden();
        Livewire::actingAs($this->ana)->test(Lembretes::class)->call('editar', $lembrete->id)->assertForbidden();
        Livewire::actingAs($this->ana)->test(Lembretes::class)->call('alternar', $lembrete->id)->assertSee('Não tem permissão para gerir lembretes.');
        $this->assertTrue($lembrete->fresh()->ativo);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Painel\Pagina as Painel;
use App\Livewire\Tempos\Calendario;
use App\Models\MembroEquipa;
use App\Models\User;
use App\Services\Tempos\GestorDespesas;
use App\Services\Tempos\PainelTempos;
use App\Services\Tempos\Presencas;
use App\Services\Tempos\ResumoTempos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

// Vertentes do portal (notas §76): Administrador (só gere), Administrador e técnico (gere e regista
// horas) e Técnico. Quem é só Administrador gere tudo, mas não aparece como alguém que regista horas.
class VertentesTest extends TestCase
{
    use RefreshDatabase;

    private User $soAdmin;

    private User $ambos;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 21:00:00');

        $this->soAdmin = $this->adminSoVer('suporte@nxs.pt');
        $this->soAdmin->update(['nome' => 'Suporte Nexus']);
        $this->ambos = $this->adminTecnico();
        $this->ambos->update(['nome' => 'Joana Santos']);
        $this->tecnico = $this->tecnico();
        $this->tecnico->update(['nome' => 'Rui Costa']);
    }

    public function test_papeis_permissoes_e_quem_regista_horas(): void
    {
        $this->assertSame(['admin', true, false], [$this->soAdmin->papelTempos(), $this->soAdmin->ehAdminTempos(), $this->soAdmin->registaHoras()]);
        $this->assertSame(['admin_tecnico', true, true], [$this->ambos->papelTempos(), $this->ambos->ehAdminTempos(), $this->ambos->registaHoras()]);
        $this->assertSame(['tecnico', false, true], [$this->tecnico->papelTempos(), $this->tecnico->ehAdminTempos(), $this->tecnico->registaHoras()]);

        // Papel vazio (o que o formulário antigo do portal gravava) vale técnico.
        $vazio = $this->utilizador('');
        $this->assertSame(['tecnico', false, true], [$vazio->papelTempos(), $vazio->ehAdminTempos(), $vazio->registaHoras()]);

        // As duas vertentes de administrador veem tudo; só o Administrador e técnico gere (§77).
        foreach (['tempos-ver-todos', 'tempos-exportar', 'tempos-valores-projetos', 'tempos-ver-despesas'] as $ver) {
            $this->assertTrue(Gate::forUser($this->soAdmin)->allows($ver), $ver);
            $this->assertTrue(Gate::forUser($this->ambos)->allows($ver), $ver);
        }
        foreach (['tempos-editar-todos', 'tempos-fechar-mes', 'tempos-gerir-tarifas', 'tempos-gerir-equipa', 'tempos-gerir-despesas', 'tempos-gerir-clientes', 'tempos-gerir-projetos'] as $gerir) {
            $this->assertFalse(Gate::forUser($this->soAdmin)->allows($gerir), $gerir);
            $this->assertTrue(Gate::forUser($this->ambos)->allows($gerir), $gerir);
        }
        $this->assertSame([true, false, false], [$this->soAdmin->soVisualiza(), $this->ambos->soVisualiza(), $this->tecnico->soVisualiza()]);
        $this->assertFalse(Gate::forUser($this->tecnico)->allows('tempos-ver-todos'));

        $this->assertEqualsCanonicalizing(
            [$this->ambos->id, $this->tecnico->id, $vazio->id],
            User::queRegistamHoras()->pluck('id')->all()
        );
    }

    public function test_quem_e_so_administrador_fica_fora_das_listas_da_equipa(): void
    {
        $semana = [CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-20')];

        // Presenças: uma linha por pessoa e dia, só de quem regista horas.
        $pessoas = collect(app(Presencas::class)->linhas(null, ...$semana))->pluck('tecnico_id')->unique()->sort()->values()->all();
        $this->assertSame(collect([$this->ambos->id, $this->tecnico->id])->sort()->values()->all(), $pessoas);

        // Atividade da equipa (Painel) e filtro Equipa dos relatórios.
        $this->assertSame(['Joana Santos', 'Rui Costa'], array_column(app(PainelTempos::class)->equipa(...$semana), 'nome'));
        $this->assertSame(['Joana Santos', 'Rui Costa'], array_values(app(ResumoTempos::class)->opcoes($this->soAdmin)['membros']));

        // Calendário: a escolha da pessoa.
        $pessoasDoCalendario = Livewire::actingAs($this->soAdmin)->test(Calendario::class)->viewData('pessoas');
        $this->assertSame(['Joana Santos', 'Rui Costa'], $pessoasDoCalendario->values()->all());
    }

    public function test_quem_so_visualiza_ve_a_equipa_mas_nao_altera_nada(): void
    {
        $registo = $this->registo($this->tecnico, $this->cliente('Hospital'), '2026-09-16', 3600, ['descricao' => 'Do Rui']);

        // Calendário: abre na equipa; não acrescenta tempo; o registo abre só para ler.
        Livewire::actingAs($this->soAdmin)->test(Calendario::class)
            ->assertSet('pessoa', 'equipa')
            ->assertSee('Do Rui')
            ->assertDontSee('Acrescentar tempo')
            ->call('novo', ['dia' => '2026-09-16', 'hora_inicio' => '09:00', 'hora_fim' => '10:00'])
            ->assertSet('editarId', null)
            ->assertSet('erro', 'Como administrador só para visualizar, não acrescenta tempo.')
            ->call('editar', $registo->id)
            ->assertSee('Fechar')
            ->assertDontSee('Guardar')
            ->set('formulario.descricao', 'Mudado')
            ->call('guardar')
            ->assertHasErrors('formulario.geral');

        $this->assertSame('Do Rui', $registo->fresh()->descricao);
        $this->assertFalse($this->soAdmin->can('update', $registo));
        $this->assertFalse($this->soAdmin->can('delete', $registo));

        // Painel: abre na equipa. Cronómetro: fora do menu e o endereço leva ao Painel.
        Livewire::actingAs($this->soAdmin)->test(Painel::class)->assertSet('quem', 'equipa');
        $this->actingAs($this->soAdmin)->get('/')->assertRedirect(route('painel'));
        $this->actingAs($this->soAdmin)->get(route('painel'))->assertOk()->assertDontSee('Cronómetro');

        // Despesas: não lança; mas quem está na lista de aprovação continua a aprovar (notas §44).
        $this->assertFalse(app(GestorDespesas::class)->podeLancar($this->soAdmin));
        $this->actingAs($this->soAdmin)->get(route('relatorios.despesas'))->assertOk()->assertDontSee('Nova despesa');

        // Quem gere continua a acrescentar tempo para si (vem escolhido).
        Livewire::actingAs($this->ambos)->test(Calendario::class)
            ->call('novo', ['dia' => '2026-09-16', 'hora_inicio' => '09:00', 'hora_fim' => '10:00'])
            ->assertSet('formulario.tecnico_id', (string) $this->ambos->id);
        $this->assertTrue($this->ambos->can('update', $registo));
    }

    public function test_quem_so_visualiza_fica_fora_da_lista_da_equipa_e_dos_lembretes(): void
    {
        $this->actingAs($this->ambos)->get(route('equipa'))
            ->assertOk()
            ->assertDontSee('Suporte Nexus')
            ->assertSee('Joana Santos')
            ->assertSee('Rui Costa');

        $this->assertSame(
            collect([$this->ambos->id, $this->tecnico->id])->sort()->values()->all(),
            MembroEquipa::query()->daEquipa()->pluck('utilizador_id')->sort()->values()->all()
        );
    }
}

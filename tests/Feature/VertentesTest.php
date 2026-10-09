<?php

namespace Tests\Feature;

use App\Livewire\Tempos\Calendario;
use App\Models\RegistoTempo;
use App\Models\User;
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

        $this->soAdmin = $this->admin('suporte@nxs.pt');
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

        // As duas vertentes de administrador gerem tudo.
        $this->assertTrue(Gate::forUser($this->soAdmin)->allows('tempos-ver-todos'));
        $this->assertTrue(Gate::forUser($this->ambos)->allows('tempos-editar-todos'));
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

    public function test_quem_e_so_administrador_nao_regista_horas_por_omissao_mas_gere(): void
    {
        // Acrescentar tempo: escolhe o membro; os membros são só quem regista horas.
        Livewire::actingAs($this->soAdmin)->test(Calendario::class)
            ->call('novo', ['dia' => '2026-09-16', 'hora_inicio' => '09:00', 'hora_fim' => '10:00'])
            ->assertSet('formulario.tecnico_id', '')
            ->assertViewHas('membrosDoNovo', fn ($m) => array_values($m) === ['Joana Santos', 'Rui Costa'])
            ->set('formulario.tecnico_id', (string) $this->tecnico->id)
            ->set('formulario.projeto_id', '')
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame(1, RegistoTempo::where('tecnico_id', $this->tecnico->id)->count());
    }
}

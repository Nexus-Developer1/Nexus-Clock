<?php

namespace Tests\Feature;

use App\Livewire\Tempos\Calendario;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Support\PessoaNaAgenda;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Calendário «Toda a equipa»: cada bloco com as iniciais de quem o fez; duas ou mais pessoas no mesmo
// projeto à mesma hora juntam-se num bloco em faixas (uma cor por pessoa), como a agenda da IFE.
class CalendarioEquipaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private User $rui;

    private ProjetoTempo $obra;

    private ProjetoTempo $interno;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00'); // quinta; semana 14/09–20/09

        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->ana->forceFill(['nome' => 'Ana Martins', 'cor_agenda' => '#3a96dd'])->save();
        $this->rui = $this->tecnico();
        $this->rui->forceFill(['nome' => 'Rui Costa'])->save();
        $this->obra = ProjetoTempo::create(['nome' => 'Obra', 'cor' => '#2a78d6']);
        $this->interno = ProjetoTempo::create(['nome' => 'Interno']);
    }

    private function comHoras(User $tecnico, string $de, string $ate, string $descricao, ?ProjetoTempo $projeto, string $dia = '2026-09-15'): RegistoTempo
    {
        $inicio = CarbonImmutable::parse($dia.' '.$de, config('tempos.fuso'))->utc();
        $fim = CarbonImmutable::parse($dia.' '.$ate, config('tempos.fuso'))->utc();

        return RegistoTempo::create([
            'tecnico_id' => $tecnico->id, 'cliente_id' => $this->cliente('Hospital')->id, 'projeto_id' => $projeto?->id,
            'descricao' => $descricao, 'inicio' => $inicio, 'fim' => $fim, 'duracao_seg' => (int) $inicio->diffInSeconds($fim),
        ]);
    }

    public function test_iniciais_e_cores_como_na_ife(): void
    {
        $this->assertSame('AM', PessoaNaAgenda::iniciais('Ana Martins'));
        $this->assertSame('RP', PessoaNaAgenda::iniciais('  rui  pedro moreira '));
        $this->assertSame('–', PessoaNaAgenda::iniciais(''));

        // A cor é a da conta (a da IFE); sem cor, uma da mesma paleta pelo id — e não se grava nada.
        $this->assertSame('#3a96dd', PessoaNaAgenda::cor($this->ana->fresh()));
        $this->assertSame(PessoaNaAgenda::PALETA[$this->rui->id % 12], PessoaNaAgenda::cor($this->rui->fresh()));
        $this->assertNull($this->rui->fresh()->cor_agenda);

        $this->assertSame('#ffffff', PessoaNaAgenda::textoSobre('#1c3f95'));
        $this->assertSame('#1f2937', PessoaNaAgenda::textoSobre('#b3a3e0'));
    }

    public function test_toda_a_equipa_junta_o_mesmo_projeto_a_mesma_hora_num_bloco_em_faixas(): void
    {
        $a = $this->comHoras($this->ana, '09:00', '10:30', 'Ana na obra', $this->obra);
        $r = $this->comHoras($this->rui, '10:00', '11:00', 'Rui na obra', $this->obra);
        $this->comHoras($this->rui, '09:30', '10:00', 'Rui no interno', $this->interno);   // outro projeto: à parte
        $this->comHoras($this->ana, '15:00', '16:00', 'Ana mais tarde', $this->obra);       // não se cruza: à parte

        $pagina = Livewire::actingAs($this->admin)->test(Calendario::class)
            ->set('pessoa', 'equipa')
            ->assertSet('pessoa', 'equipa')
            ->assertSee('Toda a equipa')
            ->assertSee('Ana Martins')   // legenda
            ->assertSee('Rui Costa')
            ->assertSee('09:00 – 11:00')
            ->assertSee('2 registos');

        $blocos = collect($pagina->viewData('dias')[1]['blocos']);   // terça, 15/09
        $this->assertCount(3, $blocos);

        $junto = $blocos->first(fn ($b) => count($b['registos']) === 2);
        $this->assertSame([540, 120], [$junto['minuto'], $junto['minutos']]);
        $this->assertSame([$a->id, $r->id], collect($junto['registos'])->pluck('id')->all());
        $this->assertSame(['AM', 'RC'], array_column($junto['pessoas'], 'iniciais'));
        $this->assertSame('11:00', $junto['fim']);

        // O bloco junto e o do interno cruzam-se: ficam lado a lado.
        $interno = $blocos->first(fn ($b) => $b['registo']->projeto_id === $this->interno->id);
        $this->assertSame([2, 2], [$junto['colunas'], $interno['colunas']]);

        $this->assertStringContainsString('#3a96dd33 0% 50%', PessoaNaAgenda::faixas($junto['pessoas']));

        // Carregar no bloco junto mostra os registos dele; escolher um abre-o.
        $pagina->call('verGrupo', [$a->id, $r->id])
            ->assertSet('grupo', [$a->id, $r->id])
            ->assertSee('Ana na obra')
            ->assertSee('Rui na obra')
            ->call('abrirDoGrupo', $r->id)
            ->assertSet('grupo', [])
            ->assertSet('editarId', $r->id);
    }

    public function test_so_de_uma_pessoa_nao_junta_e_mostra_as_iniciais(): void
    {
        $this->comHoras($this->ana, '09:00', '10:30', 'Ana na obra', $this->obra);
        $this->comHoras($this->rui, '10:00', '11:00', 'Rui na obra', $this->obra);

        $pagina = Livewire::actingAs($this->ana)->test(Calendario::class)
            ->assertSee('Ana na obra')
            ->assertDontSee('Rui na obra')
            ->assertSee('AM');

        $blocos = $pagina->viewData('dias')[1]['blocos'];
        $this->assertCount(1, $blocos);
        $this->assertCount(1, $blocos[0]['registos']);
    }

    public function test_tecnico_nao_ve_a_equipa_nem_abre_registos_dos_outros(): void
    {
        $doRui = $this->comHoras($this->rui, '10:00', '11:00', 'Rui na obra', $this->obra);
        $this->comHoras($this->ana, '09:00', '10:30', 'Ana na obra', $this->obra);

        Livewire::actingAs($this->ana)->withQueryParams(['pessoa' => 'equipa'])->test(Calendario::class)
            ->assertSet('pessoa', '')
            ->assertDontSee('Toda a equipa')
            ->assertDontSee('Rui na obra')
            ->call('verGrupo', [$doRui->id])
            ->assertSet('grupo', []);
    }

    public function test_acrescentar_em_toda_a_equipa_nao_prende_a_pessoa(): void
    {
        // Quem é só Administrador não regista horas: escolhe o membro (notas §76).
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->set('pessoa', 'equipa')
            ->call('novo', ['dia' => '2026-09-16', 'hora_inicio' => '09:00', 'hora_fim' => '10:00'])
            ->assertSet('formulario.tecnico_id', '')
            ->assertSee('Escolha o membro')
            ->assertSee('Ana Martins');

        // Quem é Administrador e técnico fica escolhido.
        $ambos = $this->adminTecnico();
        Livewire::actingAs($ambos)->test(Calendario::class)
            ->set('pessoa', 'equipa')
            ->call('novo', ['dia' => '2026-09-16', 'hora_inicio' => '09:00', 'hora_fim' => '10:00'])
            ->assertSet('formulario.tecnico_id', (string) $ambos->id)
            ->assertDontSee('Escolha o membro');
    }
}

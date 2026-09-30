<?php

namespace Tests\Feature;

use App\Livewire\Equipa\Regras;
use App\Livewire\Relatorios\Detalhado;
use App\Livewire\Tempos\Cronometro;
use App\Models\Auditoria;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\CamposObrigatorios;
use App\Services\Tempos\GravadorRegistos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Campos obrigatórios nos registos (como os «Required fields» do Clockify, notas §58): quem gere a equipa
// escolhe, em Equipa › Regras, se o projeto, a descrição e as etiquetas são obrigatórios. Vale para toda
// a gente, admins incluídos. O cronómetro começa sem eles; para parar ou gravar, têm de estar preenchidos.
class CamposObrigatoriosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private ProjetoTempo $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        $this->obra = ProjetoTempo::create(['nome' => 'Obra']);
    }

    private function erros(callable $acao): array
    {
        try {
            $acao();
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }

        return [];
    }

    public function test_por_omissao_nada_e_obrigatorio(): void
    {
        $this->assertSame([], app(CamposObrigatorios::class)->ativos());
        app(GravadorRegistos::class)->criar($this->ana, ['dia' => '2026-09-29', 'duracao_seg' => 3600]);
        $this->assertSame(1, RegistoTempo::count());
    }

    public function test_campos_obrigatorios_valem_para_todos_ao_gravar(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, ['projeto', 'descricao', 'etiquetas']);
        $gravador = app(GravadorRegistos::class);

        foreach ([$this->ana, $this->admin] as $quem) {
            $this->assertSame([
                'projeto_id' => 'O projeto é obrigatório.',
                'descricao' => 'A descrição é obrigatória.',
                'etiquetas' => 'Indique pelo menos uma etiqueta.',
            ], $this->erros(fn () => $gravador->criar($quem, ['dia' => '2026-09-29', 'duracao_seg' => 3600])));
        }

        $gravador->criar($this->ana, ['dia' => '2026-09-29', 'duracao_seg' => 3600, 'projeto_id' => $this->obra->id, 'descricao' => 'Manutenção', 'etiquetas' => ['remoto']]);
        $this->assertSame(1, RegistoTempo::count());
    }

    public function test_cronometro_comeca_sem_eles_mas_so_para_com_eles(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, ['projeto']);

        $pagina = Livewire::actingAs($this->ana)->test(Cronometro::class)->call('comecar')->assertSet('erro', null);
        $this->assertSame(1, RegistoTempo::whereNull('fim')->count(), 'começou sem projeto');

        Carbon::setTestNow('2026-09-30 10:30:00');
        $pagina->call('parar')->assertSet('erro', 'O projeto é obrigatório.');
        $this->assertSame(1, RegistoTempo::whereNull('fim')->count(), 'continua a correr');

        $pagina->set('barraProjeto', (string) $this->obra->id)->call('parar')->assertSet('erro', null);
        $this->assertSame(0, RegistoTempo::whereNull('fim')->count());
        $this->assertSame($this->obra->id, RegistoTempo::sole()->projeto_id);
    }

    public function test_so_quem_gere_a_equipa_muda_e_fica_na_auditoria(): void
    {
        Livewire::actingAs($this->admin)->test(Regras::class)
            ->set('campos', ['projeto', 'descricao'])
            ->call('guardar')
            ->assertSee('Regras guardadas.');
        $this->assertSame(['projeto', 'descricao'], app(CamposObrigatorios::class)->ativos());
        $this->assertSame(['projeto', 'descricao'], Auditoria::where('acao', 'tempo_campos_obrigatorios')->sole()->detalhe['depois']);

        // O técnico vê as regras, mas não as muda.
        $this->actingAs($this->ana)->get(route('equipa.regras'))->assertOk()->assertSee('Campos obrigatórios');
        Livewire::actingAs($this->ana)->test(Regras::class)->set('campos', [])->call('guardar')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(CamposObrigatorios::class)->definir($this->ana, []);
    }

    public function test_campo_desconhecido_nao_entra(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, ['projeto', 'tecnico_id', 'descricao', 'projeto']);
        $this->assertSame(['projeto', 'descricao'], app(CamposObrigatorios::class)->ativos());
    }

    public function test_formulario_marca_os_obrigatorios(): void
    {
        app(CamposObrigatorios::class)->definir($this->admin, ['projeto']);

        // Sem os comentários que o Livewire mete à volta dos @if.
        $html = preg_replace('/<!--.*?-->/s', '', Livewire::actingAs($this->ana)->test(Detalhado::class)->call('novo')->html());
        $this->assertStringContainsString('for="registo-projeto">Projeto <span class="text-perigo-500">*</span>', $html);
        $this->assertStringNotContainsString('for="registo-descricao">Descrição <span class="text-perigo-500">*</span>', $html);
    }
}

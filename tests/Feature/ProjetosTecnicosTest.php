<?php

namespace Tests\Feature;

use App\Livewire\Projetos\Detalhe;
use App\Livewire\Projetos\Listagem;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\GestorProjetos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Notas §63: os técnicos também criam, alteram, arquivam e apagam projetos — mas só os que veem (os públicos
// e os privados de que são membros), e sem a taxa nem os valores em euros, que continuam de quem é admin.
class ProjetosTecnicosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    private GestorProjetos $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
        app(GestorEquipa::class)->sincronizar();
        $this->gestor = app(GestorProjetos::class);
    }

    private function naoExiste(callable $acao): void
    {
        try {
            $acao();
            $this->fail('Passou num projeto que a técnica não vê.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('não existe', collect($e->errors())->flatten()->first());
        }
    }

    public function test_tecnico_cria_pelo_botao_sem_campo_de_taxa(): void
    {
        $pagina = Livewire::actingAs($this->ana)->test(Listagem::class)
            ->assertSee('Novo projeto')
            ->call('novo')
            ->assertDontSeeHtml('wire:model="formulario.taxa"')
            ->set('formulario.nome', 'Manutenção escolas')
            ->set('formulario.taxa', '99') // à mão, pelo browser: ignorada
            ->call('guardar')
            ->assertHasNoErrors();

        $p = ProjetoTempo::where('nome', 'Manutenção escolas')->sole();
        $this->assertNull($p->taxa_cent);
        $pagina->assertSee('Manutenção escolas');

        // O admin continua a ver e a pôr a taxa.
        Livewire::actingAs($this->admin)->test(Listagem::class)->call('novo')->assertSeeHtml('wire:model="formulario.taxa"');
    }

    public function test_privado_criado_por_um_tecnico_fica_com_ele_como_membro(): void
    {
        $p = $this->gestor->criar($this->ana, ['nome' => 'Interno', 'publico' => false, 'membros' => []]);

        $this->assertSame([MembroEquipa::where('utilizador_id', $this->ana->id)->value('id')], $p->membros()->pluck('membros_equipa.id')->all());
        $this->assertTrue(ProjetoTempo::visiveisPara($this->ana)->whereKey($p->id)->exists());

        // E não se tira a si próprio ao alterar.
        $this->gestor->atualizar($this->ana, $p->fresh(), ['membros' => []]);
        $this->assertTrue(ProjetoTempo::visiveisPara($this->ana)->whereKey($p->id)->exists());
    }

    public function test_tecnico_so_mexe_nos_projetos_que_ve(): void
    {
        $publico = $this->gestor->criar($this->admin, ['nome' => 'Público', 'taxa' => '40']);
        $secreto = $this->gestor->criar($this->admin, ['nome' => 'Secreto', 'publico' => false]);

        // No público, altera, mas a taxa fica como o admin a deixou.
        $this->gestor->atualizar($this->ana, $publico, ['nome' => 'Público renomeado', 'taxa' => '']);
        $this->assertSame(['Público renomeado', 4000], [$publico->fresh()->nome, $publico->fresh()->taxa_cent]);
        $this->gestor->arquivar($this->ana, [$publico->id]);
        $this->assertNotNull($publico->fresh()->arquivado_em);

        // No privado de que não é membro, nada — nem pelo id posto à mão.
        $this->naoExiste(fn () => $this->gestor->atualizar($this->ana, $secreto, ['nome' => 'Mudado']));
        $this->naoExiste(fn () => $this->gestor->arquivar($this->ana, [$secreto->id]));
        $this->naoExiste(fn () => $this->gestor->apagar($this->ana, [$secreto->id]));
        $this->assertSame('Secreto', $secreto->fresh()->nome);
        Livewire::actingAs($this->ana)->test(Listagem::class)->call('editar', $secreto->id)->assertNotFound();
    }

    public function test_valores_continuam_de_quem_e_admin(): void
    {
        $p = $this->gestor->criar($this->admin, ['nome' => 'Obra', 'taxa' => '40']);

        $csv = base64_decode(Livewire::actingAs($this->ana)->test(Listagem::class)->call('exportar')->effects['download']['content']);
        $this->assertStringNotContainsString('Valor', $csv);
        $this->assertStringContainsString('Obra', $csv);
        $this->assertStringContainsString('Valor (€)', base64_decode(Livewire::actingAs($this->admin)->test(Listagem::class)->call('exportar')->effects['download']['content']));

        Livewire::actingAs($this->ana)->test(Detalhe::class, ['projeto' => $p])->assertDontSee('Taxa')->assertDontSee('40,00 €');
        Livewire::actingAs($this->admin)->test(Detalhe::class, ['projeto' => $p])->assertSee('Taxa')->assertSee('40,00 €/h');
    }
}

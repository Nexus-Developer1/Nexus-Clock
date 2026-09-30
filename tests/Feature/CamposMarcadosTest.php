<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Atribuicoes;
use App\Livewire\Relatorios\Despesas;
use App\Livewire\Relatorios\Detalhado;
use App\Models\User;
use App\Services\Tempos\GestorClientes;
use App\Support\Nif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

// Notas §60: os formulários marcam com * os campos que a aplicação exige; e o NIF dos clientes (§59).
class CamposMarcadosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->admin = $this->admin();
        $this->ana = $this->tecnico();
    }

    /** O HTML sem os comentários que o Livewire mete à volta dos @if. */
    private function html(string $html): string
    {
        return preg_replace('/<!--.*?-->/s', '', $html);
    }

    public function test_campos_obrigatorios_levam_asterisco(): void
    {
        $ast = ' <span class="text-perigo-500">*</span>';

        $registo = $this->html(Livewire::actingAs($this->admin)->test(Detalhado::class)->call('novo')->html());
        foreach (['registo-membro">Membro', 'registo-dia">Dia', 'registo-inicio">Início', 'registo-fim">Fim', 'registo-duracao">ou Duração'] as $campo) {
            $this->assertStringContainsString($campo.$ast, $registo);
        }
        foreach (['registo-descricao">Descrição', 'registo-projeto">Projeto', 'registo-etiquetas">Etiquetas'] as $campo) {
            $this->assertStringContainsString($campo.'</label>', $registo, 'opcional: sem asterisco');
        }

        $despesa = $this->html(Livewire::actingAs($this->admin)->test(Despesas::class)->call('nova')->html());
        foreach (['despesa-membro">Membro', 'despesa-data">Data', 'despesa-categoria">Categoria', 'despesa-valor">Valor'] as $campo) {
            $this->assertStringContainsString($campo.$ast, $despesa);
        }
        $this->assertStringContainsString('despesa-projeto">Projeto</label>', $despesa);

        $atribuicao = $this->html(Livewire::actingAs($this->admin)->test(Atribuicoes::class)->call('nova')->html());
        foreach (['atribuicao-membro">Membro', 'atribuicao-projeto">Projeto', 'atribuicao-de">De', 'atribuicao-ate">Até', 'atribuicao-horas">Horas por dia'] as $campo) {
            $this->assertStringContainsString($campo.$ast, $atribuicao);
        }

        // A página das regras saiu.
        $this->actingAs($this->admin)->get('/equipa/regras')->assertNotFound();
    }

    public function test_nif_do_cliente_opcional_mas_valido(): void
    {
        $gestor = app(GestorClientes::class);

        $this->assertNull($gestor->criar($this->ana, ['nome' => 'Sem NIF'])->nif);
        $this->assertSame('501964843', $gestor->criar($this->ana, ['nome' => 'Hospital', 'nif' => 'PT 501 964 843'])->nif);
        $this->assertSame('GB123456789', $gestor->criar($this->ana, ['nome' => 'Retail', 'nif' => 'gb 123456789'])->nif);

        foreach (['501964842', '12AB'] as $invalido) {
            try {
                $gestor->criar($this->ana, ['nome' => 'Outro '.$invalido, 'nif' => $invalido]);
                $this->fail('Aceitou o NIF '.$invalido.'.');
            } catch (ValidationException $e) {
                $this->assertSame(['nif' => ['NIF inválido.']], $e->errors());
            }
        }

        $this->assertTrue(Nif::valido('501964843'));
        $this->assertFalse(Nif::valido('123456788')); // o 123456789 tem mesmo o dígito de controlo certo
    }
}

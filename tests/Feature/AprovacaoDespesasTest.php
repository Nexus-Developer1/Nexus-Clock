<?php

namespace Tests\Feature;

use App\Livewire\Relatorios\Despesas;
use App\Models\CategoriaDespesaTempo;
use App\Models\DespesaTempo;
use App\Models\User;
use App\Notifications\DespesaPorAprovar;
use App\Services\Tempos\GestorDespesas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Só o Paulo Gouveia aprova as despesas do Suporte — ser admin não chega — e recebe cada despesa por
// aprovar num email que não se confunde com os do IFE (notas §44).
class AprovacaoDespesasTest extends TestCase
{
    use RefreshDatabase;

    private User $paulo;

    private User $julio;

    private User $ana;

    private GestorDespesas $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 10:00:00');
        config(['tempos.aprovam_despesas' => ['pgouveia@nxs.pt']]);

        $this->paulo = $this->admin('pgouveia@nxs.pt');
        $this->paulo->update(['nome' => 'Paulo Gouveia']);
        $this->julio = $this->admin('jsantos@nxs.pt');
        $this->julio->update(['nome' => 'Julio Santos']);
        $this->ana = $this->tecnico();
        $this->ana->update(['nome' => 'Ana Martins']);
        $this->gestor = app(GestorDespesas::class);
    }

    private function despesa(User $autor, array $dados = []): DespesaTempo
    {
        return $this->gestor->criar($autor, $dados + ['data' => '2026-09-15', 'valor' => '18,50', 'categoria_id' => CategoriaDespesaTempo::first()->id, 'nota' => 'Almoço em deslocação']);
    }

    public function test_so_o_paulo_decide_os_outros_admins_nao(): void
    {
        $d = $this->despesa($this->ana);

        foreach (['aprovada', 'rejeitada', 'pendente'] as $estado) {
            try {
                $this->gestor->decidir($this->julio, $d, $estado, 'motivo');
                $this->fail('O Julio é admin, mas não aprova.');
            } catch (AuthorizationException $e) {
                $this->assertSame('Só quem aprova as despesas pode fazer isto.', $e->getMessage());
            }
        }

        // Na página: o Julio vê a despesa, mas não tem os botões de decidir, nem os consegue chamar.
        Livewire::actingAs($this->julio)->test(Despesas::class)
            ->call('ver', $d->id)
            ->assertSee('Almoço em deslocação')
            ->assertDontSee('wire:click="aprovar('.$d->id.')"', false)
            ->assertDontSee('wire:click="pedirRejeicao('.$d->id.')"', false)
            ->call('aprovar', $d->id)->assertSet('erro', 'Só quem aprova as despesas pode fazer isto.')
            ->call('pedirRejeicao', $d->id)->assertForbidden();
        $this->assertSame('pendente', $d->fresh()->estado);

        // O resto do que um admin faz continua: lançar para outros e gerir categorias.
        $this->despesa($this->julio, ['utilizador_id' => $this->ana->id]);
        $this->gestor->criarCategoria($this->julio, 'Estacionamento');

        // O Paulo decide.
        Livewire::actingAs($this->paulo)->test(Despesas::class)
            ->call('ver', $d->id)
            ->assertSee('wire:click="aprovar('.$d->id.')"', false)
            ->call('aprovar', $d->id)
            ->assertSee('Aprovada por Paulo Gouveia');
        $this->assertSame(['aprovada', $this->paulo->id], [$d->fresh()->estado, $d->fresh()->decidido_por]);
    }

    public function test_pedido_de_aprovacao_por_email_so_ao_paulo(): void
    {
        Notification::fake();

        $d = $this->despesa($this->ana);

        Notification::assertSentTo($this->paulo, DespesaPorAprovar::class, function (DespesaPorAprovar $n) use ($d) {
            $assunto = $n->toMail($this->paulo)->subject;

            return ! $n->reenvio && $assunto === 'Suporte · Despesa SUP-'.$d->id.' · Ana Martins · 18,50 € — para aprovar';
        });
        Notification::assertNotSentTo([$this->julio, $this->ana], DespesaPorAprovar::class);

        // Rejeitada e corrigida pela dona: novo pedido, marcado como corrigido.
        $this->gestor->decidir($this->paulo, $d, 'rejeitada', 'Falta o talão.');
        $this->gestor->atualizar($this->ana, $d, ['nota' => 'Almoço em deslocação (talão junto)']);
        $this->assertSame('pendente', $d->fresh()->estado);
        Notification::assertSentToTimes($this->paulo, DespesaPorAprovar::class, 2);
        Notification::assertSentTo($this->paulo, DespesaPorAprovar::class, fn (DespesaPorAprovar $n) => $n->reenvio
            && str_ends_with($n->toMail($this->paulo)->subject, '— corrigida, para aprovar'));

        // Aprovar, ou voltar a pendente pelo próprio Paulo, não lhe manda nada.
        $this->gestor->decidir($this->paulo, $d, 'aprovada');
        $this->gestor->decidir($this->paulo, $d, 'pendente');
        Notification::assertSentToTimes($this->paulo, DespesaPorAprovar::class, 2);

        // Um aprovador sem conta na suite recebe na mesma, pelo email.
        config(['tempos.aprovam_despesas' => ['financeiro@nxs.pt']]);
        $this->despesa($this->ana);
        Notification::assertSentOnDemand(DespesaPorAprovar::class, fn ($n, $canais, $destino) => $destino->routes['mail'] === 'financeiro@nxs.pt');
    }

    public function test_email_diz_que_e_do_suporte_e_nao_do_ife_e_abre_o_detalhe(): void
    {
        Notification::fake();
        $d = $this->despesa($this->ana, ['nota' => "Portagens A28\nida e volta"]);

        $aviso = null;
        Notification::assertSentTo($this->paulo, DespesaPorAprovar::class, function (DespesaPorAprovar $n) use (&$aviso) {
            $aviso = $n;

            return true;
        });
        $html = (string) $aviso->toMail($this->paulo)->render();

        $this->assertStringContainsString('Esta despesa é do <strong>Nexus Suporte</strong>.', $html);
        $this->assertStringNotContainsString('IFE', $html, 'a pedido: não se refere o IFE');
        $this->assertStringNotContainsString('Recebe este email', $html, 'a pedido: sem texto no fundo');
        $this->assertStringNotContainsString('pre-line', $html, 'no Gmail o pre-line descia os valores uma linha');
        $this->assertStringContainsString('SUP-'.$d->id, $html);
        $this->assertStringContainsString('Ana Martins', $html);
        $this->assertStringContainsString('18,50 €', $html);
        $this->assertStringContainsString("Portagens A28<br>\nida e volta", $html);
        $this->assertStringNotContainsString('Nexus Infra', $html);
        $link = route('relatorios.despesas', ['ver' => $d->id]);
        $this->assertStringContainsString(e($link), $html);

        // O link abre a página já com o detalhe da despesa.
        Livewire::actingAs($this->paulo)->withQueryParams(['ver' => (string) $d->id])->test(Despesas::class)
            ->assertSet('verId', $d->id)
            ->assertSee('SUP-'.$d->id.' · Ana Martins')
            ->assertSee('wire:click="aprovar('.$d->id.')"', false);
    }

    public function test_aprovada_fica_fechada_ate_o_paulo_a_voltar_a_pendente(): void
    {
        $d = $this->despesa($this->ana);
        $this->gestor->decidir($this->paulo, $d, 'aprovada');

        foreach ([$this->ana, $this->julio, $this->paulo] as $quem) {
            $this->assertFalse($this->gestor->podeAlterar($quem, $d->fresh()), $quem->nome.' não altera uma aprovada');
        }
        Livewire::actingAs($this->julio)->test(Despesas::class)->call('editar', $d->id)->assertForbidden();

        $this->gestor->decidir($this->paulo, $d, 'pendente');
        $this->gestor->atualizar($this->ana, $d->fresh(), ['valor' => '20']);
        $this->assertSame(2000, $d->fresh()->valor_cent);
    }
}

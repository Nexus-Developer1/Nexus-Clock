<?php

namespace App\Livewire\Clientes;

use App\Models\ClienteTempo;
use App\Models\DespesaTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Services\Tempos\GestorClientes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Página de um cliente do Suporte (clicar no nome na listagem): os dados do cliente, os totais de
 * horas e despesas e a lista dos seus projetos com as horas de cada um. Um técnico só vê os projetos
 * a que tem acesso (públicos e privados de que é membro), como na página Projetos — e os totais
 * são só desses. Alterar abre a mesma janela da listagem. Um cliente apagado dá 404; um arquivado
 * abre na mesma, marcado. O título da página é o nome do cliente, por isso o layout vai no
 * render() e não no atributo #[Layout], que é estático.
 */
class Detalhe extends Component
{
    public ClienteTempo $cliente;

    // Janela de alteração.
    public bool $editar = false;

    /** @var array{nome: string, email: string, emails_cc: string, morada: string, nota: string, moeda: string} */
    public array $formulario = ['nome' => '', 'email' => '', 'emails_cc' => '', 'morada' => '', 'nota' => '', 'moeda' => 'EUR'];

    public function mount(ClienteTempo $cliente): void
    {
        $this->cliente = $cliente;
    }

    public function abrirFormulario(): void
    {
        $this->resetErrorBag();
        $this->editar = true;
        $this->formulario = [
            'nome' => $this->cliente->nome,
            'email' => (string) $this->cliente->email,
            'emails_cc' => implode(', ', $this->cliente->emails_cc),
            'morada' => (string) $this->cliente->morada,
            'nota' => (string) $this->cliente->nota,
            'moeda' => $this->cliente->moeda,
        ];
    }

    public function fecharFormulario(): void
    {
        $this->reset(['editar', 'formulario']);
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        if (! $this->editar) {
            return;
        }

        $this->resetErrorBag();

        try {
            app(GestorClientes::class)->atualizar(auth()->user(), $this->cliente, $this->formulario);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('formulario.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('formulario.nome', 'Não pode alterar clientes.');

            return;
        }

        $this->cliente->refresh();
        $this->fecharFormulario();
        session()->flash('sucesso', 'Cliente guardado.');
    }

    public function render()
    {
        $projetos = ProjetoTempo::query()
            ->where('cliente_id', $this->cliente->id)
            ->visiveisPara(auth()->user())
            ->orderBy('arquivado_em')
            ->orderByRaw('lower(nome)')
            ->get();
        $ids = $projetos->pluck('id')->all();

        // Horas por projeto (só registos terminados: um cronómetro a correr ainda não tem duração).
        $horas = $ids === [] ? collect() : RegistoTempo::query()
            ->terminados()
            ->whereIn('projeto_id', $ids)
            ->selectRaw('projeto_id, sum(duracao_seg) as total_seg, sum(case when faturavel then duracao_seg else 0 end) as faturavel_seg')
            ->groupBy('projeto_id')
            ->get()
            ->keyBy('projeto_id');

        $despesas = $ids === [] ? null : DespesaTempo::query()
            ->whereIn('projeto_id', $ids)
            ->selectRaw("coalesce(sum(case when estado = 'aprovada' then valor_cent else 0 end), 0) as aprovadas_cent, count(*) filter (where estado = 'pendente') as pendentes")
            ->first();

        return view('livewire.clientes.detalhe', [
            'projetos' => $projetos,
            'horas' => $horas,
            'totalSeg' => (int) $horas->sum('total_seg'),
            'faturavelSeg' => (int) $horas->sum('faturavel_seg'),
            'despesasCent' => (int) ($despesas->aprovadas_cent ?? 0),
            'despesasPendentes' => (int) ($despesas->pendentes ?? 0),
            'podeGerir' => Gate::allows('tempos-gerir-clientes'),
            'moedas' => ClienteTempo::MOEDAS,
        ])->layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => $this->cliente->nome]);
    }
}

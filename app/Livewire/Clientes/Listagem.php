<?php

namespace App\Livewire\Clientes;

use App\Models\ClienteTempo;
use App\Services\Tempos\GestorClientes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Página Clientes (lista própria dos Tempos): filtrar ativos/arquivados, pesquisar pelo nome,
 * alterar numa janela (nome, email, emails em cópia, morada, nota, moeda), arquivar, restaurar e
 * apagar — um a um ou vários de uma vez. Criar é na página própria (App\Livewire\Clientes\Novo),
 * pelo botão «Novo cliente». Os técnicos só veem.
 */
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Clientes'])]
class Listagem extends Component
{
    #[Url(as: 'mostrar')]
    public string $mostrar = 'ativos'; // ativos | arquivados | todos

    #[Url(as: 'q')]
    public string $pesquisa = '';

    /** @var list<string> */
    public array $selecionados = [];

    // Formulário de alteração (modal).
    public ?int $editarId = null;

    /** @var array{nome: string, email: string, emails_cc: string, morada: string, nota: string, moeda: string} */
    public array $formulario = ['nome' => '', 'email' => '', 'emails_cc' => '', 'morada' => '', 'nota' => '', 'moeda' => 'EUR'];

    public ?string $erro = null;

    public function updatedMostrar(): void
    {
        if (! in_array($this->mostrar, ['ativos', 'arquivados', 'todos'], true)) {
            $this->mostrar = 'ativos';
        }
        $this->selecionados = [];
    }

    public function updatedPesquisa(): void
    {
        $this->selecionados = [];
    }

    public function editar(int $id): void
    {
        $cliente = ClienteTempo::findOrFail($id);
        $this->resetErrorBag();
        $this->editarId = $cliente->id;
        $this->formulario = [
            'nome' => $cliente->nome,
            'email' => (string) $cliente->email,
            'emails_cc' => implode(', ', $cliente->emails_cc),
            'morada' => (string) $cliente->morada,
            'nota' => (string) $cliente->nota,
            'moeda' => $cliente->moeda,
        ];
    }

    public function fecharFormulario(): void
    {
        $this->reset(['editarId', 'formulario']);
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        if (! $this->editarId) {
            return;
        }

        $this->resetErrorBag();

        try {
            app(GestorClientes::class)->atualizar(auth()->user(), ClienteTempo::findOrFail($this->editarId), $this->formulario);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('formulario.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('formulario.nome', 'Não pode alterar clientes.');

            return;
        }

        $this->fecharFormulario();
        session()->flash('sucesso', 'Cliente guardado.');
    }

    public function arquivar(?int $id = null): void
    {
        $this->emMassa('arquivar', $id, fn (int $n) => $n === 1 ? 'Cliente arquivado.' : "$n clientes arquivados.");
    }

    public function restaurar(?int $id = null): void
    {
        $this->emMassa('restaurar', $id, fn (int $n) => $n === 1 ? 'Cliente restaurado.' : "$n clientes restaurados.");
    }

    public function apagar(?int $id = null): void
    {
        $this->emMassa('apagar', $id, fn (int $n) => $n === 1 ? 'Cliente apagado.' : "$n clientes apagados.");
    }

    public function render()
    {
        $termo = trim($this->pesquisa);

        return view('livewire.clientes.listagem', [
            'clientes' => ClienteTempo::query()
                ->when($this->mostrar === 'ativos', fn ($q) => $q->ativos())
                ->when($this->mostrar === 'arquivados', fn ($q) => $q->arquivados())
                ->when($termo !== '', fn ($q) => $q->where('nome', 'ilike', '%'.$termo.'%'))
                ->orderByRaw('lower(nome)')
                ->get(),
            'podeGerir' => Gate::allows('tempos-gerir-clientes'),
            'moedas' => ClienteTempo::MOEDAS,
        ]);
    }

    /** Ação sobre um cliente (menu da linha) ou sobre os selecionados. */
    private function emMassa(string $acao, ?int $id, callable $mensagem): void
    {
        $this->erro = null;
        $ids = $id !== null ? [$id] : array_map('intval', $this->selecionados);

        try {
            $n = app(GestorClientes::class)->{$acao}(auth()->user(), $ids);
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();

            return;
        } catch (AuthorizationException) {
            $this->erro = 'Não tem permissão para gerir clientes.';

            return;
        }

        $this->selecionados = [];
        session()->flash('sucesso', $mensagem($n));
    }
}

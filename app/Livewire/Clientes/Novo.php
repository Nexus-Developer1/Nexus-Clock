<?php

namespace App\Livewire\Clientes;

use App\Models\ClienteTempo;
use App\Services\Tempos\GestorClientes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Página de criar um cliente dos Tempos (botão «Novo cliente» na listagem): os mesmos campos da
 * janela de alterar — nome, email, emails em cópia, morada, nota e moeda — numa página própria.
 * Gravado, volta à listagem com a mensagem de sucesso.
 *
 * Entra quem gere clientes (gate `tempos-gerir-clientes`), aberto a toda a gente desde 2026-09-22.
 */
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Novo cliente'])]
class Novo extends Component
{
    /** @var array{nome: string, email: string, emails_cc: string, morada: string, nota: string, moeda: string} */
    public array $formulario = ['nome' => '', 'email' => '', 'emails_cc' => '', 'morada' => '', 'nota' => '', 'moeda' => 'EUR'];

    public function mount(): void
    {
        Gate::authorize('tempos-gerir-clientes');
    }

    public function guardar(): void
    {
        $this->resetErrorBag();

        try {
            $cliente = app(GestorClientes::class)->criar(auth()->user(), $this->formulario);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('formulario.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('formulario.nome', 'Não pode acrescentar clientes.');

            return;
        }

        session()->flash('sucesso', 'Cliente «'.$cliente->nome.'» criado.');
        $this->redirectRoute('clientes', navigate: true);
    }

    public function render()
    {
        return view('livewire.clientes.novo', ['moedas' => ClienteTempo::MOEDAS]);
    }
}

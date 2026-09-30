<?php

namespace App\Livewire\Equipa;

use App\Services\Tempos\CamposObrigatorios;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Equipa › Regras: os campos obrigatórios nos registos (notas §58). Todos veem as regras (como os
 * Lembretes); mudar é só de quem gere a equipa.
 */
#[Layout('components.layouts.app', ['ativo' => 'equipa', 'titulo' => 'Equipa'])]
class Regras extends Component
{
    /** @var list<string> */
    public array $campos = [];

    public function mount(): void
    {
        $this->campos = app(CamposObrigatorios::class)->ativos();
    }

    public function guardar(): void
    {
        abort_unless(Gate::allows('tempos-gerir-equipa'), 403);

        $servico = app(CamposObrigatorios::class);
        $servico->definir(auth()->user(), array_map('strval', $this->campos));
        $this->campos = $servico->ativos();
        session()->flash('sucesso', 'Regras guardadas.');
    }

    public function render()
    {
        return view('livewire.equipa.regras', [
            'podeGerir' => Gate::allows('tempos-gerir-equipa'),
            'disponiveis' => CamposObrigatorios::CAMPOS,
        ]);
    }
}

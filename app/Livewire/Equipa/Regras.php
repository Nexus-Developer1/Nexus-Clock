<?php

namespace App\Livewire\Equipa;

use App\Services\Tempos\CamposObrigatorios;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Equipa › Regras: os campos obrigatórios nos registos de horas, nas despesas, nos clientes e nos projetos
 * (notas §58, §59). Todos veem as regras (como os Lembretes); mudar é só de quem gere a equipa.
 */
#[Layout('components.layouts.app', ['ativo' => 'equipa', 'titulo' => 'Equipa'])]
class Regras extends Component
{
    /** @var array<string, list<string>> campos obrigatórios por sítio */
    public array $campos = [];

    public function mount(): void
    {
        $servico = app(CamposObrigatorios::class);
        foreach (array_keys(CamposObrigatorios::AREAS) as $area) {
            $this->campos[$area] = $servico->ativos($area);
        }
    }

    public function guardar(): void
    {
        abort_unless(Gate::allows('tempos-gerir-equipa'), 403);

        $servico = app(CamposObrigatorios::class);
        foreach (array_keys(CamposObrigatorios::AREAS) as $area) {
            $servico->definir(auth()->user(), $area, array_map('strval', (array) ($this->campos[$area] ?? [])));
            $this->campos[$area] = $servico->ativos($area);
        }
        session()->flash('sucesso', 'Regras guardadas.');
    }

    public function render()
    {
        return view('livewire.equipa.regras', [
            'podeGerir' => Gate::allows('tempos-gerir-equipa'),
            'areas' => CamposObrigatorios::AREAS,
        ]);
    }
}

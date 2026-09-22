<?php

namespace App\Livewire\Equipa;

use App\Models\GrupoEquipa;
use App\Models\LembreteEquipa;
use App\Services\Tempos\GestorEquipa;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Equipa › Lembretes: lembretes por email a quem registou menos horas do que o mínimo no dia ou na
 * semana anterior — a quem, quando (dias da semana e hora) e com que mínimo. Só quem gere a equipa.
 */
#[Layout('components.layouts.app', ['ativo' => 'equipa', 'titulo' => 'Equipa'])]
class Lembretes extends Component
{
    public bool $aEditar = false;

    public ?int $lembreteId = null;

    /** @var array{destinatarios: string, grupos: list<string>, periodo: string, horas_minimas: string, dias: list<string>, hora: string, ativo: bool} */
    public array $formulario = [];

    public ?string $erro = null;

    public function boot(): void
    {
        abort_unless(Gate::allows('tempos-gerir-equipa'), 403);
    }

    public function novo(): void
    {
        $this->resetErrorBag();
        $this->lembreteId = null;
        $this->formulario = ['destinatarios' => 'todos', 'grupos' => [], 'periodo' => 'dia', 'horas_minimas' => '8', 'dias' => ['1', '2', '3', '4', '5'], 'hora' => '9', 'ativo' => true];
        $this->aEditar = true;
    }

    public function editar(int $id): void
    {
        $l = LembreteEquipa::findOrFail($id);
        $this->resetErrorBag();
        $this->lembreteId = $l->id;
        $this->formulario = [
            'destinatarios' => $l->destinatarios,
            'grupos' => array_map('strval', $l->grupos),
            'periodo' => $l->periodo,
            'horas_minimas' => str_replace('.', ',', rtrim(rtrim(number_format($l->horas_minimas, 2, '.', ''), '0'), '.')),
            'dias' => array_map('strval', $l->dias),
            'hora' => (string) $l->hora,
            'ativo' => $l->ativo,
        ];
        $this->aEditar = true;
    }

    public function fechar(): void
    {
        $this->reset(['aEditar', 'lembreteId', 'formulario']);
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        try {
            app(GestorEquipa::class)->guardarLembrete(auth()->user(), $this->lembreteId ? LembreteEquipa::findOrFail($this->lembreteId) : null, $this->formulario);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('formulario.'.$campo, $mensagens[0]);
            }

            return;
        }

        $this->fechar();
        session()->flash('sucesso', 'Lembrete guardado.');
    }

    public function alternar(int $id): void
    {
        $l = LembreteEquipa::findOrFail($id);
        $this->executar(fn () => app(GestorEquipa::class)->guardarLembrete(auth()->user(), $l, ['ativo' => ! $l->ativo] + $l->only(['destinatarios', 'grupos', 'periodo', 'horas_minimas', 'dias', 'hora'])));
    }

    public function apagar(int $id): void
    {
        $this->executar(function () use ($id) {
            app(GestorEquipa::class)->apagarLembrete(auth()->user(), LembreteEquipa::findOrFail($id));
            session()->flash('sucesso', 'Lembrete apagado.');
        });
    }

    public function render()
    {
        return view('livewire.equipa.lembretes', [
            'lembretes' => LembreteEquipa::orderBy('hora')->orderBy('id')->get(),
            'grupos' => GrupoEquipa::orderByRaw('lower(nome)')->get(['id', 'nome']),
            'dias' => LembreteEquipa::DIAS,
        ]);
    }

    private function executar(callable $acao): void
    {
        $this->erro = null;

        try {
            $acao();
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();
        } catch (AuthorizationException) {
            $this->erro = 'Não tem permissão para gerir lembretes.';
        }
    }
}

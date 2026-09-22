<?php

namespace App\Livewire\Equipa;

use App\Models\GrupoEquipa;
use App\Models\MembroEquipa;
use App\Services\Tempos\GestorEquipa;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Equipa › Grupos: criar grupos, renomear, escolher os membros (plenos e limitados) e apagar.
 */
#[Layout('components.layouts.app', ['ativo' => 'equipa', 'titulo' => 'Equipa'])]
class Grupos extends Component
{
    #[Url(as: 'q')]
    public string $pesquisa = '';

    public string $novoNome = '';

    // Janela: renomear e escolher membros.
    public ?int $grupoId = null;

    public string $nome = '';

    /** @var list<string> */
    public array $membros = [];

    public string $pesquisaMembros = '';

    public ?string $erro = null;

    public function mount(): void
    {
        app(GestorEquipa::class)->sincronizar();
    }

    public function acrescentar(): void
    {
        $this->resetErrorBag();

        try {
            $grupo = app(GestorEquipa::class)->criarGrupo(auth()->user(), $this->novoNome);
        } catch (ValidationException $e) {
            $this->addError('novoNome', collect($e->errors())->flatten()->first());

            return;
        } catch (AuthorizationException) {
            $this->addError('novoNome', 'Não pode criar grupos.');

            return;
        }

        $this->novoNome = '';
        session()->flash('sucesso', 'Grupo «'.$grupo->nome.'» criado. Escolha os membros.');
        $this->abrir($grupo->id);
    }

    public function abrir(int $id): void
    {
        abort_unless(Gate::allows('tempos-gerir-equipa'), 403);

        $grupo = GrupoEquipa::with('membros')->findOrFail($id);
        $this->resetErrorBag();
        $this->grupoId = $grupo->id;
        $this->nome = $grupo->nome;
        $this->membros = $grupo->membros->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->pesquisaMembros = '';
    }

    public function fechar(): void
    {
        $this->reset(['grupoId', 'nome', 'membros', 'pesquisaMembros']);
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        if (! $this->grupoId) {
            return;
        }

        $gestor = app(GestorEquipa::class);
        $grupo = GrupoEquipa::findOrFail($this->grupoId);

        try {
            if ($grupo->nome !== trim($this->nome)) {
                $gestor->renomearGrupo(auth()->user(), $grupo, $this->nome);
            }
            $gestor->definirMembrosDoGrupo(auth()->user(), $grupo, array_map('intval', $this->membros));
        } catch (ValidationException $e) {
            $this->addError('nome', collect($e->errors())->flatten()->first());

            return;
        } catch (AuthorizationException) {
            $this->addError('nome', 'Não pode alterar grupos.');

            return;
        }

        $this->fechar();
        session()->flash('sucesso', 'Grupo guardado.');
    }

    public function apagar(int $id): void
    {
        $this->erro = null;

        try {
            $grupo = GrupoEquipa::findOrFail($id);
            app(GestorEquipa::class)->apagarGrupo(auth()->user(), $grupo);
            session()->flash('sucesso', 'Grupo «'.$grupo->nome.'» apagado.');
        } catch (AuthorizationException) {
            $this->erro = 'Não tem permissão para gerir grupos.';
        }
    }

    public function render()
    {
        $termo = trim($this->pesquisa);
        $candidatos = collect();

        if ($this->grupoId) {
            $filtro = mb_strtolower(trim($this->pesquisaMembros));
            $candidatos = MembroEquipa::query()->with('utilizador:id,nome,email')
                ->where(fn ($q) => $q->comAcesso()->orWhere('limitado', true))
                ->get()
                ->filter(fn (MembroEquipa $m) => $filtro === '' || str_contains(mb_strtolower($m->nomeVisivel()), $filtro))
                ->sortBy(fn (MembroEquipa $m) => mb_strtolower($m->nomeVisivel()))
                ->values();
        }

        return view('livewire.equipa.grupos', [
            'grupos' => GrupoEquipa::query()
                ->with(['membros' => fn ($q) => $q->with('utilizador:id,nome')])
                ->when($termo !== '', fn ($q) => $q->where('nome', 'ilike', '%'.$termo.'%'))
                ->orderByRaw('lower(nome)')
                ->get(),
            'podeGerir' => Gate::allows('tempos-gerir-equipa'),
            'candidatos' => $candidatos,
        ]);
    }
}

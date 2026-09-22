<?php

namespace App\Livewire\Relatorios;

use App\Models\RelatorioPartilhado;
use App\Services\Tempos\GestorPartilhados;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatórios partilhados (como os "Shared reports" do Clockify): os links criados a partir dos
 * relatórios, com visibilidade, período e envio por email; copiar, abrir, alterar, gerar um novo link
 * e apagar. Cada pessoa vê os seus; quem gere a equipa vê (e gere) todos.
 */
#[Layout('components.layouts.app', ['ativo' => 'relatorios.partilhados', 'titulo' => 'Partilhados'])]
class Partilhados extends Component
{
    #[Url(as: 'q')]
    public string $pesquisa = '';

    public ?int $editarId = null;

    /** @var array<string, mixed> */
    public array $formulario = [];

    public ?string $erro = null;

    public function editar(int $id): void
    {
        $r = $this->relatorio($id);
        $this->resetErrorBag();
        $this->editarId = $r->id;
        $this->formulario = [
            'nome' => $r->nome,
            'publico' => $r->publico ? '1' : '0',
            'sempre_atual' => $r->sempre_atual,
            'bloquear_datas' => $r->bloquear_datas,
            'email_ativo' => $r->email_ativo,
            'email_destinatarios' => implode(', ', $r->email_destinatarios),
            'email_frequencia' => $r->email_frequencia,
            'email_hora' => (string) $r->email_hora,
            'datas' => ($r->parametros['tipo'] ?? '') === 'datas',
        ];
    }

    public function fechar(): void
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
        $dados = $this->formulario;
        $dados['publico'] = (string) $dados['publico'] === '1';
        unset($dados['datas']);

        try {
            app(GestorPartilhados::class)->atualizar(auth()->user(), $this->relatorio($this->editarId), $dados);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('formulario.'.$campo, $mensagens[0]);
            }

            return;
        }

        $this->fechar();
        session()->flash('sucesso', 'Relatório guardado.');
    }

    public function novoLink(int $id): void
    {
        $this->executar(fn () => app(GestorPartilhados::class)->novoLink(auth()->user(), $this->relatorio($id)), 'Novo link criado. O anterior deixou de funcionar.');
    }

    public function apagar(int $id): void
    {
        $this->executar(fn () => app(GestorPartilhados::class)->apagar(auth()->user(), $this->relatorio($id)), 'Relatório partilhado apagado. O link deixou de funcionar.');
    }

    public function render()
    {
        $termo = trim($this->pesquisa);

        return view('livewire.relatorios.partilhados', [
            'relatorios' => $this->visiveis()
                ->with('autor:id,nome')
                ->when($termo !== '', fn ($q) => $q->where('nome', 'ilike', '%'.addcslashes($termo, '%_\\').'%'))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->get(),
            'veTodos' => Gate::allows('tempos-gerir-equipa'),
            'frequencias' => RelatorioPartilhado::FREQUENCIAS,
            'tipos' => RelatorioPartilhado::TIPOS,
        ]);
    }

    private function visiveis()
    {
        return RelatorioPartilhado::query()
            ->unless(Gate::allows('tempos-gerir-equipa'), fn ($q) => $q->where('criado_por', auth()->id()));
    }

    private function relatorio(int $id): RelatorioPartilhado
    {
        return $this->visiveis()->findOrFail($id);
    }

    private function executar(callable $acao, string $mensagem): void
    {
        $this->erro = null;
        try {
            $acao();
        } catch (AuthorizationException $e) {
            $this->erro = $e->getMessage();

            return;
        }
        session()->flash('sucesso', $mensagem);
    }
}

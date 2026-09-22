<?php

namespace App\Livewire\Projetos;

use App\Models\ClienteTempo;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Services\Tempos\GestorProjetos;
use App\Services\Tempos\HorasProjetos;
use App\Support\Csv;
use App\Support\Dinheiro;
use App\Support\Horas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Página Projetos (como a do Clockify): filtrar ativos/arquivados, por cliente, acesso e faturação,
 * pesquisar, ordenar; ver horas registadas, valor e progresso; favoritos primeiro; criar e alterar num
 * formulário; arquivar, restaurar e apagar, um a um ou vários; exportar CSV. Os técnicos veem os
 * projetos públicos e os privados de que são membros, e marcam favoritos.
 */
#[Layout('components.layouts.app', ['ativo' => 'projetos', 'titulo' => 'Projetos'])]
class Listagem extends Component
{
    public const ORDENS = ['nome', 'cliente', 'registado', 'valor', 'progresso'];

    #[Url(as: 'mostrar')]
    public string $mostrar = 'ativos'; // ativos | arquivados | todos

    #[Url(as: 'cliente')]
    public string $filtroCliente = ''; // '' | 'sem' | id

    #[Url(as: 'acesso')]
    public string $filtroAcesso = ''; // '' | publico | privado

    #[Url(as: 'faturacao')]
    public string $filtroFaturacao = ''; // '' | faturavel | nao

    #[Url(as: 'q')]
    public string $pesquisa = '';

    #[Url(as: 'ordem')]
    public string $ordem = 'nome'; // campo, com "-" à frente para descendente

    /** @var list<string> */
    public array $selecionados = [];

    // Formulário (modal): null = fechado, 0 = novo projeto.
    public ?int $editarId = null;

    /** @var array{nome: string, cliente_id: string, cor: string, publico: bool, membros: list<string>, faturavel: bool, taxa: string, estimativa: string, nota: string} */
    public array $formulario = [];

    public ?string $erro = null;

    public function mount(): void
    {
        $this->normalizar();
    }

    public function updated(string $propriedade): void
    {
        $this->normalizar();
        if (! str_starts_with($propriedade, 'formulario') && $propriedade !== 'selecionados') {
            $this->selecionados = [];
        }
    }

    public function ordenarPor(string $campo): void
    {
        if (in_array($campo, self::ORDENS, true)) {
            $this->ordem = $this->ordem === $campo ? '-'.$campo : $campo;
        }
    }

    public function novo(): void
    {
        $this->resetErrorBag();
        $this->editarId = 0;
        $this->formulario = $this->formularioVazio();
    }

    public function editar(int $id): void
    {
        $p = ProjetoTempo::with('membros')->findOrFail($id);
        $this->resetErrorBag();
        $this->editarId = $p->id;
        $this->formulario = [
            'nome' => $p->nome,
            'cliente_id' => (string) $p->cliente_id,
            'cor' => $p->cor,
            'publico' => $p->publico,
            'membros' => $p->membros->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'faturavel' => $p->faturavel,
            'taxa' => Dinheiro::decimal($p->taxa_cent),
            'estimativa' => $p->estimativa_seg ? rtrim(rtrim(number_format($p->estimativa_seg / 3600, 2, ',', ''), '0'), ',') : '',
            'nota' => (string) $p->nota,
        ];
    }

    public function fecharFormulario(): void
    {
        $this->reset(['editarId', 'formulario']);
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        if ($this->editarId === null) {
            return;
        }

        $this->resetErrorBag();
        $dados = $this->formulario;
        if (! $dados['publico']) {
            $dados['membros'] = array_map('intval', $dados['membros']);
        } else {
            unset($dados['membros']);
        }

        try {
            $gestor = app(GestorProjetos::class);
            $projeto = $this->editarId === 0
                ? $gestor->criar(auth()->user(), $dados)
                : $gestor->atualizar(auth()->user(), ProjetoTempo::findOrFail($this->editarId), $dados);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('formulario.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('formulario.nome', 'Não pode gerir projetos.');

            return;
        }

        $novo = $this->editarId === 0;
        $this->fecharFormulario();
        session()->flash('sucesso', $novo ? 'Projeto «'.$projeto->nome.'» criado.' : 'Projeto guardado.');
    }

    public function alternarFavorito(int $id): void
    {
        try {
            app(GestorProjetos::class)->alternarFavorito(auth()->user(), ProjetoTempo::findOrFail($id));
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();
        }
    }

    public function arquivar(?int $id = null): void
    {
        $this->emMassa('arquivar', $id, fn (int $n) => $n === 1 ? 'Projeto arquivado.' : "$n projetos arquivados.");
    }

    public function restaurar(?int $id = null): void
    {
        $this->emMassa('restaurar', $id, fn (int $n) => $n === 1 ? 'Projeto restaurado.' : "$n projetos restaurados.");
    }

    public function apagar(?int $id = null): void
    {
        $this->emMassa('apagar', $id, fn (int $n) => $n === 1 ? 'Projeto apagado.' : "$n projetos apagados.");
    }

    public function exportar()
    {
        abort_unless(Gate::allows('tempos-gerir-projetos'), 403);

        $linhas = $this->projetos()->map(fn (ProjetoTempo $p) => [
            $p->nome, $p->cliente?->nome ?? '', Horas::decimal($p->segundos), Dinheiro::decimal($p->valor_cent),
            $p->progresso === null ? '' : number_format($p->progresso, 1, ',', ''),
            $p->publico ? 'Público' : 'Privado', $p->faturavel ? 'Sim' : 'Não', $p->estimativa_seg ? Horas::decimal($p->estimativa_seg) : '',
            $p->estaArquivado() ? 'Sim' : 'Não',
        ]);

        return Csv::resposta('projetos-'.now(config('tempos.fuso'))->format('Ymd').'.csv',
            ['Projeto', 'Cliente', 'Registado (h)', 'Valor (€)', 'Progresso (%)', 'Acesso', 'Faturável', 'Estimativa (h)', 'Arquivado'], $linhas->all());
    }

    public function render()
    {
        return view('livewire.projetos.listagem', [
            'projetos' => $this->projetos(),
            'podeGerir' => Gate::allows('tempos-gerir-projetos'),
            'clientes' => ClienteTempo::orderByRaw('lower(nome)')->get(['id', 'nome', 'arquivado_em']),
            'membros' => $this->editarId !== null ? MembroEquipa::with('utilizador')->get()->sortBy(fn ($m) => mb_strtolower($m->nomeVisivel()))->values() : collect(),
            'cores' => ProjetoTempo::CORES,
        ]);
    }

    /**
     * Projetos visíveis com os filtros, com segundos, valor_cent, progresso e favorito, ordenados
     * (favoritos primeiro).
     *
     * @return Collection<int, ProjetoTempo>
     */
    private function projetos(): Collection
    {
        $termo = trim($this->pesquisa);
        $projetos = ProjetoTempo::query()
            ->with('cliente')
            ->visiveisPara(auth()->user())
            ->when($this->mostrar === 'ativos', fn ($q) => $q->ativos())
            ->when($this->mostrar === 'arquivados', fn ($q) => $q->arquivados())
            ->when($this->filtroCliente === 'sem', fn ($q) => $q->whereNull('cliente_id'))
            ->when(ctype_digit($this->filtroCliente), fn ($q) => $q->where('cliente_id', (int) $this->filtroCliente))
            ->when($this->filtroAcesso !== '', fn ($q) => $q->where('publico', $this->filtroAcesso === 'publico'))
            ->when($this->filtroFaturacao !== '', fn ($q) => $q->where('faturavel', $this->filtroFaturacao === 'faturavel'))
            ->when($termo !== '', fn ($q) => $q->where('nome', 'ilike', '%'.addcslashes($termo, '%_\\').'%'))
            ->get();

        $horas = app(HorasProjetos::class)->porProjeto($projetos->modelKeys());
        $favoritos = DB::table('projeto_favoritos')->where('utilizador_id', auth()->id())->pluck('projeto_id')->all();

        foreach ($projetos as $p) {
            $p->segundos = $horas[$p->id]['segundos'] ?? 0;
            $p->valor_cent = $horas[$p->id]['valor_cent'] ?? 0;
            $p->progresso = $p->estimativa_seg ? round($p->segundos * 100 / $p->estimativa_seg, 1) : null;
            $p->favorito = in_array($p->id, $favoritos, true);
        }

        $campo = ltrim($this->ordem, '-');
        $chave = fn (ProjetoTempo $p) => match ($campo) {
            'cliente' => mb_strtolower($p->cliente?->nome ?? "\u{10FFFF}"),
            'registado' => $p->segundos,
            'valor' => $p->valor_cent,
            'progresso' => $p->progresso ?? -1,
            default => mb_strtolower($p->nome),
        };

        // Ordenações estáveis: nome, depois o campo escolhido, depois favoritos primeiro.
        return $projetos
            ->sortBy(fn ($p) => mb_strtolower($p->nome))
            ->sortBy($chave, SORT_REGULAR, str_starts_with($this->ordem, '-'))
            ->sortBy(fn ($p) => $p->favorito ? 0 : 1)
            ->values();
    }

    /** Ação sobre um projeto (menu da linha) ou sobre os selecionados. */
    private function emMassa(string $acao, ?int $id, callable $mensagem): void
    {
        $this->erro = null;
        $ids = $id !== null ? [$id] : array_map('intval', $this->selecionados);

        try {
            $n = app(GestorProjetos::class)->{$acao}(auth()->user(), $ids);
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();

            return;
        } catch (AuthorizationException) {
            $this->erro = 'Não tem permissão para gerir projetos.';

            return;
        }

        $this->selecionados = [];
        session()->flash('sucesso', $mensagem($n));
    }

    private function normalizar(): void
    {
        foreach ([
            'mostrar' => [['ativos', 'arquivados', 'todos'], 'ativos'],
            'filtroAcesso' => [['', 'publico', 'privado'], ''],
            'filtroFaturacao' => [['', 'faturavel', 'nao'], ''],
        ] as $prop => [$validos, $omissao]) {
            if (! in_array($this->{$prop}, $validos, true)) {
                $this->{$prop} = $omissao;
            }
        }
        if ($this->filtroCliente !== 'sem' && ! ctype_digit($this->filtroCliente)) {
            $this->filtroCliente = '';
        }
        if (! in_array(ltrim($this->ordem, '-'), self::ORDENS, true)) {
            $this->ordem = 'nome';
        }
    }

    /** @return array{nome: string, cliente_id: string, cor: string, publico: bool, membros: list<string>, faturavel: bool, taxa: string, estimativa: string, nota: string} */
    private function formularioVazio(): array
    {
        return ['nome' => '', 'cliente_id' => '', 'cor' => ProjetoTempo::CORES[0], 'publico' => true, 'membros' => [], 'faturavel' => true, 'taxa' => '', 'estimativa' => '', 'nota' => ''];
    }
}

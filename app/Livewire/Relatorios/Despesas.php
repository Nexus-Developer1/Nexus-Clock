<?php

namespace App\Livewire\Relatorios;

use App\Livewire\Relatorios\Concerns\PeriodoEFiltros;
use App\Models\CategoriaDespesaTempo;
use App\Models\ClienteTempo;
use App\Models\DespesaTempo;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Tempos\GestorDespesas;
use App\Services\Tempos\RelatorioDespesas;
use App\Support\Dinheiro;
use App\Support\LimiteExportacoes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Relatório Despesas (como o "Expense report" do Clockify): as despesas dos Tempos no período, com
 * filtros (pessoas, cliente, projeto, categoria, estado, nota), total e faturável, ordenação e páginas;
 * nova despesa com recibo, alterar, apagar, aprovar e rejeitar (quem gere); categorias; exportar CSV,
 * descarregar os recibos num ZIP e imprimir. Quem não gere as despesas só vê as suas.
 */
#[Layout('components.layouts.app', ['ativo' => 'relatorios.despesas', 'titulo' => 'Despesas'])]
class Despesas extends Component
{
    use PeriodoEFiltros, WithFileUploads, WithPagination;

    public const POR_PAGINA = 50;

    /** @var list<string> */
    #[Url(as: 'categorias')]
    public array $categorias = [];

    #[Url(as: 'situacao')]
    public string $situacao = ''; // '' | pendente | aprovada | rejeitada

    #[Url(as: 'ordem')]
    public string $ordem = '-data';

    // Formulário (null = fechado, 0 = nova).
    public ?int $editarId = null;

    /** @var array{utilizador_id: string, data: string, projeto_id: string, categoria_id: string, valor: string, faturavel: bool, nota: string} */
    public array $formulario = [];

    public $recibo = null;

    public bool $retirarRecibo = false;

    // Rejeitar (com motivo).
    public ?int $rejeitarId = null;

    public string $motivo = '';

    // Detalhe só de leitura (carregar na linha, ou ?ver=<id> — o link do email ao aprovador). O id é só
    // um pedido: quem pode ver a despesa volta a verificar-se no render, por isso mexer nele pelo
    // browser não mostra a despesa de ninguém.
    #[Url(as: 'ver')]
    public ?int $verId = null;

    // Categorias (janela).
    public bool $categoriasAbertas = false;

    public string $novaCategoria = '';

    public ?string $erro = null;

    public function ordenarPor(string $campo): void
    {
        if (in_array($campo, RelatorioDespesas::ORDENS, true)) {
            $this->ordem = ltrim($this->ordem, '-') === $campo
                ? (str_starts_with($this->ordem, '-') ? $campo : '-'.$campo)
                : (in_array($campo, ['membro', 'projeto', 'categoria', 'estado'], true) ? $campo : '-'.$campo);
            $this->resetPage();
        }
    }

    public function limparFiltros(): void
    {
        $this->reset(['membros', 'clientes', 'projetos', 'categorias', 'situacao', 'descricao']);
        $this->resetPage();
    }

    // --- Nova / alterar ---

    public function nova(): void
    {
        $this->prepararFormulario();
        $this->editarId = 0;
        $this->formulario = [
            'utilizador_id' => (string) auth()->id(), 'data' => $this->hoje()->toDateString(), 'projeto_id' => '',
            'categoria_id' => '', 'valor' => '', 'faturavel' => false, 'nota' => '',
        ];
    }

    public function editar(int $id): void
    {
        $d = $this->despesa($id);
        // Ver as despesas dos outros não é poder alterá-las: o formulário nem abre (notas §41).
        abort_unless(app(GestorDespesas::class)->podeAlterar(auth()->user(), $d), 403);
        $this->prepararFormulario();
        $this->editarId = $d->id;
        $this->formulario = [
            'utilizador_id' => (string) $d->utilizador_id,
            'data' => $d->data->toDateString(),
            'projeto_id' => (string) $d->projeto_id,
            'categoria_id' => (string) $d->categoria_id,
            'valor' => Dinheiro::decimal($d->valor_cent),
            'faturavel' => $d->faturavel,
            'nota' => (string) $d->nota,
        ];
    }

    public function fecharFormulario(): void
    {
        $this->reset(['editarId', 'formulario', 'recibo', 'retirarRecibo']);
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        if ($this->editarId === null) {
            return;
        }
        $this->resetErrorBag();

        try {
            $this->validate(['recibo' => 'nullable|file|max:'.GestorDespesas::RECIBO_MAX_KB], [
                'recibo.max' => 'O recibo não pode passar de 10 MB.',
                'recibo.file' => 'Não foi possível carregar o recibo.',
                'recibo.uploaded' => 'Não foi possível carregar o recibo.',
            ]);
            $gestor = app(GestorDespesas::class);
            $this->editarId === 0
                ? $gestor->criar(auth()->user(), $this->formulario, $this->recibo)
                : $gestor->atualizar(auth()->user(), $this->despesa($this->editarId), $this->formulario, $this->recibo, $this->retirarRecibo);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError(str_starts_with($campo, 'recibo') ? 'recibo' : 'formulario.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException $e) {
            $this->addError('formulario.geral', $e->getMessage() ?: 'Não pode gravar esta despesa.');

            return;
        }

        $nova = $this->editarId === 0;
        $this->fecharFormulario();
        session()->flash('sucesso', $nova ? 'Despesa acrescentada.' : 'Despesa guardada.');
    }

    // --- Ações por despesa ---

    public function apagar(int $id): void
    {
        $this->executar(fn () => app(GestorDespesas::class)->apagar(auth()->user(), $this->despesa($id)), 'Despesa apagada.');
    }

    /** $versao: a da despesa que estava no ecrã — se mudou entretanto, não se aprova (notas §52). */
    public function aprovar(int $id, ?string $versao = null): void
    {
        $this->executar(fn () => app(GestorDespesas::class)->decidir(auth()->user(), $this->despesa($id), 'aprovada', versao: $versao), 'Despesa aprovada.');
    }

    public function reabrir(int $id): void
    {
        $this->executar(fn () => app(GestorDespesas::class)->decidir(auth()->user(), $this->despesa($id), 'pendente'), 'Despesa de novo pendente.');
    }

    public function ver(int $id): void
    {
        $this->verId = $this->despesa($id)->id;
    }

    public function fecharDetalhe(): void
    {
        $this->verId = null;
    }

    public function pedirRejeicao(int $id): void
    {
        abort_unless(app(GestorDespesas::class)->podeDecidir(auth()->user()), 403);
        $this->resetErrorBag();
        $this->rejeitarId = $id;
        $this->motivo = '';
    }

    public function rejeitar(): void
    {
        if (! $this->rejeitarId) {
            return;
        }
        try {
            app(GestorDespesas::class)->decidir(auth()->user(), $this->despesa($this->rejeitarId), 'rejeitada', $this->motivo);
        } catch (ValidationException $e) {
            $this->addError('motivo', collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset(['rejeitarId', 'motivo']);
        session()->flash('sucesso', 'Despesa rejeitada.');
    }

    // --- Categorias ---

    public function acrescentarCategoria(): void
    {
        $this->resetErrorBag('novaCategoria');
        try {
            app(GestorDespesas::class)->criarCategoria(auth()->user(), $this->novaCategoria);
        } catch (ValidationException $e) {
            $this->addError('novaCategoria', collect($e->errors())->flatten()->first());

            return;
        }
        $this->novaCategoria = '';
    }

    public function alternarCategoria(int $id): void
    {
        app(GestorDespesas::class)->alternarCategoria(auth()->user(), CategoriaDespesaTempo::findOrFail($id));
    }

    // --- Exportar ---

    public function exportar(string $formato = 'csv')
    {
        [$de, $ate] = $this->periodo();
        $linhas = $this->ocultarPrivados($this->consulta()->limit(20000)->get())->map(fn (DespesaTempo $d) => [
            $d->data->format('d/m/Y'), $d->utilizador?->nome ?? '', $d->projeto?->nome ?? '', $d->projeto?->cliente?->nome ?? '',
            $d->categoria?->nome ?? '', (string) $d->nota, Dinheiro::decimal($d->valor_cent), $d->faturavel ? 'Sim' : 'Não',
            DespesaTempo::ESTADOS[$d->estado], $d->recibo_caminho ? 'Sim' : 'Não',
        ]);

        return $this->descarregar($formato, 'despesas', 'Despesas', $de, $ate, ['Data', 'Membro', 'Projeto', 'Cliente', 'Categoria', 'Nota', 'Valor (€)', 'Faturável', 'Estado', 'Recibo'], $linhas->all());
    }

    /**
     * O ZIP monta-se no disco e o browser vai buscá-lo a um link assinado (ZipRecibosController): pela
     * ação do Livewire ia em base64 dentro da resposta, e um ZIP grande esgotava a memória (notas §52).
     */
    public function descarregarRecibos()
    {
        LimiteExportacoes::verificar('zip', LimiteExportacoes::ZIPS_POR_MINUTO);
        [$de, $ate] = $this->periodo();
        $this->erro = null;

        // ZIPs pedidos e nunca descarregados não ficam: os com mais de uma hora vão fora.
        $disco = Storage::disk(DespesaTempo::DISCO);
        foreach ($disco->files('zips') as $antigo) {
            if ($disco->lastModified($antigo) < now()->subHour()->getTimestamp()) {
                $disco->delete($antigo);
            }
        }
        $disco->makeDirectory('zips');
        $ficheiro = 'recibos-'.auth()->id().'-'.Str::random(32);

        try {
            $zip = app(RelatorioDespesas::class)->zipRecibos($this->consulta()->whereNotNull('recibo_caminho')->limit(2000)->cursor(), $disco->path('zips/'.$ficheiro.'.zip'));
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();

            return null;
        }
        if (! $zip) {
            $this->erro = 'Não há recibos nas despesas mostradas.';

            return null;
        }

        return redirect()->to(url()->temporarySignedRoute('despesas.recibos-zip', now()->addMinutes(10), [
            'ficheiro' => $ficheiro, 'nome' => 'recibos-'.$de->format('Ymd').'-'.$ate->format('Ymd').'.zip',
        ]));
    }

    public function render()
    {
        [$de, $ate] = $this->periodo();
        $gestor = app(GestorDespesas::class);
        $gere = $gestor->gere(auth()->user());

        // Detalhe: só se quem vê pode ver ESTA despesa (dona ou quem gere); apagada entretanto, fecha.
        $emDetalhe = $this->verId ? DespesaTempo::with(['utilizador:id,nome', 'projeto.cliente:id,nome', 'categoria:id,nome', 'decisor:id,nome'])->find($this->verId) : null;
        if ($emDetalhe && ! $gestor->podeVer(auth()->user(), $emDetalhe)) {
            $emDetalhe = null;
        }
        if (! $emDetalhe) {
            $this->verId = null;
        }
        $pagina = $this->consulta()->paginate(self::POR_PAGINA);
        $this->ocultarPrivados(collect($pagina->items())->when($emDetalhe, fn ($c) => $c->push($emDetalhe)));

        return view('livewire.relatorios.despesas', [
            'emDetalhe' => $emDetalhe,
            'nomesRegisto' => $emDetalhe ? User::whereIn('id', array_filter([$emDetalhe->criado_por, $emDetalhe->alterado_por]))->pluck('nome', 'id') : collect(),
            'pagina' => $pagina,
            'totais' => app(RelatorioDespesas::class)->totais($this->filtros(), $de, $ate),
            'de' => $de,
            'ate' => $ate,
            'rotuloPeriodo' => $this->rotuloPeriodo($de, $ate),
            'podeVerEquipa' => $gestor->veTodas(auth()->user()), // filtro Equipa e coluna Membro
            'podeDecidir' => $gestor->podeDecidir(auth()->user()), // aprovar, rejeitar, voltar a pendente
            'gere' => $gere,
            'gestor' => $gestor,
            'opcoes' => [
                'membros' => User::comAcessoAosTempos()->orderBy('nome')->pluck('nome', 'id')->all(),
                'clientes' => ClienteTempo::orderByRaw('lower(nome)')->pluck('nome', 'id')->all(),
                'projetos' => [0 => 'Sem projeto'] + ProjetoTempo::visiveisPara(auth()->user())->orderByRaw('arquivado_em is not null, lower(nome)')->pluck('nome', 'id')->all(),
                'categorias' => CategoriaDespesaTempo::orderByRaw('arquivada_em is not null, lower(nome)')->pluck('nome', 'id')->all(),
            ],
            'estados' => DespesaTempo::ESTADOS,
            'filtrosAtivos' => ($gere ? count($this->membros) : 0) + count($this->clientes) + count($this->projetos) + count($this->categorias)
                + ($this->situacao !== '' ? 1 : 0) + (trim($this->descricao) !== '' ? 1 : 0),
            // Formulário
            'membrosFormulario' => $gere ? User::comAcessoAosTempos()->orderBy('nome')->pluck('nome', 'id')->all() : [auth()->id() => auth()->user()->nome],
            'projetosFormulario' => $this->editarId !== null
                ? ProjetoTempo::visiveisPara(auth()->user())->where(fn ($q) => $q->whereNull('arquivado_em')->orWhere('id', (int) ($this->formulario['projeto_id'] ?? 0)))->orderByRaw('lower(nome)')->pluck('nome', 'id')->all()
                : [],
            'categoriasFormulario' => $this->editarId !== null
                ? CategoriaDespesaTempo::where(fn ($q) => $q->whereNull('arquivada_em')->orWhere('id', (int) ($this->formulario['categoria_id'] ?? 0)))->orderByRaw('lower(nome)')->pluck('nome', 'id')->all()
                : [],
            'emEdicao' => $this->editarId ? DespesaTempo::find($this->editarId) : null,
            'todasCategorias' => $this->categoriasAbertas ? CategoriaDespesaTempo::orderByRaw('arquivada_em is not null, lower(nome)')->get() : collect(),
        ]);
    }

    protected function filtrosMudaram(string $propriedade): void
    {
        if (in_array(strtok($propriedade, '.'), ['tipo', 'inicio', 'fim', 'periodo', 'filtros', 'membros', 'clientes', 'projetos', 'categorias', 'situacao', 'descricao'], true)) {
            $this->resetPage();
        }
    }

    private function filtros(): array
    {
        $f = $this->filtrosDoServico();

        // Quem vê as despesas da equipa escolhe as pessoas no filtro (vazio = todas); quem não vê, só as suas.
        // (O filtrosDoServico() prende o técnico às suas horas — nas despesas a regra é outra, notas §41.)
        // Um projeto que não pode ver, posto à mão no filtro, não filtra por ele (não serve para sondar).
        $projetos = $f['projetos'];
        if ($projetos !== [] && ($visiveis = $this->projetosVisiveis()) !== null) {
            $projetos = array_values(array_filter($projetos, fn ($id) => $id === 0 || isset($visiveis[$id]))) ?: [-1];
        }

        return [
            'membros' => app(GestorDespesas::class)->veTodas(auth()->user())
                ? ($this->membros === [] ? null : array_map('intval', $this->membros))
                : [auth()->id()],
            'clientes' => $f['clientes'],
            'projetos' => $projetos,
            'categorias' => array_map('intval', $this->categorias),
            'estado' => $this->situacao,
            'nota' => $this->descricao,
        ];
    }

    private function consulta()
    {
        [$de, $ate] = $this->periodo();

        return app(RelatorioDespesas::class)->consulta($this->filtros(), $de, $ate, $this->ordem);
    }

    /** @var array<int, int>|null ids dos projetos que quem vê pode ver (null = todos); por pedido. */
    private ?array $visiveis = null;

    private bool $visiveisCalculados = false;

    /** @return array<int, int>|null */
    private function projetosVisiveis(): ?array
    {
        if (! $this->visiveisCalculados) {
            $quem = auth()->user();
            $this->visiveis = $quem->ehAdminTempos() ? null
                : ProjetoTempo::withTrashed()->visiveisPara($quem)->pluck('id')->map(fn ($id) => (int) $id)->flip()->all();
            $this->visiveisCalculados = true;
        }

        return $this->visiveis;
    }

    /**
     * As despesas dos colegas em projetos privados de que quem vê não é membro mostram «Projeto
     * privado», sem nome nem cliente — na lista, no detalhe e nas exportações (notas §52).
     *
     * @template T of iterable<DespesaTempo>
     *
     * @param  T  $despesas
     * @return T
     */
    private function ocultarPrivados(iterable $despesas): iterable
    {
        $visiveis = $this->projetosVisiveis();
        if ($visiveis !== null) {
            foreach ($despesas as $d) {
                if ($d->projeto_id && ! isset($visiveis[(int) $d->projeto_id])) {
                    $d->setRelation('projeto', ProjetoTempo::mascarado());
                }
            }
        }

        return $despesas;
    }

    private function despesa(int $id): DespesaTempo
    {
        $d = DespesaTempo::findOrFail($id);
        abort_unless(app(GestorDespesas::class)->podeVer(auth()->user(), $d), 403);

        return $d;
    }

    private function prepararFormulario(): void
    {
        $this->resetErrorBag();
        $this->reset(['recibo', 'retirarRecibo']);
        $this->erro = null;
    }

    private function executar(callable $acao, string $mensagem): void
    {
        $this->erro = null;
        try {
            $acao();
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();

            return;
        } catch (AuthorizationException $e) {
            $this->erro = $e->getMessage() ?: 'Não tem permissão para esta ação.';

            return;
        }
        session()->flash('sucesso', $mensagem);
    }

    private function normalizar(): void
    {
        $this->normalizarPeriodoEFiltros();

        $this->categorias = array_values(array_unique(array_filter(array_map('strval', (array) $this->categorias), 'ctype_digit')));
        if (! isset(DespesaTempo::ESTADOS[$this->situacao])) {
            $this->situacao = '';
        }
        if (! in_array(ltrim($this->ordem, '-'), RelatorioDespesas::ORDENS, true)) {
            $this->ordem = '-data';
        }
    }
}

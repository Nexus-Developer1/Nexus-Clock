<?php

namespace App\Livewire\Equipa;

use App\Enums\PapelEquipa;
use App\Models\GrupoEquipa;
use App\Models\MembroEquipa;
use App\Models\TaxaMembro;
use App\Services\Tempos\GestorEquipa;
use App\Services\Tempos\LeitorDuracao;
use App\Support\Csv;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Equipa › Membros (plenos): as pessoas com acesso aos Tempos, com email, taxa faturável e taxa de
 * custo (com histórico, "Mudar"), papel, grupos e — se escolhidos no menu Filtros — início da semana,
 * dias de trabalho, capacidade diária e gestor de equipa atribuído. Filtros por papel, grupo e taxas, pesquisa e
 * exportação CSV. Acrescentar membros plenos faz-se no portal. A subclasse Limitados mostra os
 * membros sem conta, que se criam aqui.
 */
#[Layout('components.layouts.app', ['ativo' => 'equipa', 'titulo' => 'Equipa'])]
class Membros extends Component
{
    protected bool $limitados = false;

    // Campos que se podem mostrar (coluna e filtro), como no menu "Filtros" do Clockify.
    public const CAMPOS = [
        'faturavel' => 'Taxa faturável',
        'custo' => 'Taxa de custo',
        'papel' => 'Papel',
        'grupo' => 'Grupo',
        'inicio_semana' => 'Início da semana',
        'dias_trabalho' => 'Dias de trabalho',
        'capacidade' => 'Capacidade diária',
        'gestor' => 'Gestor de equipa atribuído',
    ];

    public const CAMPOS_POR_OMISSAO = ['faturavel', 'custo', 'papel', 'grupo'];

    /** @var list<string> campos à vista (guardado na sessão de quem está a ver) */
    #[Session(key: 'equipa-campos')]
    public array $campos = self::CAMPOS_POR_OMISSAO;

    #[Url(as: 'inicio')]
    public string $filtroInicioSemana = '';

    #[Url(as: 'dia')]
    public string $filtroDia = '';

    #[Url(as: 'capacidade')]
    public string $filtroCapacidade = ''; // '' | com | sem

    #[Url(as: 'gestor')]
    public string $filtroGestor = ''; // '' | id | sem

    #[Url(as: 'papel')]
    public string $filtroPapel = '';

    #[Url(as: 'grupo')]
    public string $filtroGrupo = '';

    #[Url(as: 'faturavel')]
    public string $filtroFaturavel = ''; // '' | com | sem

    #[Url(as: 'custo')]
    public string $filtroCusto = '';

    #[Url(as: 'q')]
    public string $pesquisa = '';

    #[Url(as: 'ordem')]
    public string $ordem = 'nome'; // nome | -nome | email | -email

    /** @var list<string> */
    public array $selecionados = [];

    // Mudar taxa (janela).
    // Janela da taxa: só o servidor abre (abrirTaxa, que exige gerir a equipa) — o browser não mexe
    // nestes dois campos, e o render volta a exigir a permissão (notas §42).
    #[Locked]
    public ?int $taxaMembroId = null;

    #[Locked]
    public string $taxaTipo = 'faturavel';

    public string $taxaValor = '';

    public string $taxaApartir = '';

    // Membro limitado: novo e alterar.
    public string $novoNome = '';

    public string $novoEmail = '';

    public ?int $editarId = null;

    public string $editarNome = '';

    public string $editarEmail = '';

    public ?string $erro = null;

    public function mount(): void
    {
        app(GestorEquipa::class)->sincronizar();
    }

    /** Mostra ou esconde um campo (coluna e filtro). Esconder limpa o filtro desse campo. */
    public function alternarCampo(string $campo): void
    {
        if (! isset(self::CAMPOS[$campo])) {
            return;
        }

        $this->campos = in_array($campo, $this->campos, true)
            ? array_values(array_diff($this->campos, [$campo]))
            : array_values(array_intersect(array_keys(self::CAMPOS), [...$this->campos, $campo]));

        if (! in_array($campo, $this->campos, true)) {
            match ($campo) {
                'faturavel' => $this->filtroFaturavel = '',
                'custo' => $this->filtroCusto = '',
                'papel' => $this->filtroPapel = '',
                'grupo' => $this->filtroGrupo = '',
                'inicio_semana' => $this->filtroInicioSemana = '',
                'dias_trabalho' => $this->filtroDia = '',
                'capacidade' => $this->filtroCapacidade = '',
                'gestor' => $this->filtroGestor = '',
            };
        }
    }

    public function camposPorOmissao(): void
    {
        $this->campos = self::CAMPOS_POR_OMISSAO;
        $this->reset(['filtroInicioSemana', 'filtroDia', 'filtroCapacidade', 'filtroGestor']);
    }

    // --- Campos de trabalho ---

    public function mudarCampo(int $membroId, string $campo, mixed $valor): void
    {
        $this->executar(fn () => app(GestorEquipa::class)->atualizarCampo(auth()->user(), $this->membro($membroId), $campo, $valor));
    }

    public function alternarDia(int $membroId, int $dia): void
    {
        $this->executar(fn () => app(GestorEquipa::class)->alternarDiaTrabalho(auth()->user(), $this->membro($membroId), $dia));
    }

    public function ordenarPor(string $campo): void
    {
        $this->ordem = $this->ordem === $campo ? '-'.$campo : $campo;
    }

    // --- Papel e grupos ---

    public function mudarPapel(int $membroId, string $papel): void
    {
        $this->executar(function () use ($membroId, $papel) {
            $membro = $this->membro($membroId);
            app(GestorEquipa::class)->mudarPapel(auth()->user(), $membro, $papel);
            session()->flash('sucesso', $membro->nomeVisivel().' passa a '.PapelEquipa::from($papel)->rotulo().'.');
        });
    }

    public function alternarGrupo(int $membroId, int $grupoId): void
    {
        $this->executar(function () use ($membroId, $grupoId) {
            app(GestorEquipa::class)->alternarGrupo(auth()->user(), $this->membro($membroId), GrupoEquipa::findOrFail($grupoId));
        });
    }

    public function porNoGrupo(int $grupoId): void
    {
        $this->executar(function () use ($grupoId) {
            $grupo = GrupoEquipa::findOrFail($grupoId);
            $ids = array_values(array_unique(array_merge($grupo->membros()->pluck('membros_equipa.id')->all(), array_map('intval', $this->selecionados))));
            app(GestorEquipa::class)->definirMembrosDoGrupo(auth()->user(), $grupo, $ids);
            $n = count($this->selecionados);
            $this->selecionados = [];
            session()->flash('sucesso', ($n === 1 ? '1 membro' : "$n membros").' no grupo «'.$grupo->nome.'».');
        });
    }

    // --- Taxas ---

    public function abrirTaxa(int $membroId, string $tipo): void
    {
        $membro = $this->membro($membroId);
        abort_unless(Gate::allows('tempos-gerir-equipa') && isset(TaxaMembro::TIPOS[$tipo]), 403);

        $this->resetErrorBag();
        $this->taxaMembroId = $membro->id;
        $this->taxaTipo = $tipo;
        $this->taxaValor = Dinheiro::decimal($membro->taxaEm($tipo, $this->hoje()));
        $this->taxaApartir = $this->hoje()->toDateString();
    }

    public function fecharTaxa(): void
    {
        $this->reset(['taxaMembroId', 'taxaValor', 'taxaApartir']);
        $this->resetErrorBag();
    }

    public function guardarTaxa(): void
    {
        if (! $this->taxaMembroId) {
            return;
        }

        try {
            app(GestorEquipa::class)->mudarTaxa(auth()->user(), $this->membro($this->taxaMembroId), $this->taxaTipo, $this->taxaValor, $this->taxaApartir);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('taxa.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('taxa.valor', 'Não pode mudar taxas.');

            return;
        }

        $this->fecharTaxa();
        session()->flash('sucesso', TaxaMembro::TIPOS[$this->taxaTipo].' guardada.');
    }

    // --- Limitados ---

    public function acrescentarLimitado(): void
    {
        $this->resetErrorBag();

        try {
            $membro = app(GestorEquipa::class)->criarLimitado(auth()->user(), $this->novoNome, $this->novoEmail);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('novo.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('novo.nome', 'Não pode acrescentar membros.');

            return;
        }

        $this->reset(['novoNome', 'novoEmail']);
        session()->flash('sucesso', 'Membro limitado «'.$membro->nome.'» acrescentado.');
        $this->dispatch('limitado-acrescentado');
    }

    public function editarLimitado(int $membroId): void
    {
        $membro = $this->membro($membroId);
        $this->resetErrorBag();
        $this->editarId = $membro->id;
        $this->editarNome = (string) $membro->nome;
        $this->editarEmail = (string) $membro->email;
    }

    public function fecharEdicao(): void
    {
        $this->reset(['editarId', 'editarNome', 'editarEmail']);
        $this->resetErrorBag();
    }

    public function guardarLimitado(): void
    {
        if (! $this->editarId) {
            return;
        }

        try {
            app(GestorEquipa::class)->atualizarLimitado(auth()->user(), $this->membro($this->editarId), $this->editarNome, $this->editarEmail);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('editar.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('editar.nome', 'Não pode alterar membros.');

            return;
        }

        $this->fecharEdicao();
        session()->flash('sucesso', 'Membro guardado.');
    }

    public function apagarLimitado(int $membroId): void
    {
        $this->executar(function () use ($membroId) {
            $membro = $this->membro($membroId);
            app(GestorEquipa::class)->apagarLimitado(auth()->user(), $membro);
            session()->flash('sucesso', 'Membro «'.$membro->nome.'» apagado.');
        });
    }

    // --- Exportar ---

    public function exportar()
    {
        abort_unless(Gate::allows('tempos-gerir-equipa'), 403);

        $linhas = $this->membros()->map(fn (MembroEquipa $m) => [
            $m->nomeVisivel(), $m->emailVisivel(),
            Dinheiro::decimal($m->taxaEm('faturavel', $this->hoje())), Dinheiro::decimal($m->taxaEm('custo', $this->hoje())),
            $m->papel->rotulo(), $m->grupos->pluck('nome')->implode(', '),
            MembroEquipa::DIAS[$m->inicio_semana], $m->rotuloDiasTrabalho(),
            $m->capacidade_diaria_seg ? LeitorDuracao::formatar($m->capacidade_diaria_seg) : '', $m->gestor?->nomeVisivel() ?? '',
        ]);

        return Csv::resposta(($this->limitados ? 'equipa-limitados-' : 'equipa-').$this->hoje()->format('Ymd').'.csv',
            ['Nome', 'Email', 'Taxa faturável (€/h)', 'Taxa de custo (€/h)', 'Papel', 'Grupos', 'Início da semana', 'Dias de trabalho', 'Capacidade diária', 'Gestor de equipa'], $linhas->all());
    }

    public function render()
    {
        $podeGerir = Gate::allows('tempos-gerir-equipa');
        $membroTaxa = $this->taxaMembroId && $podeGerir && isset(TaxaMembro::TIPOS[$this->taxaTipo])
            ? MembroEquipa::with(['utilizador', 'taxas.criadoPor'])->find($this->taxaMembroId)
            : null;

        return view('livewire.equipa.membros', [
            'membros' => $this->membros(),
            'limitados' => $this->limitados,
            'podeGerir' => $podeGerir,
            'hoje' => $this->hoje(),
            'papeis' => PapelEquipa::cases(),
            'grupos' => GrupoEquipa::orderByRaw('lower(nome)')->get(['id', 'nome']),
            'membroTaxa' => $membroTaxa,
            'historicoTaxa' => $membroTaxa ? $membroTaxa->taxas->where('tipo', $this->taxaTipo)->sortByDesc(fn ($t) => $t->valido_de->toDateString())->values() : collect(),
            'portalUrl' => rtrim((string) config('app.portal_url'), '/').'/',
            'camposDisponiveis' => $podeGerir ? self::CAMPOS : array_diff_key(self::CAMPOS, ['faturavel' => 1, 'custo' => 1]),
            'ver' => fn (string $campo) => in_array($campo, $this->campos, true) && ($podeGerir || ! in_array($campo, ['faturavel', 'custo'], true)),
            'dias' => MembroEquipa::DIAS,
            'gestores' => MembroEquipa::query()->with('utilizador:id,nome')->where('papel', PapelEquipa::GestorEquipa)
                ->where(fn ($q) => $q->comAcesso()->orWhere('limitado', true))->get()
                ->sortBy(fn (MembroEquipa $g) => mb_strtolower($g->nomeVisivel()))->values(),
        ]);
    }

    /** @return Collection<int, MembroEquipa> */
    protected function membros(): Collection
    {
        $termo = mb_strtolower(trim($this->pesquisa));
        $hoje = $this->hoje();

        $membros = MembroEquipa::query()
            ->with(['utilizador:id,nome,email', 'grupos:id,nome', 'taxas', 'gestor.utilizador:id,nome'])
            ->when($this->limitados, fn ($q) => $q->limitados(), fn ($q) => $q->comAcesso())
            ->when(PapelEquipa::tryFrom($this->filtroPapel), fn ($q, $p) => $q->where('papel', $p->value))
            ->when(ctype_digit($this->filtroGrupo), fn ($q) => $q->whereHas('grupos', fn ($g) => $g->whereKey((int) $this->filtroGrupo)))
            ->when(ctype_digit($this->filtroInicioSemana), fn ($q) => $q->where('inicio_semana', (int) $this->filtroInicioSemana))
            ->when(ctype_digit($this->filtroDia), fn ($q) => $q->whereRaw('? = any(dias_trabalho)', [(int) $this->filtroDia]))
            ->when($this->filtroCapacidade === 'com', fn ($q) => $q->whereNotNull('capacidade_diaria_seg'))
            ->when($this->filtroCapacidade === 'sem', fn ($q) => $q->whereNull('capacidade_diaria_seg'))
            ->when(ctype_digit($this->filtroGestor), fn ($q) => $q->where('gestor_id', (int) $this->filtroGestor))
            ->when($this->filtroGestor === 'sem', fn ($q) => $q->whereNull('gestor_id'))
            ->get()
            ->filter(fn (MembroEquipa $m) => $termo === ''
                || str_contains(mb_strtolower($m->nomeVisivel()), $termo)
                || str_contains(mb_strtolower((string) $m->emailVisivel()), $termo));

        foreach (['faturavel' => $this->filtroFaturavel, 'custo' => $this->filtroCusto] as $tipo => $filtro) {
            if (in_array($filtro, ['com', 'sem'], true)) {
                $membros = $membros->filter(fn (MembroEquipa $m) => ($m->taxaEm($tipo, $hoje) !== null) === ($filtro === 'com'));
            }
        }

        $campo = ltrim($this->ordem, '-') === 'email' ? fn (MembroEquipa $m) => mb_strtolower((string) $m->emailVisivel()) : fn (MembroEquipa $m) => mb_strtolower($m->nomeVisivel());

        return $membros->sortBy($campo, SORT_NATURAL, str_starts_with($this->ordem, '-'))->values();
    }

    protected function membro(int $id): MembroEquipa
    {
        return MembroEquipa::with('utilizador')->findOrFail($id);
    }

    protected function hoje(): CarbonImmutable
    {
        return CarbonImmutable::now(config('tempos.fuso'))->startOfDay();
    }

    protected function executar(callable $acao): void
    {
        $this->erro = null;

        try {
            $acao();
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();
        } catch (AuthorizationException) {
            $this->erro = 'Não tem permissão para gerir a equipa.';
        }
    }
}

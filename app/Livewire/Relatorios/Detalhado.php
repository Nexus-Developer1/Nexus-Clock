<?php

namespace App\Livewire\Relatorios;

use App\Livewire\Concerns\FormularioRegisto;
use App\Livewire\Relatorios\Concerns\PeriodoEFiltros;
use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Services\Tempos\EdicaoEmMassa;
use App\Services\Tempos\GravadorRegistos;
use App\Services\Tempos\PainelTempos;
use App\Services\Tempos\ResumoTempos;
use App\Support\Csv;
use App\Support\Dinheiro;
use App\Support\Horas;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Relatório Detalhado (como o "Detailed report" do Clockify): os registos um a um no período e com os
 * filtros (PeriodoEFiltros), com auditoria de tempo (sem projeto, sem descrição, sem etiquetas, com
 * mais de 8 h), ordenação, páginas, total/faturável/valor, alterar, duplicar, apagar (ou anular, se
 * faturado), acrescentar tempo (para outros, quem gere) e edição em massa; exportar CSV e imprimir.
 *
 * Toda a escrita passa pelo GravadorRegistos e pela EdicaoEmMassa (semanas entregues, meses fechados,
 * faturados). Quem não vê a equipa só vê (e mexe) nas suas horas e não vê valores.
 */
#[Layout('components.layouts.app', ['ativo' => 'relatorios.detalhado', 'titulo' => 'Detalhado'])]
class Detalhado extends Component
{
    use FormularioRegisto, PeriodoEFiltros, WithPagination;

    public const POR_PAGINA = 50;

    public const ORDENS = ['data', 'duracao', 'membro', 'descricao', 'valor'];

    private const FILTROS = ['tipo', 'inicio', 'fim', 'periodo', 'filtros', 'membros', 'clientes', 'projetos', 'etiquetas', 'estado', 'descricao', 'auditoria'];

    #[Url(as: 'auditoria')]
    public string $auditoria = '';

    #[Url(as: 'ordem')]
    public string $ordem = '-data';

    #[Url(as: 'valor')]
    public string $mostrarValor = 'faturavel'; // faturavel | custo | lucro | nao

    /** @var list<string> */
    public array $selecionados = [];

    // --- Edição em massa ---

    public bool $massaAberta = false;

    public string $massaProjeto = ''; // '' manter | '0' sem projeto | id

    public string $massaFaturavel = ''; // '' manter | sim | nao

    public string $massaAcrescentar = '';

    public string $massaRetirar = '';

    // --- Anular (registo faturado) ---

    public ?int $anularId = null;

    public string $motivo = '';

    public function ordenarPor(string $campo): void
    {
        if (in_array($campo, self::ORDENS, true)) {
            $this->ordem = ltrim($this->ordem, '-') === $campo
                ? (str_starts_with($this->ordem, '-') ? $campo : '-'.$campo)
                : (in_array($campo, ['membro', 'descricao'], true) ? $campo : '-'.$campo);
            $this->resetPage();
        }
    }

    // --- Ações por registo ---

    public function apagar(int $id): void
    {
        $this->executar(function () use ($id) {
            app(GravadorRegistos::class)->apagar(auth()->user(), RegistoTempo::findOrFail($id));
            $this->selecionados = array_values(array_diff($this->selecionados, [(string) $id]));
            session()->flash('sucesso', 'Registo apagado.');
        });
    }

    public function pedirAnulacao(int $id): void
    {
        $this->erro = null;
        $this->resetErrorBag();
        $this->anularId = $id;
        $this->motivo = '';
    }

    public function anular(): void
    {
        if (! $this->anularId) {
            return;
        }

        try {
            app(GravadorRegistos::class)->anular(auth()->user(), RegistoTempo::findOrFail($this->anularId), $this->motivo);
        } catch (ValidationException $e) {
            $this->addError('motivo', collect($e->errors())->flatten()->first());

            return;
        } catch (AuthorizationException) {
            $this->addError('motivo', 'Só um administrador anula registos faturados.');

            return;
        }

        $this->reset(['anularId', 'motivo']);
        session()->flash('sucesso', 'Registo anulado.');
    }

    // --- Seleção e massa ---

    public function limparSelecao(): void
    {
        $this->reset(['selecionados', 'massaAberta', 'massaProjeto', 'massaFaturavel', 'massaAcrescentar', 'massaRetirar']);
    }

    public function aplicarMassa(): void
    {
        $this->executar(function () {
            $n = app(EdicaoEmMassa::class)->aplicar(auth()->user(), array_map('intval', $this->selecionados), [
                'projeto' => $this->massaProjeto === '' ? null : (int) $this->massaProjeto,
                'faturavel' => match ($this->massaFaturavel) {
                    'sim' => true,
                    'nao' => false,
                    default => null,
                },
                'etiquetas_acrescentar' => self::lerEtiquetas($this->massaAcrescentar),
                'etiquetas_retirar' => self::lerEtiquetas($this->massaRetirar),
            ]);
            $this->limparSelecao();
            session()->flash('sucesso', $n.' '.($n === 1 ? 'registo alterado' : 'registos alterados').'.');
        });
    }

    public function apagarMassa(): void
    {
        $this->executar(function () {
            $n = app(EdicaoEmMassa::class)->apagar(auth()->user(), array_map('intval', $this->selecionados));
            $this->limparSelecao();
            session()->flash('sucesso', $n.' '.($n === 1 ? 'registo apagado' : 'registos apagados').'.');
        });
    }

    public function exportar(string $formato = 'csv')
    {
        [$de, $ate] = $this->periodo();
        $comValor = $this->valorVisivel();
        $linhas = [];

        $this->consulta($de, $ate)->limit(20000)->get()->chunk(1000)->each(function (Collection $bloco) use (&$linhas, $comValor) {
            $modelos = $this->modelos($bloco->pluck('id')->all());
            foreach ($bloco as $l) {
                $r = $modelos[$l->id];
                $horas = self::temHorasReais($r);
                $local = fn (?CarbonImmutable $d) => $d?->setTimezone(config('tempos.fuso'))->format('H:i');
                $linhas[] = array_merge([
                    $r->dia()->format('d/m/Y'), $horas ? $local($r->inicio) : '', $horas ? $local($r->fim) : '',
                    Horas::decimal($r->duracao_seg), PainelTempos::hms((int) $r->duracao_seg),
                    $r->tecnico?->nome ?? '', $r->projeto?->cliente?->nome ?? '', $r->projeto?->nome ?? '',
                    (string) $r->descricao, implode(', ', $r->etiquetas), $l->fat ? 'Sim' : 'Não',
                ], $comValor ? [Dinheiro::decimal($this->valorDe($l))] : []);
            }
        });

        return $this->descarregar($formato, 'detalhado', 'Detalhado', $de, $ate, array_merge(
            ['Dia', 'Início', 'Fim', 'Duração (h)', 'Duração', 'Membro', 'Cliente', 'Projeto', 'Descrição', 'Etiquetas', 'Faturável'],
            $comValor ? [$this->rotuloValor().' (€)'] : [],
        ), $linhas);
    }

    public function render()
    {
        [$de, $ate] = $this->periodo();
        $servico = app(ResumoTempos::class);
        $pagina = $this->consulta($de, $ate)->paginate(self::POR_PAGINA);
        $modelos = $this->modelos(collect($pagina->items())->pluck('id')->all());
        $totais = $servico->totais(auth()->user(), $this->filtros(), $de, $ate);

        // O projeto e o cliente de cada linha abrem a página deles (notas §50), só se quem vê a puder
        // abrir: um projeto privado de que não é membro, ou um projeto/cliente apagado, dariam 404.
        $idsProjetos = $modelos->pluck('projeto_id')->filter()->unique()->all();
        $idsClientes = $modelos->map(fn (RegistoTempo $r) => $r->projeto?->cliente_id)->filter()->unique()->all();

        return view('livewire.relatorios.detalhado', [
            'pagina' => $pagina,
            'abreProjeto' => ProjetoTempo::visiveisPara(auth()->user())->whereKey($idsProjetos)->pluck('id')->flip()->all(),
            'abreCliente' => ClienteTempo::whereKey($idsClientes)->pluck('id')->flip()->all(),
            'linhas' => collect($pagina->items())->map(fn ($l) => ['r' => $modelos[$l->id], 'fat' => (bool) $l->fat, 'valor' => $this->valorDe($l)]),
            'totais' => $totais,
            'valorTotal' => $this->valorDe((object) $totais),
            'de' => $de,
            'ate' => $ate,
            'rotuloPeriodo' => $this->rotuloPeriodo($de, $ate),
            'podeVerEquipa' => Gate::allows('tempos-ver-todos'),
            'podeAnular' => auth()->user()->ehAdminTempos(),
            'comValor' => $this->valorVisivel(),
            'rotuloValor' => $this->rotuloValor(),
            'opcoes' => $servico->opcoes(auth()->user()),
            'estados' => ResumoTempos::ESTADOS,
            'auditorias' => ResumoTempos::AUDITORIA,
            'filtrosAtivos' => $this->contarFiltros() + ($this->auditoria !== '' ? 1 : 0),
            'projetosMassa' => ProjetoTempo::visiveisPara(auth()->user())->ativos()->orderByRaw('lower(nome)')->pluck('nome', 'id')->all(),
            // Só soma registos que quem vê pode ver: a seleção vem do browser (notas §42).
            'segundosSelecionados' => $this->selecionados === [] ? 0 : (int) RegistoTempo::whereKey(array_map('intval', $this->selecionados))
                ->when(! Gate::allows('tempos-ver-todos'), fn ($q) => $q->where('tecnico_id', auth()->id()))
                ->sum('duracao_seg'),
        ] + $this->dadosDoFormulario());
    }

    protected function filtrosMudaram(string $propriedade): void
    {
        foreach (self::FILTROS as $filtro) {
            if (str_starts_with($propriedade, $filtro)) {
                $this->resetPage();
                $this->selecionados = [];

                return;
            }
        }
    }

    // --- internos ---

    private function consulta(CarbonImmutable $de, CarbonImmutable $ate)
    {
        return app(ResumoTempos::class)->registos(auth()->user(), $this->filtros(), $de, $ate, $this->ordem);
    }

    private function filtros(): array
    {
        return $this->filtrosDoServico() + ['auditoria' => $this->auditoria];
    }

    /** @return Collection<int, RegistoTempo> por id */
    private function modelos(array $ids): Collection
    {
        return RegistoTempo::with(['tecnico:id,nome', 'projeto:id,nome,cor,arquivado_em,cliente_id', 'projeto.cliente:id,nome'])
            ->whereKey($ids)->get()->keyBy('id');
    }

    private function valorDe(object $linha): int
    {
        return match ($this->mostrarValor) {
            'custo' => (int) $linha->custo,
            'lucro' => (int) $linha->valor - (int) $linha->custo,
            default => (int) $linha->valor,
        };
    }

    private function valorVisivel(): bool
    {
        return Gate::allows('tempos-ver-todos') && $this->mostrarValor !== 'nao';
    }

    private function rotuloValor(): string
    {
        return match ($this->mostrarValor) {
            'custo' => 'Custo',
            'lucro' => 'Lucro',
            default => 'Valor',
        };
    }

    private function normalizar(): void
    {
        $this->normalizarPeriodoEFiltros();

        if (! isset(ResumoTempos::AUDITORIA[$this->auditoria])) {
            $this->auditoria = '';
        }
        if (! in_array(ltrim($this->ordem, '-'), self::ORDENS, true)) {
            $this->ordem = '-data';
        }
        if (! in_array($this->mostrarValor, ['faturavel', 'custo', 'lucro', 'nao'], true)) {
            $this->mostrarValor = 'faturavel';
        }
    }
}

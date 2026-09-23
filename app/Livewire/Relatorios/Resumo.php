<?php

namespace App\Livewire\Relatorios;

use App\Livewire\Relatorios\Concerns\PeriodoEFiltros;
use App\Models\ProjetoTempo;
use App\Models\RelatorioPartilhado;
use App\Services\Tempos\GestorPartilhados;
use App\Services\Tempos\PainelTempos;
use App\Services\Tempos\ResumoTempos;
use App\Support\Csv;
use App\Support\Dinheiro;
use App\Support\Horas;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatório Resumo (como o "Summary report" do Clockify): período e filtros (PeriodoEFiltros); total,
 * faturável e valor; gráfico por dia (ou mês) por faturabilidade ou pelo 1.º agrupamento; tabela
 * agrupada por dois critérios com estimativa dos projetos; anel; exportar CSV e imprimir.
 * Quem não vê a equipa só vê as suas horas e não vê valores.
 */
#[Layout('components.layouts.app', ['ativo' => 'relatorios.resumo', 'titulo' => 'Resumo'])]
class Resumo extends Component
{
    use PeriodoEFiltros;

    #[Url(as: 'agrupar')]
    public string $agrupar1 = 'projeto';

    #[Url(as: 'depois')]
    public string $agrupar2 = 'descricao';

    #[Url(as: 'cor')]
    public string $cor = 'faturabilidade'; // faturabilidade | grupo

    #[Url(as: 'valor')]
    public string $mostrarValor = 'faturavel'; // faturavel | custo | lucro | nao

    #[Url(as: 'ordem')]
    public string $ordem = '-duracao'; // titulo | duracao | valor, "-" = descendente

    public bool $estimativa = false;

    // Partilhar (modal): null = fechado.
    public ?bool $partilhaAberta = null;

    /** @var array{nome: string, publico: bool, sempre_atual: bool, bloquear_datas: bool, email_ativo: bool, email_destinatarios: string, email_frequencia: string, email_hora: string} */
    public array $partilha = [];

    public ?string $linkCriado = null;

    public function abrirPartilha(): void
    {
        $this->resetErrorBag();
        $this->linkCriado = null;
        $this->partilhaAberta = true;
        $this->partilha = [
            'nome' => '', 'publico' => '1', 'sempre_atual' => $this->tipo !== 'datas', 'bloquear_datas' => false,
            'email_ativo' => false, 'email_destinatarios' => (string) $this->autor()->email, 'email_frequencia' => 'semanal', 'email_hora' => '8',
        ];
    }

    public function fecharPartilha(): void
    {
        $this->reset(['partilhaAberta', 'partilha', 'linkCriado']);
        $this->resetErrorBag();
    }

    public function guardarPartilha(): void
    {
        if (! $this->partilhaAberta || $this->linkCriado) {
            return;
        }
        $this->resetErrorBag();

        try {
            $dados = ['publico' => (string) ($this->partilha['publico'] ?? '1') === '1'] + $this->partilha;
            $relatorio = app(GestorPartilhados::class)->criar($this->autor(), 'resumo', $dados, $this->parametros());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('partilha.'.$campo, $mensagens[0]);
            }

            return;
        }

        $this->linkCriado = $relatorio->url();
    }

    /** Estado atual do relatório, guardado num relatório partilhado. */
    public function parametros(): array
    {
        return [
            'tipo' => $this->tipo, 'inicio' => $this->inicio, 'fim' => $this->fim,
            'membros' => $this->membros, 'clientes' => $this->clientes, 'projetos' => $this->projetos,
            'etiquetas' => $this->etiquetas, 'estado' => $this->estado, 'descricao' => $this->descricao,
            'agrupar1' => $this->agrupar1, 'agrupar2' => $this->agrupar2, 'cor' => $this->cor,
            'mostrarValor' => $this->mostrarValor, 'ordem' => $this->ordem, 'estimativa' => $this->estimativa,
        ];
    }

    public function ordenarPor(string $campo): void
    {
        if (in_array($campo, ['titulo', 'duracao', 'valor'], true)) {
            $this->ordem = ltrim($this->ordem, '-') === $campo
                ? (str_starts_with($this->ordem, '-') ? $campo : '-'.$campo)
                : ($campo === 'titulo' ? $campo : '-'.$campo);
        }
    }

    public function exportar(string $formato = 'csv')
    {
        [$de, $ate] = $this->periodo();
        $dados = $this->dados($de, $ate);
        $comValor = $this->valorVisivel();
        $nomes = ResumoTempos::AGRUPAMENTOS;

        $linhas = [];
        foreach ($this->ordenar($dados['grupos']) as $g) {
            $linhas[] = array_merge([$g['nome'], ''], [Horas::decimal($g['segundos']), PainelTempos::hms($g['segundos'])], $comValor ? [Dinheiro::decimal($this->valorDe($g))] : []);
            foreach ($this->ordenar($g['filhos']) as $f) {
                $linhas[] = array_merge([$g['nome'], $f['nome']], [Horas::decimal($f['segundos']), PainelTempos::hms($f['segundos'])], $comValor ? [Dinheiro::decimal($this->valorDe($f))] : []);
            }
        }

        $cabecalho = array_merge([$nomes[$this->agrupar1], $this->agrupar2 !== '' ? $nomes[$this->agrupar2] : ''], ['Duração (h)', 'Duração'], $comValor ? [$this->rotuloValor().' (€)'] : []);

        return $this->descarregar($formato, 'resumo', 'Resumo', $de, $ate, $cabecalho, $linhas);
    }

    public function render()
    {
        [$de, $ate] = $this->periodo();
        $dados = $this->dados($de, $ate);

        $estimativas = $this->estimativa && $this->agrupar1 === 'projeto'
            ? ProjetoTempo::withTrashed()->whereIn('id', collect($dados['grupos'])->pluck('chave')->filter())->pluck('estimativa_seg', 'id')->all()
            : null;

        return view('livewire.relatorios.resumo', [
            'dados' => $dados,
            'grupos' => $this->ordenar($dados['grupos']),
            'de' => $de,
            'ate' => $ate,
            'rotuloPeriodo' => $this->rotuloPeriodo($de, $ate),
            'podeVerEquipa' => $this->autorVeEquipa(),
            'comValor' => $this->valorVisivel(),
            'rotuloValor' => $this->rotuloValor(),
            'valorTotal' => $this->valorDe($dados),
            'opcoes' => app(ResumoTempos::class)->opcoes($this->autor()),
            'agrupamentos' => ResumoTempos::AGRUPAMENTOS,
            'estados' => ResumoTempos::ESTADOS,
            'estimativas' => $estimativas,
            'filtrosAtivos' => $this->contarFiltros(),
            'partilhado' => null,
            'podePartilhar' => true,
            'frequencias' => RelatorioPartilhado::FREQUENCIAS,
        ]);
    }

    /** Valor a mostrar de um grupo (ou dos totais), conforme "Mostrar valor". */
    public function valorDe(array $linha): int
    {
        return match ($this->mostrarValor) {
            'custo' => $linha['custo'],
            'lucro' => $linha['valor'] - $linha['custo'],
            default => $linha['valor'],
        };
    }

    /** Subgrupos pela mesma ordem da tabela. */
    public function ordenarFilhos(array $filhos): array
    {
        return $this->ordenar($filhos);
    }

    protected function dados(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        return app(ResumoTempos::class)->gerar($this->autor(), $this->filtrosDoServico(), $de, $ate,
            $this->agrupar1, $this->agrupar2 !== '' ? $this->agrupar2 : null, $this->cor);
    }

    /** @return list<array<string, mixed>> */
    private function ordenar(array $linhas): array
    {
        $campo = ltrim($this->ordem, '-');
        $desc = str_starts_with($this->ordem, '-');

        // Desempate pelo nome (a ordenação do PHP é estável).
        return collect($linhas)
            ->sortBy(fn ($l) => mb_strtolower($l['nome']))
            ->sortBy(fn ($l) => match ($campo) {
                'titulo' => mb_strtolower($l['nome']),
                'valor' => $this->valorDe($l),
                default => $l['segundos'],
            }, SORT_REGULAR, $desc)
            ->values()->all();
    }

    private function valorVisivel(): bool
    {
        return $this->autorVeEquipa() && $this->mostrarValor !== 'nao';
    }

    private function rotuloValor(): string
    {
        return match ($this->mostrarValor) {
            'custo' => 'Custo',
            'lucro' => 'Lucro',
            default => 'Valor',
        };
    }

    protected function normalizar(): void
    {
        $this->normalizarPeriodoEFiltros();

        $validos = [
            'agrupar1' => [array_keys(ResumoTempos::AGRUPAMENTOS), 'projeto'],
            'cor' => [['faturabilidade', 'grupo'], 'faturabilidade'],
            'mostrarValor' => [['faturavel', 'custo', 'lucro', 'nao'], 'faturavel'],
        ];
        foreach ($validos as $prop => [$lista, $omissao]) {
            if (! in_array($this->{$prop}, $lista, true)) {
                $this->{$prop} = $omissao;
            }
        }
        if ($this->agrupar2 === $this->agrupar1 || ($this->agrupar2 !== '' && ! isset(ResumoTempos::AGRUPAMENTOS[$this->agrupar2]))) {
            $this->agrupar2 = '';
        }
        if (! in_array(ltrim($this->ordem, '-'), ['titulo', 'duracao', 'valor'], true)) {
            $this->ordem = '-duracao';
        }
    }
}

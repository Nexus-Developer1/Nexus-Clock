<?php

namespace App\Livewire\Relatorios;

use App\Livewire\Relatorios\Concerns\PeriodoEFiltros;
use App\Models\User;
use App\Services\Tempos\PainelTempos;
use App\Services\Tempos\Presencas as ServicoPresencas;
use App\Support\Csv;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatório Presenças (como o "Attendance report" do Clockify): uma linha por pessoa e dia com
 * entrada, saída, capacidade, trabalho, horas extra, em falta, saldo e pausas; totais do período;
 * filtro por pessoas e situação; agrupar por pessoa ou dia (com subtotais); ordenar; páginas;
 * exportar CSV e imprimir. Quem não vê a equipa só vê a sua linha.
 */
#[Layout('components.layouts.app', ['ativo' => 'relatorios.presencas', 'titulo' => 'Presenças'])]
class Presencas extends Component
{
    use PeriodoEFiltros;

    public const POR_PAGINA = 50;

    public const ORDENS = ['nome', 'dia', 'inicio', 'fim', 'capacidade', 'trabalho', 'extra', 'em_falta', 'saldo', 'pausa'];

    #[Url(as: 'situacao')]
    public string $situacao = '';

    #[Url(as: 'agrupar')]
    public string $agrupar = ''; // '' | membro | dia

    #[Url(as: 'ordem')]
    public string $ordem = '-dia';

    #[Url(as: 'pagina')]
    public int $pagina = 1;

    public function ordenarPor(string $campo): void
    {
        if (in_array($campo, self::ORDENS, true)) {
            $this->ordem = ltrim($this->ordem, '-') === $campo
                ? (str_starts_with($this->ordem, '-') ? $campo : '-'.$campo)
                : (in_array($campo, ['nome', 'inicio'], true) ? $campo : '-'.$campo);
            $this->pagina = 1;
        }
    }

    public function irPara(int $pagina): void
    {
        $this->pagina = max(1, $pagina);
    }

    public function exportar(string $formato = 'csv')
    {
        [$de, $ate] = $this->periodo();
        $hms = fn (int $s) => PainelTempos::hms($s);

        $linhas = array_map(fn ($l) => [
            $l['nome'], $l['dia']->format('d/m/Y'), $l['inicio']?->format('H:i') ?? '', $l['fim']?->format('H:i') ?? '',
            $hms($l['capacidade']), $hms($l['trabalho']), $hms($l['extra']), $hms($l['em_falta']),
            ServicoPresencas::saldo($l['saldo']), $hms($l['pausa']),
        ], $this->linhas());

        return $this->descarregar($formato, 'presencas', 'Presenças', $de, $ate, ['Membro', 'Dia', 'Entrada', 'Saída', 'Capacidade', 'Trabalho', 'Horas extra', 'Em falta', 'Saldo', 'Pausas'], $linhas);
    }

    public function render()
    {
        [$de, $ate] = $this->periodo();
        $todas = $this->linhas();
        $paginas = max(1, (int) ceil(count($todas) / self::POR_PAGINA));
        $this->pagina = min($this->pagina, $paginas);
        $pagina = array_slice($todas, ($this->pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        $subtotais = [];
        if ($this->agrupar !== '') {
            foreach (collect($todas)->groupBy(fn ($l) => $this->chaveGrupo($l)) as $chave => $grupo) {
                $subtotais[$chave] = ['n' => $grupo->count()] + ServicoPresencas::somar($grupo->all());
            }
        }

        return view('livewire.relatorios.presencas', [
            'linhas' => $pagina,
            'total' => ServicoPresencas::somar($todas),
            'nLinhas' => count($todas),
            'paginas' => $paginas,
            'subtotais' => $subtotais,
            'de' => $de,
            'ate' => $ate,
            'rotuloPeriodo' => $this->rotuloPeriodo($de, $ate),
            'podeVerEquipa' => Gate::allows('tempos-ver-todos'),
            'opcoes' => ['membros' => User::comAcessoAosTempos()->orderBy('nome')->pluck('nome', 'id')->all()],
            'situacoes' => ServicoPresencas::SITUACOES,
            'filtrosAtivos' => count($this->membros) + ($this->situacao !== '' ? 1 : 0),
        ]);
    }

    public function chaveGrupo(array $linha): string
    {
        return match ($this->agrupar) {
            'membro' => (string) $linha['tecnico_id'],
            'dia' => $linha['dia']->toDateString(),
            default => '',
        };
    }

    public function limparFiltros(): void
    {
        $this->reset(['membros', 'situacao']);
        $this->pagina = 1;
    }

    protected function filtrosMudaram(string $propriedade): void
    {
        $this->pagina = 1;
    }

    /** @return list<array<string, mixed>> todas as linhas, ordenadas (e agrupadas) */
    private function linhas(): array
    {
        [$de, $ate] = $this->periodo();
        $membros = $this->filtrosDoServico()['membros'];
        $campo = ltrim($this->ordem, '-');
        $desc = str_starts_with($this->ordem, '-');

        $linhas = collect(app(ServicoPresencas::class)->linhas($membros, $de, $ate, $this->situacao))
            // Desempates estáveis: dia (recente primeiro) e nome.
            ->sortBy(fn ($l) => mb_strtolower($l['nome']))
            ->sortByDesc(fn ($l) => $l['dia']->toDateString())
            ->sortBy(fn ($l) => match ($campo) {
                'nome' => mb_strtolower($l['nome']),
                'dia' => $l['dia']->toDateString(),
                'inicio', 'fim' => $l[$campo]?->format('H:i') ?? ($desc ? '' : '99'),
                default => $l[$campo],
            }, SORT_REGULAR, $desc);

        if ($this->agrupar === 'membro') {
            $linhas = $linhas->sortBy(fn ($l) => mb_strtolower($l['nome']));
        } elseif ($this->agrupar === 'dia') {
            $linhas = $linhas->sortByDesc(fn ($l) => $l['dia']->toDateString());
        }

        return $linhas->values()->all();
    }

    private function normalizar(): void
    {
        $this->normalizarPeriodoEFiltros();

        if (! isset(ServicoPresencas::SITUACOES[$this->situacao])) {
            $this->situacao = '';
        }
        if (! in_array($this->agrupar, ['', 'membro', 'dia'], true)) {
            $this->agrupar = '';
        }
        if (! in_array(ltrim($this->ordem, '-'), self::ORDENS, true)) {
            $this->ordem = '-dia';
        }
        $this->pagina = max(1, $this->pagina);
    }
}

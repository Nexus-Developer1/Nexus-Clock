<?php

namespace App\Livewire\Relatorios;

use App\Livewire\Relatorios\Concerns\PeriodoEFiltros;
use App\Services\Tempos\ResumoTempos;
use App\Support\Csv;
use App\Support\Dinheiro;
use App\Support\Horas;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatório Semanal (como o "Weekly report" do Clockify): uma grelha com uma linha por grupo (e
 * subgrupo) e uma coluna por dia — ou por semana/mês em períodos longos —, com totais por linha e por
 * coluna. Mostra tempo, valor ou os dois; cada célula abre o Detalhado desse dia e desse grupo.
 * Período e filtros em PeriodoEFiltros. Quem não vê a equipa só vê as suas horas e não vê valores.
 */
#[Layout('components.layouts.app', ['ativo' => 'relatorios.semanal', 'titulo' => 'Semanal'])]
class Semanal extends Component
{
    use PeriodoEFiltros;

    #[Url(as: 'agrupar')]
    public string $agrupar1 = 'projeto';

    #[Url(as: 'depois')]
    public string $agrupar2 = 'membro';

    #[Url(as: 'mostrar')]
    public string $mostrar = 'tempo'; // tempo | valor | ambos

    #[Url(as: 'ordem')]
    public string $ordem = '-total'; // nome | total, "-" = descendente

    public function ordenarPor(string $campo): void
    {
        if (in_array($campo, ['nome', 'total'], true)) {
            $this->ordem = ltrim($this->ordem, '-') === $campo
                ? (str_starts_with($this->ordem, '-') ? $campo : '-'.$campo)
                : ($campo === 'nome' ? 'nome' : '-total');
        }
    }

    public function exportar()
    {
        [$de, $ate] = $this->periodo();
        $g = $this->grelha();
        $comValor = $this->mostraValor();
        $comTempo = $this->mostrar !== 'valor' || ! $comValor;

        $celulas = function (array $linha) use ($g, $comValor, $comTempo) {
            $saida = [];
            foreach ($g['colunas'] as $c) {
                $cel = $linha['celulas'][$c['chave']];
                if ($comTempo) {
                    $saida[] = Horas::decimal($cel['segundos']);
                }
                if ($comValor) {
                    $saida[] = Dinheiro::decimal($cel['valor']);
                }
            }
            if ($comTempo) {
                $saida[] = Horas::decimal($linha['segundos']);
            }
            if ($comValor) {
                $saida[] = Dinheiro::decimal($linha['valor']);
            }

            return $saida;
        };

        $cabecalho = [ResumoTempos::AGRUPAMENTOS[$this->agrupar1], $this->agrupar2 !== '' ? ResumoTempos::AGRUPAMENTOS[$this->agrupar2] : ''];
        foreach (array_merge($g['colunas'], [['rotulo' => 'Total', 'detalhe' => '']]) as $c) {
            $nome = trim($c['rotulo'].' '.$c['detalhe']);
            if ($comTempo) {
                $cabecalho[] = $nome.' (h)';
            }
            if ($comValor) {
                $cabecalho[] = $nome.' (€)';
            }
        }

        $linhas = [];
        foreach ($this->ordenar($g['linhas']) as $l) {
            $linhas[] = array_merge([$l['nome'], ''], $celulas($l));
            foreach ($this->ordenar($l['filhos']) as $f) {
                $linhas[] = array_merge([$l['nome'], $f['nome']], $celulas($f));
            }
        }
        $linhas[] = array_merge(['Total', ''], $celulas(['celulas' => $g['totais']] + $g['total']));

        return Csv::resposta('semanal-'.$de->format('Ymd').'-'.$ate->format('Ymd').'.csv', $cabecalho, $linhas);
    }

    public function render()
    {
        [$de, $ate] = $this->periodo();
        $g = $this->grelha();

        return view('livewire.relatorios.semanal', [
            'grelha' => $g,
            'linhas' => $this->ordenar($g['linhas']),
            'maximo' => max(1, (int) collect($g['linhas'])->flatMap(fn ($l) => array_column($l['celulas'], 'segundos'))->max()),
            'de' => $de,
            'ate' => $ate,
            'rotuloPeriodo' => $this->rotuloPeriodo($de, $ate),
            'podeVerEquipa' => Gate::allows('tempos-ver-todos'),
            'comValor' => $this->mostraValor(),
            'comTempo' => $this->mostrar !== 'valor' || ! $this->mostraValor(),
            'opcoes' => app(ResumoTempos::class)->opcoes(auth()->user()),
            'agrupamentos' => ResumoTempos::AGRUPAMENTOS,
            'estados' => ResumoTempos::ESTADOS,
            'filtrosAtivos' => $this->contarFiltros(),
        ]);
    }

    /** Subgrupos pela mesma ordem da tabela. */
    public function ordenarFilhos(array $filhos): array
    {
        return $this->ordenar($filhos);
    }

    /** URL do Detalhado para uma célula: as datas da coluna e o grupo (e subgrupo) da linha. */
    public function ligacao(string $deCol, string $ateCol, ?string $chave1 = null, ?string $chave2 = null): string
    {
        $filtros = ['periodo' => 'datas', 'de' => $deCol, 'ate' => $ateCol]
            + array_filter([
                'membros' => $this->membros, 'clientes' => $this->clientes, 'projetos' => $this->projetos,
                'etiquetas' => $this->etiquetas, 'estado' => $this->estado, 'descricao' => $this->descricao,
            ]);

        foreach ([[$this->agrupar1, $chave1], [$this->agrupar2, $chave2]] as [$agrupar, $chave]) {
            if ($chave === null) {
                continue;
            }
            match ($agrupar) {
                'projeto' => $filtros['projetos'] = [(int) $chave],
                'cliente' => $chave !== '' ? $filtros['clientes'] = [(int) $chave] : null,
                'membro' => $chave !== '' ? $filtros['membros'] = [(int) $chave] : null,
                'etiqueta' => $chave !== '' ? $filtros['etiquetas'] = [$chave] : null,
                'descricao' => $chave !== '' ? $filtros['descricao'] = $chave : null,
                'dia' => [$filtros['de'], $filtros['ate']] = [$chave, $chave],
                default => null,
            };
        }

        return route('relatorios.detalhado', $filtros);
    }

    private function grelha(): array
    {
        [$de, $ate] = $this->periodo();

        return app(ResumoTempos::class)->grelha(auth()->user(), $this->filtrosDoServico(), $de, $ate,
            $this->agrupar1, $this->agrupar2 !== '' ? $this->agrupar2 : null);
    }

    /** @return list<array<string, mixed>> */
    private function ordenar(array $linhas): array
    {
        $desc = str_starts_with($this->ordem, '-');

        return collect($linhas)
            ->sortBy(fn ($l) => mb_strtolower($l['nome']))
            ->when(ltrim($this->ordem, '-') === 'total', fn ($c) => $c->sortBy(fn ($l) => $this->mostrar === 'valor' && $this->mostraValor() ? $l['valor'] : $l['segundos'], SORT_REGULAR, $desc))
            ->when(ltrim($this->ordem, '-') === 'nome' && $desc, fn ($c) => $c->reverse())
            ->values()->all();
    }

    private function mostraValor(): bool
    {
        return Gate::allows('tempos-ver-todos') && $this->mostrar !== 'tempo';
    }

    private function normalizar(): void
    {
        $this->normalizarPeriodoEFiltros();

        if (! isset(ResumoTempos::AGRUPAMENTOS[$this->agrupar1])) {
            $this->agrupar1 = 'projeto';
        }
        if ($this->agrupar2 === $this->agrupar1 || ($this->agrupar2 !== '' && ! isset(ResumoTempos::AGRUPAMENTOS[$this->agrupar2]))) {
            $this->agrupar2 = '';
        }
        if (! in_array($this->mostrar, ['tempo', 'valor', 'ambos'], true) || ! Gate::allows('tempos-ver-todos')) {
            $this->mostrar = 'tempo';
        }
        if (! in_array(ltrim($this->ordem, '-'), ['nome', 'total'], true)) {
            $this->ordem = '-total';
        }
    }
}

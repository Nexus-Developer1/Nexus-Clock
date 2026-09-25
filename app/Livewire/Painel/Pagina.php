<?php

namespace App\Livewire\Painel;

use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Services\Tempos\PainelTempos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Painel (como o Dashboard do Clockify): tempo total (com a comparação com o período anterior) e
 * faturável, projeto e cliente com mais horas, horas por dia, distribuição e atividades mais registadas,
 * num período (semana ou mês, com anterior/seguinte), para as horas de quem está a ver ou da equipa
 * (quem vê os tempos de todos, que vê também a atividade da equipa). Barras, grupos e atividades
 * abrem o relatório Detalhado já filtrado. Os cartões do resumo também abrem: o tempo total e o faturável
 * no Detalhado; o projeto e o cliente principais na página deles (notas §49).
 */
#[Layout('components.layouts.app', ['ativo' => 'painel', 'titulo' => 'Painel'])]
class Pagina extends Component
{
    #[Url(as: 'agrupar')]
    public string $agrupar = 'projeto';

    #[Url(as: 'quem')]
    public string $quem = 'eu'; // eu | equipa

    #[Url(as: 'periodo')]
    public string $tipo = 'semana'; // semana | mes

    #[Url(as: 'de')]
    public string $inicio = '';

    public int $top = 10;

    public function mount(): void
    {
        $this->normalizar();
    }

    public function updated(): void
    {
        $this->normalizar();
    }

    /** Atalhos: esta semana, semana passada, este mês, mês passado. */
    public function escolherPeriodo(string $qual): void
    {
        $hoje = $this->hoje();
        [$this->tipo, $inicio] = match ($qual) {
            'semana-passada' => ['semana', $hoje->startOfWeek()->subWeek()],
            'mes' => ['mes', $hoje->startOfMonth()],
            'mes-passado' => ['mes', $hoje->startOfMonth()->subMonthNoOverflow()],
            default => ['semana', $hoje->startOfWeek()],
        };
        $this->inicio = $inicio->toDateString();
    }

    public function anterior(): void
    {
        $de = CarbonImmutable::parse($this->inicio);
        $this->inicio = ($this->tipo === 'mes' ? $de->subMonthNoOverflow() : $de->subWeek())->toDateString();
    }

    public function seguinte(): void
    {
        $de = CarbonImmutable::parse($this->inicio);
        $this->inicio = ($this->tipo === 'mes' ? $de->addMonthNoOverflow() : $de->addWeek())->toDateString();
    }

    public function render()
    {
        // Em cada pedido: quem deixou de ver a equipa (perdeu o papel de admin) com a página aberta volta
        // às suas horas no pedido seguinte (notas §52).
        $this->normalizar();
        $de = CarbonImmutable::parse($this->inicio);
        $ate = $this->fim($de);
        $equipa = $this->quem === 'equipa';
        $tecnicoId = $equipa ? null : auth()->id();
        $servico = app(PainelTempos::class);

        // Período anterior, para a comparação do tempo total.
        $deAnterior = $this->tipo === 'mes' ? $de->subMonthNoOverflow() : $de->subWeek();

        $dados = $servico->gerar($tecnicoId, $this->agrupar, $de, $ate, $this->top);

        // Só se liga ao que quem vê pode abrir: um projeto privado de que não é membro, ou um
        // projeto/cliente arquivado, ficam sem ligação (as páginas deles dariam 404).
        $topProjeto = $dados['topProjeto']['id'] ?? null;
        $topCliente = $dados['topCliente']['id'] ?? null;

        return view('livewire.painel.pagina', [
            'dados' => $dados,
            'urlProjeto' => $topProjeto && ProjetoTempo::visiveisPara(auth()->user())->whereKey($topProjeto)->exists() ? route('projetos.ver', $topProjeto) : null,
            'urlCliente' => $topCliente && ClienteTempo::whereKey($topCliente)->exists() ? route('clientes.ver', $topCliente) : null,
            'anterior' => $servico->totais($tecnicoId, $deAnterior, $this->fim($deAnterior))['total'],
            'equipa' => $equipa ? $servico->equipa($de, $ate) : [],
            'de' => $de,
            'ate' => $ate,
            'rotuloPeriodo' => $this->rotuloPeriodo($de, $ate),
            'podeVerEquipa' => Gate::allows('tempos-ver-todos'),
            'agrupamentos' => $equipa ? PainelTempos::AGRUPAMENTOS : array_diff_key(PainelTempos::AGRUPAMENTOS, ['membro' => 1]),
            'ligacao' => fn (array $filtros = []) => $this->ligacao($filtros),
        ]);
    }

    /** URL do relatório Detalhado com o mesmo período e quem, mais os filtros dados. */
    public function ligacao(array $filtros = []): string
    {
        $base = ['periodo' => $this->tipo, 'de' => $this->inicio];
        if ($this->quem !== 'equipa' && Gate::allows('tempos-ver-todos')) {
            $base['membros'] = [auth()->id()];
        }

        return route('relatorios.detalhado', array_filter($filtros + $base, fn ($v) => $v !== null && $v !== '' && $v !== []));
    }

    private function fim(CarbonImmutable $de): CarbonImmutable
    {
        return $this->tipo === 'mes' ? $de->endOfMonth()->startOfDay() : $de->addDays(6);
    }

    private function normalizar(): void
    {
        if (! in_array($this->quem, ['eu', 'equipa'], true) || ($this->quem === 'equipa' && ! Gate::allows('tempos-ver-todos'))) {
            $this->quem = 'eu';
        }
        if (! isset(PainelTempos::AGRUPAMENTOS[$this->agrupar]) || ($this->agrupar === 'membro' && $this->quem !== 'equipa')) {
            $this->agrupar = 'projeto';
        }
        if (! in_array($this->tipo, ['semana', 'mes'], true)) {
            $this->tipo = 'semana';
        }
        if (! in_array($this->top, [10, 20], true)) {
            $this->top = 10;
        }

        try {
            $de = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->inicio) ? CarbonImmutable::parse($this->inicio) : $this->hoje();
        } catch (\Throwable) {
            $de = $this->hoje();
        }
        $this->inicio = ($this->tipo === 'mes' ? $de->startOfMonth() : $de->startOfWeek())->toDateString();
    }

    private function rotuloPeriodo(CarbonImmutable $de, CarbonImmutable $ate): string
    {
        $hoje = $this->hoje();

        return match (true) {
            $this->tipo === 'semana' && $de->equalTo($hoje->startOfWeek()) => 'Esta semana',
            $this->tipo === 'semana' && $de->equalTo($hoje->startOfWeek()->subWeek()) => 'Semana passada',
            $this->tipo === 'mes' && $de->equalTo($hoje->startOfMonth()) => 'Este mês',
            $this->tipo === 'mes' && $de->equalTo($hoje->startOfMonth()->subMonthNoOverflow()) => 'Mês passado',
            $this->tipo === 'mes' => ucfirst($de->translatedFormat('F Y')),
            default => $de->format('d/m').' – '.$ate->format('d/m/Y'),
        };
    }

    private function hoje(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(config('tempos.fuso'))->toDateString());
    }
}

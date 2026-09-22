<?php

namespace App\Livewire\Relatorios\Concerns;

use App\Models\User;
use App\Services\Tempos\ResumoTempos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Período e filtros comuns aos relatórios de tempo (Resumo, Detalhado): semana, mês, ano ou datas à
 * escolha, com anterior/seguinte; membros, clientes, projetos, etiquetas, estado e descrição, no URL.
 * O componente define normalizar(), que chama normalizarPeriodoEFiltros().
 */
trait PeriodoEFiltros
{
    #[Url(as: 'periodo')]
    public string $tipo = 'semana'; // semana | mes | ano | datas

    #[Url(as: 'de')]
    public string $inicio = '';

    #[Url(as: 'ate')]
    public string $fim = '';

    /** @var list<string> */
    #[Url(as: 'membros')]
    public array $membros = [];

    /** @var list<string> */
    #[Url(as: 'clientes')]
    public array $clientes = [];

    /** @var list<string> */
    #[Url(as: 'projetos')]
    public array $projetos = [];

    /** @var list<string> */
    #[Url(as: 'etiquetas')]
    public array $etiquetas = [];

    #[Url(as: 'estado')]
    public string $estado = '';

    #[Url(as: 'descricao')]
    public string $descricao = '';

    // Datas escolhidas à mão (antes de aplicar).
    public string $escolhaDe = '';

    public string $escolhaAte = '';

    public function mount(): void
    {
        $this->normalizar();
    }

    public function updated(string $propriedade): void
    {
        // As datas à escolha só contam ao aplicar.
        if (! str_starts_with($propriedade, 'escolha')) {
            $this->normalizar();
            $this->filtrosMudaram($propriedade);
        }
    }

    public function escolherPeriodo(string $qual): void
    {
        $hoje = $this->hoje();
        [$this->tipo, $inicio] = match ($qual) {
            'semana-passada' => ['semana', $hoje->startOfWeek()->subWeek()],
            'mes' => ['mes', $hoje->startOfMonth()],
            'mes-passado' => ['mes', $hoje->startOfMonth()->subMonthNoOverflow()],
            'ano' => ['ano', $hoje->startOfYear()],
            'ano-passado' => ['ano', $hoje->startOfYear()->subYear()],
            default => ['semana', $hoje->startOfWeek()],
        };
        $this->inicio = $inicio->toDateString();
        $this->normalizar();
        $this->filtrosMudaram('periodo');
    }

    public function aplicarDatas(): void
    {
        $this->resetErrorBag();
        try {
            $de = CarbonImmutable::createFromFormat('!Y-m-d', $this->escolhaDe);
            $ate = CarbonImmutable::createFromFormat('!Y-m-d', $this->escolhaAte);
        } catch (\Throwable) {
            $de = $ate = false;
        }
        if (! $de || ! $ate) {
            $this->addError('datas', 'Escolha as duas datas.');

            return;
        }
        if ($ate->lt($de)) {
            $this->addError('datas', 'A data final não pode ser antes da inicial.');

            return;
        }
        if ($de->diffInDays($ate) > 366) {
            $this->addError('datas', 'Escolha no máximo um ano.');

            return;
        }

        $this->tipo = 'datas';
        $this->inicio = $de->toDateString();
        $this->fim = $ate->toDateString();
        $this->dispatch('datas-aplicadas');
        $this->filtrosMudaram('periodo');
    }

    public function anterior(): void
    {
        $this->deslocar(-1);
    }

    public function seguinte(): void
    {
        $this->deslocar(1);
    }

    public function limparFiltros(): void
    {
        $this->reset(['membros', 'clientes', 'projetos', 'etiquetas', 'estado', 'descricao']);
        $this->filtrosMudaram('filtros');
    }

    /** Chamado quando o período ou um filtro muda (para limpar seleções, voltar à 1.ª página…). */
    protected function filtrosMudaram(string $propriedade): void {}

    /** De quem são as permissões com que o relatório é calculado (num relatório partilhado, de quem o criou). */
    protected function autor(): User
    {
        return auth()->user();
    }

    protected function autorVeEquipa(): bool
    {
        return Gate::forUser($this->autor())->allows('tempos-ver-todos');
    }

    /** Filtros para o ResumoTempos. Quem não vê a equipa só vê as suas horas. */
    protected function filtrosDoServico(): array
    {
        return [
            'membros' => $this->autorVeEquipa() ? ($this->membros === [] ? null : array_map('intval', $this->membros)) : [$this->autor()->id],
            'clientes' => array_map('intval', $this->clientes),
            'projetos' => array_map('intval', $this->projetos),
            'etiquetas' => $this->etiquetas,
            'estado' => $this->estado,
            'descricao' => $this->descricao,
        ];
    }

    protected function contarFiltros(): int
    {
        return count($this->membros) + count($this->clientes) + count($this->projetos) + count($this->etiquetas)
            + ($this->estado !== '' ? 1 : 0) + (trim($this->descricao) !== '' ? 1 : 0);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function periodo(): array
    {
        $de = CarbonImmutable::parse($this->inicio);

        return [$de, match ($this->tipo) {
            'mes' => $de->endOfMonth()->startOfDay(),
            'ano' => $de->endOfYear()->startOfDay(),
            'datas' => CarbonImmutable::parse($this->fim),
            default => $de->addDays(6),
        }];
    }

    protected function normalizarPeriodoEFiltros(): void
    {
        if (! in_array($this->tipo, ['semana', 'mes', 'ano', 'datas'], true)) {
            $this->tipo = 'semana';
        }
        if (! in_array($this->estado, array_merge([''], array_keys(ResumoTempos::ESTADOS)), true)) {
            $this->estado = '';
        }
        foreach (['membros', 'clientes', 'projetos'] as $lista) {
            $this->{$lista} = array_values(array_unique(array_filter(array_map('strval', (array) $this->{$lista}), 'ctype_digit')));
        }
        $this->etiquetas = array_values(array_unique(array_filter(array_map('strval', (array) $this->etiquetas), fn ($e) => $e !== '')));
        $this->descricao = mb_substr($this->descricao, 0, 200);

        $data = fn (string $texto) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) && ($d = CarbonImmutable::createFromFormat('!Y-m-d', $texto)) && $d->toDateString() === $texto ? $d : null;
        $de = $data($this->inicio) ?? $this->hoje();

        if ($this->tipo === 'datas') {
            $ate = $data($this->fim);
            if (! $ate || $ate->lt($de) || $de->diffInDays($ate) > 366) {
                $this->tipo = 'semana';
            } else {
                $this->inicio = $de->toDateString();
                $this->fim = $ate->toDateString();
                $this->escolhaDe = $this->inicio;
                $this->escolhaAte = $this->fim;

                return;
            }
        }

        $this->inicio = (match ($this->tipo) {
            'mes' => $de->startOfMonth(),
            'ano' => $de->startOfYear(),
            default => $de->startOfWeek(),
        })->toDateString();
        $this->fim = '';
        [$inicio, $fim] = $this->periodo();
        $this->escolhaDe = $inicio->toDateString();
        $this->escolhaAte = $fim->toDateString();
    }

    protected function rotuloPeriodo(CarbonImmutable $de, CarbonImmutable $ate): string
    {
        $hoje = $this->hoje();

        return match (true) {
            $this->tipo === 'semana' && $de->equalTo($hoje->startOfWeek()) => 'Esta semana',
            $this->tipo === 'semana' && $de->equalTo($hoje->startOfWeek()->subWeek()) => 'Semana passada',
            $this->tipo === 'mes' && $de->equalTo($hoje->startOfMonth()) => 'Este mês',
            $this->tipo === 'mes' && $de->equalTo($hoje->startOfMonth()->subMonthNoOverflow()) => 'Mês passado',
            $this->tipo === 'mes' => ucfirst($de->translatedFormat('F Y')),
            $this->tipo === 'ano' && $de->year === $hoje->year => 'Este ano',
            $this->tipo === 'ano' && $de->year === $hoje->year - 1 => 'Ano passado',
            $this->tipo === 'ano' => (string) $de->year,
            default => $de->format('d/m').' – '.$ate->format('d/m/Y'),
        };
    }

    protected function hoje(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(config('tempos.fuso'))->toDateString());
    }

    private function deslocar(int $sentido): void
    {
        [$de, $ate] = $this->periodo();
        if ($this->tipo === 'datas') {
            $dias = $de->diffInDays($ate) + 1;
            $this->inicio = $de->addDays($sentido * $dias)->toDateString();
            $this->fim = $ate->addDays($sentido * $dias)->toDateString();
        } else {
            $this->inicio = (match ($this->tipo) {
                'mes' => $de->addMonthsNoOverflow($sentido),
                'ano' => $de->addYears($sentido),
                default => $de->addWeeks($sentido),
            })->toDateString();
        }
        $this->filtrosMudaram('periodo');
    }
}

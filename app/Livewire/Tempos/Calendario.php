<?php

namespace App\Livewire\Tempos;

use App\Livewire\Concerns\FiltrosInversos;
use App\Livewire\Concerns\FormularioRegisto;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\Feriados;
use App\Support\PessoaNaAgenda;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Calendário (como o "Calendar" do Clockify): os registos de quem está a ver desenhados nas horas de
 * cada dia, arrastar numa coluna para acrescentar tempo e carregar num bloco para o alterar. Os
 * registos sem horas (só duração) ficam numa faixa por cima do dia. Vista de dia, de semana ou de mês,
 * como a agenda da Nexus Infra (notas §73). Quem vê a equipa (tempos-ver-todos) escolhe de quem é o
 * calendário (notas §71), ou «Toda a equipa»: cada bloco leva as iniciais de quem o fez e, quando duas
 * ou mais pessoas estão no mesmo projeto à mesma hora, os registos juntam-se num bloco dividido em faixas,
 * uma cor por pessoa, como a agenda da IFE (notas §75). Filtro de projeto com «Incluir · Excluir»
 * (FiltrosInversos); 0 = sem projeto.
 */
#[Layout('components.layouts.app', ['ativo' => 'calendario', 'titulo' => 'Calendário'])]
class Calendario extends Component
{
    use FiltrosInversos;
    use FormularioRegisto {
        novo as private novoDoFormulario;
    }

    /** Altura de uma hora, em pixels (o mesmo valor está na vista). */
    public const ALTURA_HORA = 48;

    public const VISTAS = ['dia' => 'Dia', 'semana' => 'Semana', 'mes' => 'Mês'];

    #[Url(as: 'vista')]
    public string $vista = 'semana';

    // O dia mostrado (vista de dia), a segunda-feira (semana) ou o dia 1 (mês).
    #[Url(as: 'de')]
    public string $data = '';

    // De quem é o calendário: '' = de quem está a ver; o id de outro membro ou 'equipa' (todos) só para
    // quem vê a equipa.
    #[Url(as: 'pessoa')]
    public string $pessoa = '';

    /** @var list<int> registos de um bloco com várias pessoas, para escolher qual abrir */
    public array $grupo = [];

    /** @var list<string> projetos do filtro ('0' = sem projeto) */
    #[Url(as: 'projetos')]
    public array $projetos = [];

    public function mount(): void
    {
        $this->ajustar($this->lerData($this->data));
        $this->updatedPessoa();
        $this->updatedProjetos();
    }

    public function updatedProjetos(): void
    {
        $this->projetos = array_values(array_unique(array_filter(array_map('strval', (array) $this->projetos), 'ctype_digit')));
        $this->normalizarExclusoes();
    }

    /** @return list<string> */
    protected function filtrosInversiveis(): array
    {
        return ['projetos'];
    }

    public function updatedPessoa(): void
    {
        if ($this->pessoa === 'equipa') {
            if (! Gate::allows('tempos-ver-todos')) {
                $this->pessoa = '';
            }

            return;
        }
        if ($this->pessoa !== '' && $this->quem()->id === auth()->id()) {
            $this->pessoa = '';
        }
    }

    public function updatedVista(): void
    {
        $this->mudarVista($this->vista);
    }

    /** Muda de vista: fica no dia de hoje se ele estiver no que se via, senão no início do que se via. */
    public function mudarVista(string $vista): void
    {
        [$de, $ate] = $this->periodo();
        $hoje = $this->hojeLocal();

        $this->vista = $vista;
        $this->ajustar($hoje->betweenIncluded($de, $ate) ? $hoje : $de);
    }

    /** Do mês ou da semana para a vista desse dia (carregar no número do dia). */
    public function irParaDia(string $dia): void
    {
        $this->vista = 'dia';
        $this->ajustar($this->lerData($dia));
    }

    public function anterior(): void
    {
        $this->ajustar(match ($this->vista) {
            'dia' => $this->inicio()->subDay(),
            'mes' => $this->inicio()->subMonthNoOverflow(),
            default => $this->inicio()->subWeek(),
        });
    }

    public function seguinte(): void
    {
        $this->ajustar(match ($this->vista) {
            'dia' => $this->inicio()->addDay(),
            'mes' => $this->inicio()->addMonthNoOverflow(),
            default => $this->inicio()->addWeek(),
        });
    }

    public function hoje(): void
    {
        $this->ajustar($this->hojeLocal());
    }

    /** No calendário de outra pessoa, o tempo acrescentado é dela (para quem pode mexer nas horas de todos). */
    public function novo(array $valores = []): void
    {
        // Na vista de dia, «Acrescentar tempo» é nesse dia.
        if (! isset($valores['dia']) && $this->vista === 'dia') {
            $valores['dia'] = $this->inicio()->toDateString();
        }

        // Num feriado não se registam horas (notas §72): avisa logo, sem abrir o formulário.
        $dia = (string) ($valores['dia'] ?? '');
        if (config('tempos.bloquear_feriados') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) && $feriado = app(Feriados::class)->nome($dia)) {
            $this->erro = CarbonImmutable::parse($dia)->format('d/m/Y').' é feriado ('.$feriado.') — não é possível registar horas neste dia.';

            return;
        }

        $quem = $this->quem();
        $outro = ! $this->equipa() && $quem->id !== auth()->id() && Gate::allows('tempos-editar-todos');
        $this->novoDoFormulario($valores + ($outro ? ['tecnico_id' => (string) $quem->id] : []));
    }

    /** Bloco com várias pessoas: mostra os registos dele para escolher qual abrir. */
    public function verGrupo(array $ids): void
    {
        $this->grupo = RegistoTempo::whereKey(array_map('intval', array_slice($ids, 0, 50)))->get()
            ->filter(fn (RegistoTempo $r) => Gate::allows('view', $r))->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function abrirDoGrupo(int $id): void
    {
        $this->grupo = [];
        $this->editar($id);
    }

    public function render()
    {
        [$de, $ate] = $this->periodo();
        // No mês, a grelha vai da segunda antes do dia 1 ao domingo depois do último.
        [$gradeDe, $gradeAte] = $this->vista === 'mes'
            ? [$de->startOfWeek(CarbonImmutable::MONDAY), $ate->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()]
            : [$de, $ate];
        $dias = $this->dias($gradeDe, $gradeAte, $this->quem());
        $doPeriodo = $dias->filter(fn (array $d) => $d['data']->betweenIncluded($de, $ate));
        // Legenda (Toda a equipa): quem tem horas no que se vê, com a cor e as iniciais de cada um.
        $legenda = $this->equipa()
            ? $doPeriodo->flatMap(fn (array $d) => $d['registos'])->map(fn (RegistoTempo $r) => PessoaNaAgenda::de($r->tecnico))
                ->unique('id')->sortBy('nome')->values()
            : collect();

        return view('livewire.tempos.calendario', [
            'pessoas' => Gate::allows('tempos-ver-todos')
                ? User::queRegistamHoras()->whereKeyNot(auth()->id())->orderBy('nome')->pluck('nome', 'id')
                : collect(),
            'dias' => $dias,
            'semanas' => $this->vista === 'mes' ? $dias->chunk(7) : collect(),
            'mes' => (int) $de->month,
            'total' => $doPeriodo->sum('total'),
            'rotuloTotal' => ['dia' => 'Total do dia', 'mes' => 'Total do mês'][$this->vista] ?? 'Total da semana',
            'rotulo' => $this->rotulo($de, $ate),
            'mostraHoje' => ! $this->hojeLocal()->betweenIncluded($de, $ate),
            'alturaHora' => self::ALTURA_HORA,
            'vistas' => self::VISTAS,
            'equipa' => $this->equipa(),
            'legenda' => $legenda,
            'doGrupo' => $this->grupo === [] ? collect() : RegistoTempo::whereKey($this->grupo)
                ->with(['tecnico:id,nome,cor_agenda', 'projeto:id,nome,cor'])->orderBy('inicio')->get(),
            'opcoesProjetos' => [0 => 'Sem projeto'] + ProjetoTempo::visiveisPara(auth()->user())
                ->orderByRaw('arquivado_em is not null, lower(nome)')->pluck('nome', 'id')->all(),
        ] + $this->dadosDoFormulario());
    }

    /**
     * Os dias de $de a $ate, cada um com os registos, os blocos (com horas) e os registos só com duração.
     *
     * @return Collection<int, array{data: CarbonImmutable, feriado: ?string, bloqueado: bool, total: int, registos: Collection<int, RegistoTempo>, blocos: list<array<string, mixed>>, semHoras: Collection<int, RegistoTempo>}>
     */
    private function dias(CarbonImmutable $de, CarbonImmutable $ate, User $quem): Collection
    {
        $registos = RegistoTempo::query()
            ->when(! $this->equipa(), fn ($q) => $q->doTecnico($quem))
            ->whereNotNull('fim')
            ->whereBetween('inicio', [$de->utc(), $ate->addDay()->utc()])
            ->when($this->projetos !== [], fn ($q) => $this->filtrarProjetos($q))
            ->with(['projeto:id,nome,cor,cliente_id', 'projeto.cliente:id,nome', 'tecnico:id,nome,cor_agenda'])
            ->orderBy('inicio')
            ->limit(2000)
            ->get()
            ->groupBy(fn (RegistoTempo $r) => $r->dia()->toDateString());

        $feriados = app(Feriados::class);
        $grelha = $this->vista !== 'mes';

        return collect(range(0, (int) $de->diffInDays($ate)))->map(function (int $n) use ($de, $registos, $feriados, $grelha) {
            $data = $de->addDays($n);
            $doDia = $registos->get($data->toDateString(), collect());

            return [
                'data' => $data,
                'feriado' => $feriados->nome($data, true),
                'bloqueado' => config('tempos.bloquear_feriados') && $feriados->eFeriado($data),
                'total' => (int) $doDia->sum('duracao_seg'),
                'registos' => $doDia->values(),
                'blocos' => $grelha ? $this->blocos($doDia->filter(fn (RegistoTempo $r) => self::temHorasReais($r)), $data) : [],
                'semHoras' => $doDia->reject(fn (RegistoTempo $r) => self::temHorasReais($r))->values(),
            ];
        })->values();
    }

    /**
     * Filtro de projeto (0 = sem projeto). Excluir: tudo menos os escolhidos — os registos sem projeto
     * ficam, a não ser que «Sem projeto» também esteja escolhido.
     */
    private function filtrarProjetos($q)
    {
        $lista = array_map('intval', $this->projetos);
        $ids = array_values(array_filter($lista));
        $semProjeto = in_array(0, $lista, true);

        if (! $this->excluido('projetos')) {
            return $q->where(function ($w) use ($ids, $semProjeto) {
                $w->whereIn('projeto_id', $ids ?: [-1]);
                if ($semProjeto) {
                    $w->orWhereNull('projeto_id');
                }
            });
        }

        return $q
            ->when($ids !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('projeto_id')->orWhereNotIn('projeto_id', $ids)))
            ->when($semProjeto, fn ($q) => $q->whereNotNull('projeto_id'));
    }

    /**
     * Posição de cada registo no dia (minuto de início, altura e, quando se sobrepõem, lado a lado).
     * Cada bloco leva quem o fez (`pessoas`); em «Toda a equipa», os registos do mesmo projeto que se
     * sobrepõem juntam-se num só bloco (`registos`), com uma faixa por pessoa.
     *
     * @param  Collection<int, RegistoTempo>  $registos
     * @return list<array<string, mixed>>
     */
    private function blocos(Collection $registos, CarbonImmutable $dia): array
    {
        $fuso = config('tempos.fuso');
        $fimDoDia = $dia->addDay();

        $blocos = $registos->map(function (RegistoTempo $r) use ($fuso, $dia, $fimDoDia) {
            $de = $r->inicio->setTimezone($fuso);
            $ate = $r->fim->setTimezone($fuso)->min($fimDoDia);
            $minuto = max(0, (int) $dia->diffInMinutes($de));

            return [
                'registo' => $r,
                'registos' => [$r],
                'pessoas' => [PessoaNaAgenda::de($r->tecnico)],
                'minuto' => $minuto,
                'minutos' => max(15, min(24 * 60 - $minuto, (int) $de->diffInMinutes($ate))),
                'inicio' => $de->format('H:i'),
                'fim' => $r->fim->setTimezone($fuso)->format('H:i'),
                'fimReal' => $r->fim,
                'coluna' => 0,
                'colunas' => 1,
            ];
        })->sortBy('minuto')->values()->all();

        if ($this->equipa()) {
            $blocos = $this->juntarPorProjeto($blocos);
        }

        // Sobreposições: cada grupo de blocos que se cruzam divide a largura do dia.
        $grupo = [];
        $fimGrupo = -1;
        foreach ($blocos as $i => $bloco) {
            if ($grupo !== [] && $bloco['minuto'] >= $fimGrupo) {
                $this->arrumarGrupo($blocos, $grupo);
                $grupo = [];
                $fimGrupo = -1;
            }
            $grupo[] = $i;
            $fimGrupo = max($fimGrupo, $bloco['minuto'] + $bloco['minutos']);
        }
        $this->arrumarGrupo($blocos, $grupo);

        return $blocos;
    }

    /**
     * Junta os blocos do mesmo projeto que se sobrepõem (diretamente ou em cadeia) num só: vai do
     * primeiro início ao último fim e leva todos os registos e as pessoas (sem repetir). Os registos
     * sem projeto ficam cada um no seu bloco.
     *
     * @param  list<array<string, mixed>>  $blocos  ordenados pelo início
     * @return list<array<string, mixed>>
     */
    private function juntarPorProjeto(array $blocos): array
    {
        $fuso = config('tempos.fuso');
        $juntos = [];
        $aberto = []; // projeto_id => índice do bloco em $juntos

        foreach ($blocos as $b) {
            $projeto = $b['registo']->projeto_id;
            $i = $projeto ? ($aberto[$projeto] ?? null) : null;

            if ($i === null || $b['minuto'] >= $juntos[$i]['minuto'] + $juntos[$i]['minutos']) {
                $juntos[] = $b;
                if ($projeto) {
                    $aberto[$projeto] = array_key_last($juntos);
                }

                continue;
            }

            $g = &$juntos[$i];
            $g['registos'][] = $b['registo'];
            if (! in_array($b['pessoas'][0]['id'], array_column($g['pessoas'], 'id'), true)) {
                $g['pessoas'][] = $b['pessoas'][0];
            }
            $g['minutos'] = max($g['minuto'] + $g['minutos'], $b['minuto'] + $b['minutos']) - $g['minuto'];
            if ($b['fimReal']->gt($g['fimReal'])) {
                $g['fimReal'] = $b['fimReal'];
                $g['fim'] = $b['fimReal']->setTimezone($fuso)->format('H:i');
            }
            unset($g);
        }

        return $juntos;
    }

    /**
     * @param  list<array<string, mixed>>  $blocos
     * @param  list<int>  $grupo
     */
    private function arrumarGrupo(array &$blocos, array $grupo): void
    {
        if (count($grupo) < 2) {
            return;
        }

        $fins = []; // fim de cada coluna
        foreach ($grupo as $i) {
            $coluna = 0;
            while (isset($fins[$coluna]) && $fins[$coluna] > $blocos[$i]['minuto']) {
                $coluna++;
            }
            $fins[$coluna] = $blocos[$i]['minuto'] + $blocos[$i]['minutos'];
            $blocos[$i]['coluna'] = $coluna;
        }

        $total = count($fins);
        foreach ($grupo as $i) {
            $blocos[$i]['colunas'] = $total;
        }
    }

    /** «Toda a equipa»: os registos de todos (só para quem vê a equipa). */
    private function equipa(): bool
    {
        return $this->pessoa === 'equipa' && Gate::allows('tempos-ver-todos');
    }

    /** De quem é o calendário mostrado: outra pessoa só para quem vê a equipa; senão, quem está a ver. */
    private function quem(): User
    {
        if (! ctype_digit($this->pessoa) || (int) $this->pessoa === auth()->id() || ! Gate::allows('tempos-ver-todos')) {
            return auth()->user();
        }

        return User::comAcessoAosTempos()->find((int) $this->pessoa) ?? auth()->user();
    }

    /** Põe a data no início do período da vista (o dia, a segunda-feira ou o dia 1); vista inválida → semana. */
    private function ajustar(CarbonImmutable $dia): void
    {
        if (! array_key_exists($this->vista, self::VISTAS)) {
            $this->vista = 'semana';
        }

        $inicio = match ($this->vista) {
            'dia' => $dia->startOfDay(),
            'mes' => $dia->startOfMonth(),
            default => $dia->startOfWeek(CarbonImmutable::MONDAY)->startOfDay(),
        };
        $this->data = $inicio->toDateString();
    }

    private function lerData(string $dia): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) && strtotime($dia)
            ? CarbonImmutable::parse($dia, config('tempos.fuso'))->startOfDay()
            : $this->hojeLocal();
    }

    private function inicio(): CarbonImmutable
    {
        return $this->lerData($this->data);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} primeiro e último dia do que se vê */
    private function periodo(): array
    {
        $de = $this->inicio();

        return [$de, match ($this->vista) {
            'dia' => $de,
            'mes' => $de->endOfMonth()->startOfDay(),
            default => $de->addDays(6),
        }];
    }

    private function rotulo(CarbonImmutable $de, CarbonImmutable $ate): string
    {
        $hoje = $this->hojeLocal();

        if ($this->vista === 'dia') {
            return match (true) {
                $de->equalTo($hoje) => 'Hoje',
                $de->equalTo($hoje->subDay()) => 'Ontem',
                $de->equalTo($hoje->addDay()) => 'Amanhã',
                default => ucfirst($de->locale('pt_PT')->isoFormat('ddd, D [de] MMM YYYY')),
            };
        }

        if ($this->vista === 'mes') {
            return ucfirst($de->locale('pt_PT')->isoFormat('MMMM [de] YYYY'));
        }

        $segunda = $hoje->startOfWeek(CarbonImmutable::MONDAY);

        return match (true) {
            $de->equalTo($segunda) => 'Esta semana',
            $de->equalTo($segunda->subWeek()) => 'Semana passada',
            default => $de->format('d/m').' – '.$ate->format('d/m/Y'),
        };
    }
}

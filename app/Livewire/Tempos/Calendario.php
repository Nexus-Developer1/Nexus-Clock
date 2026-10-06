<?php

namespace App\Livewire\Tempos;

use App\Livewire\Concerns\FormularioRegisto;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\Feriados;
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
 * calendário (notas §71).
 */
#[Layout('components.layouts.app', ['ativo' => 'calendario', 'titulo' => 'Calendário'])]
class Calendario extends Component
{
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

    // De quem é o calendário: '' = de quem está a ver; o id de outro membro só para quem vê a equipa.
    #[Url(as: 'pessoa')]
    public string $pessoa = '';

    public function mount(): void
    {
        $this->ajustar($this->lerData($this->data));
        $this->updatedPessoa();
    }

    public function updatedPessoa(): void
    {
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
        $outro = $quem->id !== auth()->id() && Gate::allows('tempos-editar-todos');
        $this->novoDoFormulario($valores + ($outro ? ['tecnico_id' => (string) $quem->id] : []));
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

        return view('livewire.tempos.calendario', [
            'pessoas' => Gate::allows('tempos-ver-todos')
                ? User::comAcessoAosTempos()->whereKeyNot(auth()->id())->orderBy('nome')->pluck('nome', 'id')
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
            ->doTecnico($quem)
            ->whereNotNull('fim')
            ->whereBetween('inicio', [$de->utc(), $ate->addDay()->utc()])
            ->with(['projeto:id,nome,cor,cliente_id', 'projeto.cliente:id,nome'])
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
     * Posição de cada registo no dia (minuto de início, altura e, quando se sobrepõem, lado a lado).
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
                'minuto' => $minuto,
                'minutos' => max(15, min(24 * 60 - $minuto, (int) $de->diffInMinutes($ate))),
                'inicio' => $de->format('H:i'),
                'fim' => $r->fim->setTimezone($fuso)->format('H:i'),
                'coluna' => 0,
                'colunas' => 1,
            ];
        })->sortBy('minuto')->values()->all();

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

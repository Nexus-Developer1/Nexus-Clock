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
 * Calendário da semana (como o "Calendar" do Clockify): os registos de quem está a ver desenhados nas
 * horas de cada dia, arrastar numa coluna para acrescentar tempo e carregar num bloco para o alterar.
 * Os registos sem horas (só duração) ficam numa faixa por cima do dia. Quem vê a equipa
 * (tempos-ver-todos) escolhe de quem é o calendário (notas §71).
 */
#[Layout('components.layouts.app', ['ativo' => 'calendario', 'titulo' => 'Calendário'])]
class Calendario extends Component
{
    use FormularioRegisto {
        novo as private novoDoFormulario;
    }

    /** Altura de uma hora, em pixels (o mesmo valor está na vista). */
    public const ALTURA_HORA = 48;

    #[Url(as: 'de')]
    public string $semana = '';

    // De quem é o calendário: '' = de quem está a ver; o id de outro membro só para quem vê a equipa.
    #[Url(as: 'pessoa')]
    public string $pessoa = '';

    public function mount(): void
    {
        $this->semana = $this->segunda($this->semana)->toDateString();
        $this->updatedPessoa();
    }

    public function updatedPessoa(): void
    {
        if ($this->pessoa !== '' && $this->quem()->id === auth()->id()) {
            $this->pessoa = '';
        }
    }

    /** No calendário de outra pessoa, o tempo acrescentado é dela (para quem pode mexer nas horas de todos). */
    public function novo(array $valores = []): void
    {
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

    public function semanaAnterior(): void
    {
        $this->semana = $this->segunda()->subWeek()->toDateString();
    }

    public function semanaSeguinte(): void
    {
        $this->semana = $this->segunda()->addWeek()->toDateString();
    }

    public function estaSemana(): void
    {
        $this->semana = $this->semanaDeHoje()->toDateString();
    }

    public function render()
    {
        $inicio = $this->segunda();
        $dias = $this->dias($inicio, $this->quem());

        return view('livewire.tempos.calendario', [
            'pessoas' => Gate::allows('tempos-ver-todos')
                ? User::comAcessoAosTempos()->whereKeyNot(auth()->id())->orderBy('nome')->pluck('nome', 'id')
                : collect(),
            'dias' => $dias,
            'totalSemana' => $dias->sum('total'),
            'rotuloSemana' => $this->rotuloSemana($inicio, $inicio->addDays(6)),
            'estaSemana' => $inicio->equalTo($this->semanaDeHoje()),
            'alturaHora' => self::ALTURA_HORA,
        ] + $this->dadosDoFormulario());
    }

    /**
     * Os sete dias da semana, cada um com os blocos (com horas) e os registos só com duração.
     *
     * @return Collection<int, array{data: CarbonImmutable, feriado: ?string, bloqueado: bool, total: int, blocos: list<array<string, mixed>>, semHoras: Collection<int, RegistoTempo>}>
     */
    private function dias(CarbonImmutable $inicio, User $quem): Collection
    {
        $fim = $inicio->addDays(7);

        $registos = RegistoTempo::query()
            ->doTecnico($quem)
            ->whereNotNull('fim')
            ->whereBetween('inicio', [$inicio->utc(), $fim->utc()])
            ->with(['projeto:id,nome,cor,cliente_id', 'projeto.cliente:id,nome'])
            ->orderBy('inicio')
            ->limit(500)
            ->get()
            ->groupBy(fn (RegistoTempo $r) => $r->dia()->toDateString());

        $feriados = app(Feriados::class);

        return collect(range(0, 6))->map(function (int $n) use ($inicio, $registos, $feriados) {
            $data = $inicio->addDays($n);
            $doDia = $registos->get($data->toDateString(), collect());

            return [
                'data' => $data,
                'feriado' => $feriados->nome($data, true),
                'bloqueado' => config('tempos.bloquear_feriados') && $feriados->eFeriado($data),
                'total' => (int) $doDia->sum('duracao_seg'),
                'blocos' => $this->blocos($doDia->filter(fn (RegistoTempo $r) => self::temHorasReais($r)), $data),
                'semHoras' => $doDia->reject(fn (RegistoTempo $r) => self::temHorasReais($r))->values(),
            ];
        });
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

    /** A segunda-feira da semana de hoje. */
    private function semanaDeHoje(): CarbonImmutable
    {
        return $this->hojeLocal()->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    private function segunda(?string $dia = null): CarbonImmutable
    {
        $dia ??= $this->semana;
        $data = $dia && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) && strtotime($dia)
            ? CarbonImmutable::parse($dia, config('tempos.fuso'))
            : $this->hojeLocal();

        return $data->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    private function rotuloSemana(CarbonImmutable $inicio, CarbonImmutable $fim): string
    {
        if ($inicio->equalTo($this->semanaDeHoje())) {
            return 'Esta semana';
        }
        if ($inicio->equalTo($this->semanaDeHoje()->subWeek())) {
            return 'Semana passada';
        }

        return $inicio->format('d/m').' – '.$fim->format('d/m/Y');
    }
}

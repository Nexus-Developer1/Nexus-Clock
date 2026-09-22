<?php

namespace App\Livewire\Tempos;

use App\Enums\OrigemRegistoTempo;
use App\Livewire\Concerns\FormularioRegisto;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Services\Tempos\Cronometro as ServicoCronometro;
use App\Services\Tempos\GravadorRegistos;
use App\Services\Tempos\PainelTempos;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Página de registar horas (como o "Time tracker" do Clockify): barra com o que se está a fazer
 * (descrição, cliente, contrato, projeto, etiquetas, faturável) e cronómetro a correr no servidor,
 * ou modo manual (horas de início e fim); por baixo, os registos da semana agrupados por dia, com
 * continuar, alterar, duplicar e apagar. Cada pessoa vê e mexe só nas suas horas.
 */
#[Layout('components.layouts.app', ['ativo' => 'cronometro', 'titulo' => 'Cronómetro'])]
class Cronometro extends Component
{
    use FormularioRegisto;

    // --- Barra ---

    public string $descricao = '';

    public ?int $barraCliente = null;

    public string $barraBusca = '';

    public string $barraContrato = '';

    public string $barraProjeto = '';

    public string $barraEtiquetas = '';

    public bool $barraFaturavel = true;

    public string $modo = 'cronometro'; // cronometro | manual

    public string $manualInicio = '';

    public string $manualFim = '';

    #[Url(as: 'de')]
    public string $semana = ''; // segunda-feira mostrada

    public function mount(): void
    {
        $this->semana = $this->segunda($this->semana)->toDateString();
        $this->lerCronometro();
    }

    // --- Cronómetro ---

    public function comecar(): void
    {
        $this->executar(function () {
            app(ServicoCronometro::class)->iniciar(auth()->user(), $this->dadosDaBarra());
            $this->modo = 'cronometro';
            $this->semana = $this->segunda()->toDateString();
        });
    }

    public function parar(): void
    {
        $this->executar(function () {
            $registo = app(ServicoCronometro::class)->parar(auth()->user());
            $this->limparBarra();
            session()->flash('sucesso', $registo
                ? 'Registo de '.PainelTempos::hms((int) $registo->duracao_seg).' gravado.'
                : 'Cronómetro descartado: durou menos de um minuto.');
        });
    }

    public function descartar(): void
    {
        $this->executar(function () {
            app(ServicoCronometro::class)->descartar(auth()->user());
            $this->limparBarra();
            session()->flash('sucesso', 'Cronómetro descartado.');
        });
    }

    public function continuar(int $id): void
    {
        $this->executar(function () use ($id) {
            app(ServicoCronometro::class)->continuar(auth()->user(), RegistoTempo::findOrFail($id));
            $this->lerCronometro();
            $this->semana = $this->segunda()->toDateString();
        });
    }

    /** Modo manual: grava as horas indicadas, sem cronómetro. */
    public function acrescentarManual(): void
    {
        $this->executar(function () {
            $dados = $this->dadosDaBarra()
                + $this->tempoIndicado($this->hojeLocal()->toDateString(), $this->manualInicio, $this->manualFim, '')
                + ['tecnico_id' => auth()->id(), 'origem' => OrigemRegistoTempo::Timesheet];

            app(GravadorRegistos::class)->criar(auth()->user(), $dados);
            $this->limparBarra();
            session()->flash('sucesso', 'Registo acrescentado.');
        });
    }

    // --- Barra: cliente ---

    public function selecionarClienteBarra(int $id): void
    {
        $cliente = Cliente::find($id);
        $this->barraCliente = $cliente?->id;
        $this->barraContrato = '';
        $this->barraBusca = $cliente?->nome ?? '';
        $this->sincronizar();
    }

    public function updatedBarraBusca(): void
    {
        $this->barraCliente = null;
        $this->barraContrato = '';
    }

    public function updated(string $propriedade): void
    {
        if (in_array($propriedade, ['descricao', 'barraContrato', 'barraProjeto', 'barraEtiquetas', 'barraFaturavel'], true)) {
            $this->sincronizar();
        }
    }

    // --- Semana ---

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
        $fim = $inicio->addDays(6);
        $aCorrer = app(ServicoCronometro::class)->aCorrer(auth()->user());
        $registos = $this->registosDaSemana($inicio, $fim);

        return view('livewire.tempos.cronometro', [
            'aCorrer' => $aCorrer,
            'inicioACorrer' => $aCorrer?->inicio->getTimestamp(),
            'dias' => $registos,
            'totalSemana' => $registos->sum(fn (array $dia) => $dia['total']),
            'inicio' => $inicio,
            'fim' => $fim,
            'rotuloSemana' => $this->rotuloSemana($inicio, $fim),
            'estaSemana' => $inicio->equalTo($this->semanaDeHoje()),
            'clientesBarra' => $this->pesquisarClientes($this->barraBusca),
            'contratosBarra' => $this->barraCliente
                ? Contrato::where('cliente_id', $this->barraCliente)->orderByDesc('data_inicio')->pluck('numero', 'id')->all()
                : [],
            'projetos' => ProjetoTempo::visiveisPara(auth()->user())->ativos()->orderByRaw('lower(nome)')->get(['id', 'nome', 'cor']),
        ] + $this->dadosDoFormulario());
    }

    /**
     * Os registos da semana (só os de quem está a ver), por dia, do mais recente para o mais antigo.
     *
     * @return Collection<int, array{dia: CarbonImmutable, total: int, registos: Collection<int, RegistoTempo>}>
     */
    private function registosDaSemana(CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        $registos = RegistoTempo::query()
            ->doTecnico(auth()->user())
            ->whereNotNull('fim')
            ->whereBetween('inicio', [$inicio->utc(), $fim->endOfDay()->utc()])
            ->with(['cliente:id,nome', 'contrato:id,numero', 'projeto:id,nome,cor'])
            ->orderByDesc('inicio')
            ->limit(500)
            ->get();

        return $registos
            ->groupBy(fn (RegistoTempo $r) => $r->dia()->toDateString())
            ->map(fn (Collection $doDia, string $dia) => [
                'dia' => CarbonImmutable::parse($dia),
                'total' => (int) $doDia->sum('duracao_seg'),
                'registos' => $doDia,
            ])
            ->sortKeysDesc()
            ->values();
    }

    /** @return array<string, mixed> */
    private function dadosDaBarra(): array
    {
        return [
            'cliente_id' => $this->barraCliente,
            'contrato_id' => $this->barraContrato !== '' ? (int) $this->barraContrato : null,
            'projeto_id' => $this->barraProjeto !== '' ? (int) $this->barraProjeto : null,
            'descricao' => trim($this->descricao) ?: null,
            'faturavel' => $this->barraFaturavel,
            'etiquetas' => self::lerEtiquetas($this->barraEtiquetas),
        ];
    }

    /** Enquanto o cronómetro corre, o que se escreve na barra vai para o registo. */
    private function sincronizar(): void
    {
        $registo = app(ServicoCronometro::class)->aCorrer(auth()->user());
        if (! $registo || ! $this->barraCliente) {
            return;
        }

        try {
            app(GravadorRegistos::class)->atualizar(auth()->user(), $registo, $this->dadosDaBarra());
            $this->erro = null;
        } catch (ValidationException|AuthorizationException) {
            // A barra fica como está; o erro aparece quando parar.
        }
    }

    private function lerCronometro(): void
    {
        $registo = app(ServicoCronometro::class)->aCorrer(auth()->user());
        if (! $registo) {
            return;
        }

        $this->descricao = (string) $registo->descricao;
        $this->barraCliente = $registo->cliente_id;
        $this->barraBusca = (string) $registo->cliente?->nome;
        $this->barraContrato = (string) $registo->contrato_id;
        $this->barraProjeto = (string) $registo->projeto_id;
        $this->barraEtiquetas = implode(', ', $registo->etiquetas);
        $this->barraFaturavel = $registo->faturavel;
    }

    private function limparBarra(): void
    {
        $this->reset(['descricao', 'barraCliente', 'barraBusca', 'barraContrato', 'barraProjeto', 'barraEtiquetas', 'manualInicio', 'manualFim']);
        $this->barraFaturavel = true;
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

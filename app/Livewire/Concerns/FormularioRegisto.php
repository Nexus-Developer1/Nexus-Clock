<?php

namespace App\Livewire\Concerns;

use App\Enums\OrigemRegistoTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Tempos\GravadorRegistos;
use App\Services\Tempos\LeitorDuracao;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Formulário de um registo de tempo (acrescentar, alterar, duplicar, apagar), partilhado pelo
 * relatório Detalhado, pelo Cronómetro e pelo Calendário. A vista é o partial
 * `livewire.partials.formulario-registo`; toda a escrita passa pelo GravadorRegistos.
 */
trait FormularioRegisto
{
    // null = fechado, 0 = novo, id = alterar.
    public ?int $editarId = null;

    /** @var array{tecnico_id: string, projeto_id: string, dia: string, hora_inicio: string, hora_fim: string, duracao: string, descricao: string, faturavel: bool, etiquetas: string} */
    public array $formulario = [];

    public ?string $erro = null;

    /** @param array<string, mixed> $valores campos já preenchidos (dia, horas…) */
    public function novo(array $valores = []): void
    {
        $this->erro = null;
        $this->resetErrorBag();
        $this->editarId = 0;
        $this->formulario = $valores + [
            'tecnico_id' => (string) auth()->id(), 'projeto_id' => '',
            'dia' => $this->hojeLocal()->toDateString(), 'hora_inicio' => '', 'hora_fim' => '', 'duracao' => '',
            'descricao' => '', 'faturavel' => true, 'etiquetas' => '',
        ];
    }

    public function editar(int $id): void
    {
        $registo = RegistoTempo::findOrFail($id);
        Gate::authorize('view', $registo);
        $local = fn (?CarbonImmutable $d) => $d?->setTimezone(config('tempos.fuso'));
        $horas = self::temHorasReais($registo);

        $this->erro = null;
        $this->resetErrorBag();
        $this->editarId = $registo->id;
        $this->formulario = [
            'tecnico_id' => (string) $registo->tecnico_id,
            'projeto_id' => (string) $registo->projeto_id,
            'dia' => $registo->dia()->toDateString(),
            'hora_inicio' => $horas ? $local($registo->inicio)->format('H:i') : '',
            'hora_fim' => $horas && $registo->fim ? $local($registo->fim)->format('H:i') : '',
            'duracao' => $horas ? '' : LeitorDuracao::formatar($registo->duracao_seg),
            'descricao' => (string) $registo->descricao,
            'faturavel' => $registo->faturavel,
            'etiquetas' => implode(', ', $registo->etiquetas),
        ];
    }

    public function fecharFormulario(): void
    {
        $this->reset(['editarId', 'formulario']);
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        if ($this->editarId === null) {
            return;
        }

        $this->resetErrorBag();
        $f = $this->formulario;

        try {
            $dados = [
                'projeto_id' => $f['projeto_id'] !== '' ? (int) $f['projeto_id'] : null,
                'descricao' => trim($f['descricao']) ?: null,
                'faturavel' => (bool) $f['faturavel'],
                'etiquetas' => self::lerEtiquetas($f['etiquetas']),
            ] + $this->tempoIndicado($f['dia'], $f['hora_inicio'], $f['hora_fim'], $f['duracao']);

            $gravador = app(GravadorRegistos::class);
            if ($this->editarId === 0) {
                $dados['tecnico_id'] = $this->tecnicoDoNovo((int) $f['tecnico_id'])->id;
                $dados['origem'] = OrigemRegistoTempo::Timesheet;
                $gravador->criar(auth()->user(), $dados);
            } else {
                $gravador->atualizar(auth()->user(), RegistoTempo::findOrFail($this->editarId), $dados);
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('formulario.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('formulario.geral', 'Não pode gravar este registo: está numa semana entregue, num mês fechado, já foi faturado, ou é de outra pessoa.');

            return;
        }

        $novo = $this->editarId === 0;
        $this->fecharFormulario();
        session()->flash('sucesso', $novo ? 'Registo acrescentado.' : 'Registo alterado.');
    }

    public function duplicar(int $id): void
    {
        $this->executar(function () use ($id) {
            $modelo = RegistoTempo::findOrFail($id);
            Gate::authorize('view', $modelo);

            app(GravadorRegistos::class)->criar(auth()->user(), [
                'tecnico_id' => $modelo->tecnico_id,
                'projeto_id' => $modelo->projeto_id && ! ProjetoTempo::find($modelo->projeto_id)?->estaArquivado() ? $modelo->projeto_id : null,
                'descricao' => $modelo->descricao,
                'faturavel' => $modelo->faturavel,
                'etiquetas' => $modelo->etiquetas,
                'inicio' => $modelo->inicio,
                'fim' => $modelo->fim,
                'origem' => $modelo->origem,
            ]);
            session()->flash('sucesso', 'Registo duplicado.');
        });
    }

    public function apagar(int $id): void
    {
        $this->executar(function () use ($id) {
            app(GravadorRegistos::class)->apagar(auth()->user(), RegistoTempo::findOrFail($id));
            session()->flash('sucesso', 'Registo apagado.');
        });
    }

    /** Registo com horas reais (cronómetro ou início/fim indicados), em vez de só uma duração no dia. */
    public static function temHorasReais(RegistoTempo $registo): bool
    {
        return $registo->origem === OrigemRegistoTempo::Cronometro
            || ! $registo->inicio->equalTo(RegistoTempo::inicioDoDia($registo->dia()));
    }

    /** @return list<string> */
    public static function lerEtiquetas(string $texto): array
    {
        return collect(explode(',', $texto))->map(fn ($e) => trim($e))->filter()->unique()->values()->all();
    }

    /**
     * O que a vista do formulário precisa (membros e projetos).
     *
     * @return array<string, mixed>
     */
    protected function dadosDoFormulario(): array
    {
        $aberto = $this->editarId !== null;
        $projetoAtual = (int) ($this->formulario['projeto_id'] ?? 0);

        return [
            'membrosDoNovo' => Gate::allows('tempos-editar-todos')
                ? User::comAcessoAosTempos()->orderBy('nome')->pluck('nome', 'id')->all()
                : [auth()->id() => auth()->user()->nome],
            'projetosDoFormulario' => $aberto
                ? ProjetoTempo::visiveisPara(auth()->user())->where(fn ($q) => $q->whereNull('arquivado_em')->orWhere('id', $projetoAtual))->with('cliente:id,nome')->orderByRaw('lower(nome)')->get(['id', 'nome', 'cor', 'cliente_id'])
                : collect(),
        ];
    }

    /** Quem gere acrescenta tempo para qualquer membro; os outros só para si. */
    protected function tecnicoDoNovo(int $id): User
    {
        if ($id !== auth()->id()) {
            if (! Gate::allows('tempos-editar-todos')) {
                throw new AuthorizationException;
            }

            return User::comAcessoAosTempos()->find($id)
                ?? throw ValidationException::withMessages(['tecnico_id' => 'Escolha um membro da equipa.']);
        }

        return auth()->user();
    }

    /**
     * Tempo indicado no formulário: início e fim (horas locais) ou só a duração no dia.
     *
     * @return array<string, mixed>
     */
    protected function tempoIndicado(string $dia, string $horaInicio, string $horaFim, string $duracao): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) || ! strtotime($dia)) {
            throw ValidationException::withMessages(['dia' => 'Indique o dia.']);
        }

        if (trim($horaInicio) !== '' || trim($horaFim) !== '') {
            if (trim($horaInicio) === '' || trim($horaFim) === '') {
                throw ValidationException::withMessages(['hora' => 'Indique a hora de início e a de fim (ou só a duração).']);
            }

            $inicio = $this->instante($dia, $horaInicio);
            $fim = $this->instante($dia, $horaFim);
            if ($fim->lte($inicio)) {
                $fim = $fim->addDay(); // ex.: 22:00 → 02:00 acaba no dia seguinte
            }

            return ['inicio' => $inicio, 'fim' => $fim];
        }

        try {
            $segundos = LeitorDuracao::ler($duracao);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['duracao' => $e->getMessage()]);
        }

        if (! $segundos) {
            throw ValidationException::withMessages(['duracao' => 'Indique a duração (ex.: 1:30) ou as horas de início e fim.']);
        }

        return ['dia' => $dia, 'duracao_seg' => $segundos];
    }

    protected function instante(string $dia, string $hora): CarbonImmutable
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hora))) {
            throw ValidationException::withMessages(['hora' => 'Hora inválida: use o formato 09:30.']);
        }

        return CarbonImmutable::parse($dia.' '.trim($hora), config('tempos.fuso'))->utc();
    }

    protected function executar(callable $acao): void
    {
        $this->erro = null;

        try {
            $acao();
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();
        } catch (AuthorizationException $e) {
            // Quando o serviço explica a recusa, é essa a mensagem a mostrar.
            $this->erro = in_array($e->getMessage(), ['', 'This action is unauthorized.'], true)
                ? 'Não pode alterar este registo: está numa semana entregue, num mês fechado, já foi faturado, ou é de outra pessoa.'
                : $e->getMessage();
        }
    }

    protected function hojeLocal(): CarbonImmutable
    {
        return CarbonImmutable::now(config('tempos.fuso'))->startOfDay();
    }
}

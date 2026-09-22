<?php

namespace App\Livewire\Relatorios;

use App\Livewire\Relatorios\Concerns\PeriodoEFiltros;
use App\Models\AtribuicaoTempo;
use App\Models\ClienteTempo;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Tempos\GestorAtribuicoes;
use App\Services\Tempos\LeitorDuracao;
use App\Services\Tempos\PainelTempos;
use App\Services\Tempos\RelatorioAtribuicoes;
use App\Support\Csv;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatório Atribuições (como o "Assignments report" do Clockify): horas agendadas (atribuições) contra
 * registadas, por membro › projeto (ou projeto/cliente), com diferença e estado; mostrar quem não tem
 * tempo; filtros por pessoas, cliente e projeto; exportar CSV e imprimir. Quem gere a equipa cria, altera
 * e apaga atribuições; os outros só veem as suas.
 */
#[Layout('components.layouts.app', ['ativo' => 'relatorios.atribuicoes', 'titulo' => 'Atribuições'])]
class Atribuicoes extends Component
{
    use PeriodoEFiltros;

    #[Url(as: 'agrupar')]
    public string $agrupar1 = 'membro';

    #[Url(as: 'depois')]
    public string $agrupar2 = 'projeto';

    #[Url(as: 'sem_tempo')]
    public bool $semTempo = false;

    #[Url(as: 'ordem')]
    public string $ordem = 'nome'; // nome | agendado | registado | diferenca, "-" = descendente

    // Formulário (null = fechado, 0 = nova).
    public ?int $editarId = null;

    /** @var array{utilizador_id: string, projeto_id: string, de: string, ate: string, horas_dia: string, fins_de_semana: bool, nota: string} */
    public array $formulario = [];

    public function ordenarPor(string $campo): void
    {
        if (in_array($campo, ['nome', 'agendado', 'registado', 'diferenca'], true)) {
            $this->ordem = ltrim($this->ordem, '-') === $campo
                ? (str_starts_with($this->ordem, '-') ? $campo : '-'.$campo)
                : ($campo === 'nome' ? 'nome' : '-'.$campo);
        }
    }

    public function nova(): void
    {
        [$de, $ate] = $this->periodo();
        $this->resetErrorBag();
        $this->editarId = 0;
        $this->formulario = [
            'utilizador_id' => (string) (count($this->membros) === 1 ? $this->membros[0] : ''),
            'projeto_id' => (string) (count($this->projetos) === 1 ? $this->projetos[0] : ''),
            'de' => $de->toDateString(), 'ate' => $ate->toDateString(), 'horas_dia' => '', 'fins_de_semana' => false, 'nota' => '',
        ];
    }

    public function editar(int $id): void
    {
        $a = AtribuicaoTempo::findOrFail($id);
        $this->resetErrorBag();
        $this->editarId = $a->id;
        $this->formulario = [
            'utilizador_id' => (string) $a->utilizador_id,
            'projeto_id' => (string) $a->projeto_id,
            'de' => $a->de->toDateString(),
            'ate' => $a->ate->toDateString(),
            'horas_dia' => LeitorDuracao::formatar($a->horas_dia_seg),
            'fins_de_semana' => $a->fins_de_semana,
            'nota' => (string) $a->nota,
        ];
    }

    public function fechar(): void
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

        try {
            $gestor = app(GestorAtribuicoes::class);
            $this->editarId === 0
                ? $gestor->criar(auth()->user(), $this->formulario)
                : $gestor->atualizar(auth()->user(), AtribuicaoTempo::findOrFail($this->editarId), $this->formulario);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensagens) {
                $this->addError('formulario.'.$campo, $mensagens[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('formulario.utilizador_id', 'Só quem gere a equipa cria atribuições.');

            return;
        }

        $nova = $this->editarId === 0;
        $this->fechar();
        session()->flash('sucesso', $nova ? 'Atribuição criada.' : 'Atribuição guardada.');
    }

    public function apagar(int $id): void
    {
        app(GestorAtribuicoes::class)->apagar(auth()->user(), AtribuicaoTempo::findOrFail($id));
        session()->flash('sucesso', 'Atribuição apagada.');
    }

    public function exportar()
    {
        [$de, $ate] = $this->periodo();
        $r = $this->dados();
        $hms = fn (int $s) => PainelTempos::hms($s);
        $estados = RelatorioAtribuicoes::ESTADOS;
        $nomes = RelatorioAtribuicoes::AGRUPAMENTOS;

        $linhas = [];
        foreach ($this->ordenar($r['grupos']) as $g) {
            $linhas[] = [$g['nome'], '', $hms($g['agendado']), $hms($g['registado']), $this->diferenca($g['diferenca']), $estados[$g['estado']]];
            foreach ($this->ordenar($g['filhos']) as $f) {
                $linhas[] = [$g['nome'], $f['nome'], $hms($f['agendado']), $hms($f['registado']), $this->diferenca($f['diferenca']), $estados[$f['estado']]];
            }
        }

        return Csv::resposta('atribuicoes-'.$de->format('Ymd').'-'.$ate->format('Ymd').'.csv',
            [$nomes[$this->agrupar1], $this->agrupar2 !== '' ? $nomes[$this->agrupar2] : '', 'Agendado', 'Registado', 'Diferença', 'Estado'], $linhas);
    }

    public function render()
    {
        [$de, $ate] = $this->periodo();
        $r = $this->dados();
        $podeGerir = Gate::allows('tempos-gerir-equipa');
        $podeVerEquipa = Gate::allows('tempos-ver-todos');

        return view('livewire.relatorios.atribuicoes', [
            'r' => $r,
            'grupos' => $this->ordenar($r['grupos']),
            'de' => $de,
            'ate' => $ate,
            'rotuloPeriodo' => $this->rotuloPeriodo($de, $ate),
            'podeVerEquipa' => $podeVerEquipa,
            'podeGerir' => $podeGerir,
            'opcoes' => [
                'membros' => User::comAcessoAosTempos()->orderBy('nome')->pluck('nome', 'id')->all(),
                'clientes' => ClienteTempo::orderByRaw('lower(nome)')->pluck('nome', 'id')->all(),
                'projetos' => ProjetoTempo::visiveisPara(auth()->user())->orderByRaw('arquivado_em is not null, lower(nome)')->pluck('nome', 'id')->all(),
            ],
            'projetosFormulario' => $this->editarId !== null
                ? ProjetoTempo::where(fn ($q) => $q->whereNull('arquivado_em')->orWhere('id', (int) ($this->formulario['projeto_id'] ?? 0)))->orderByRaw('lower(nome)')->pluck('nome', 'id')->all()
                : [],
            'agrupamentos' => RelatorioAtribuicoes::AGRUPAMENTOS,
            'estados' => RelatorioAtribuicoes::ESTADOS,
            'filtrosAtivos' => count($this->membros) + count($this->clientes) + count($this->projetos),
        ]);
    }

    /** "+1:30:00", "−0:45:00" ou "0:00:00". */
    public function diferenca(int $segundos): string
    {
        return ($segundos > 0 ? '+' : ($segundos < 0 ? '−' : '')).PainelTempos::hms(abs($segundos));
    }

    /** Subgrupos pela mesma ordem da tabela. */
    public function ordenarFilhos(array $filhos): array
    {
        return $this->ordenar($filhos);
    }

    public function limparFiltros(): void
    {
        $this->reset(['membros', 'clientes', 'projetos']);
    }

    private function dados(): array
    {
        [$de, $ate] = $this->periodo();
        $f = $this->filtrosDoServico();

        return app(RelatorioAtribuicoes::class)->gerar(
            ['membros' => $f['membros'], 'clientes' => $f['clientes'], 'projetos' => $f['projetos']],
            $de, $ate, $this->agrupar1, $this->agrupar2 !== '' ? $this->agrupar2 : null, $this->semTempo,
        );
    }

    /** @return list<array<string, mixed>> */
    private function ordenar(array $linhas): array
    {
        $campo = ltrim($this->ordem, '-');

        return collect($linhas)
            ->sortBy(fn ($l) => mb_strtolower($l['nome']), SORT_REGULAR, $campo === 'nome' && str_starts_with($this->ordem, '-'))
            ->when($campo !== 'nome', fn ($c) => $c->sortBy(fn ($l) => $l[$campo], SORT_REGULAR, str_starts_with($this->ordem, '-')))
            ->values()->all();
    }

    private function normalizar(): void
    {
        $this->normalizarPeriodoEFiltros();

        if (! isset(RelatorioAtribuicoes::AGRUPAMENTOS[$this->agrupar1])) {
            $this->agrupar1 = 'membro';
        }
        if ($this->agrupar2 === $this->agrupar1 || ($this->agrupar2 !== '' && ! isset(RelatorioAtribuicoes::AGRUPAMENTOS[$this->agrupar2]))) {
            $this->agrupar2 = '';
        }
        if (! in_array(ltrim($this->ordem, '-'), ['nome', 'agendado', 'registado', 'diferenca'], true)) {
            $this->ordem = 'nome';
        }
    }
}

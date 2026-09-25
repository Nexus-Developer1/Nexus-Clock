<?php

namespace App\Livewire\Equipa;

use App\Models\AtribuicaoTempo;
use App\Models\MembroEquipa;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Página de um membro da equipa (carregar na linha, em Equipa › Membros) — notas §48: dados, grupos,
 * horas da semana (face à capacidade) e do mês, horas do mês por projeto e atribuições em curso e
 * futuras. Toda a gente vê a Equipa (§18); as horas por pessoa e por projeto já são visíveis a todos
 * nas Atribuições (§43).
 *
 * Privacidade: um projeto privado de que quem vê não é membro não aparece pelo nome — as horas dele
 * contam no total, mas vão para «Outros projetos (privados)», sem link. Taxas só para quem gere a
 * equipa, como na lista.
 */
class Detalhe extends Component
{
    public MembroEquipa $membro;

    public function mount(MembroEquipa $membro): void
    {
        $this->membro = $membro;
    }

    public function render()
    {
        $m = $this->membro->loadMissing(['utilizador:id,nome,email', 'grupos:id,nome', 'gestor.utilizador:id,nome', 'taxas']);
        $fuso = config('tempos.fuso');
        $hoje = CarbonImmutable::now($fuso)->startOfDay();
        $semana = [$hoje->startOfWeek(), $hoje->endOfWeek()];
        $mes = [$hoje->startOfMonth(), $hoje->endOfMonth()];
        $podeGerir = Gate::allows('tempos-gerir-equipa');
        $visiveis = ProjetoTempo::withTrashed()->visiveisPara(auth()->user())->pluck('id')->all();

        $porProjeto = collect();
        $semanaSeg = 0;
        $atribuicoes = collect();

        if ($m->utilizador_id && ! $m->limitado) {
            $registos = fn (array $periodo) => RegistoTempo::query()->toBase()
                ->leftJoin('projetos_tempos as p', 'p.id', '=', 'registos_tempo.projeto_id')
                ->where('registos_tempo.tecnico_id', $m->utilizador_id)
                ->whereNull('registos_tempo.deleted_at')
                ->whereNotNull('registos_tempo.duracao_seg')
                ->where('registos_tempo.inicio', '>=', $periodo[0]->utc())
                ->where('registos_tempo.inicio', '<', $periodo[1]->addDay()->startOfDay()->utc());

            $semanaSeg = (int) $registos($semana)->sum('registos_tempo.duracao_seg');

            $linhas = $registos($mes)
                ->groupBy('registos_tempo.projeto_id')
                ->selectRaw('registos_tempo.projeto_id, sum(registos_tempo.duracao_seg) as total')
                ->selectRaw('sum(case when registos_tempo.faturavel and coalesce(p.faturavel, true) then registos_tempo.duracao_seg else 0 end) as faturavel')
                ->get();
            $projetos = ProjetoTempo::withTrashed()->with('cliente:id,nome')->whereIn('id', $linhas->pluck('projeto_id')->filter())->get()->keyBy('id');

            // Os privados que quem vê não pode ver juntam-se numa linha só, sem nome.
            $porProjeto = $linhas->groupBy(fn ($l) => $l->projeto_id === null ? 'sem' : (in_array((int) $l->projeto_id, $visiveis, true) ? 'p'.$l->projeto_id : 'privados'))
                ->map(function ($grupo, $chave) use ($projetos) {
                    $projeto = str_starts_with($chave, 'p') ? $projetos->get((int) substr($chave, 1)) : null;

                    return (object) [
                        'chave' => $chave,
                        'projeto' => $projeto,
                        'total' => (int) $grupo->sum('total'),
                        'faturavel' => (int) $grupo->sum('faturavel'),
                    ];
                })
                ->sortByDesc('total')->values();

            $atribuicoes = AtribuicaoTempo::with('projeto:id,nome,cor')->where('utilizador_id', $m->utilizador_id)
                ->where('ate', '>=', $hoje->toDateString())->orderBy('de')->orderBy('id')->get();
        }

        $mesSeg = (int) $porProjeto->sum('total');
        $mesFaturavel = (int) $porProjeto->sum('faturavel');
        $capacidadeSemanal = $m->capacidade_diaria_seg ? $m->capacidade_diaria_seg * count($m->dias_trabalho) : null;

        return view('livewire.equipa.detalhe', [
            'semanaSeg' => $semanaSeg,
            'capacidadeSemanal' => $capacidadeSemanal,
            'mesSeg' => $mesSeg,
            'mesFaturavel' => $mesFaturavel,
            'porProjeto' => $porProjeto,
            'atribuicoes' => $atribuicoes,
            'visiveis' => $visiveis,
            'hoje' => $hoje,
            'rotuloMes' => ucfirst($hoje->locale('pt_PT')->translatedFormat('F')),
            'podeGerir' => $podeGerir,
            'taxaFaturavel' => $podeGerir ? $m->taxaEm('faturavel', $hoje) : null,
            'taxaCusto' => $podeGerir ? $m->taxaEm('custo', $hoje) : null,
            // O Detalhado só mostra as horas dos outros a quem vê a equipa toda.
            'verRegistos' => $m->utilizador_id && ($m->utilizador_id === auth()->id() || Gate::allows('tempos-ver-todos')),
        ])->layout('components.layouts.app', ['ativo' => 'equipa', 'titulo' => $m->nomeVisivel()]);
    }
}

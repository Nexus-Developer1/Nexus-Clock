<?php

namespace App\Livewire\Projetos;

use App\Models\AtribuicaoTempo;
use App\Models\DespesaTempo;
use App\Models\ProjetoTempo;
use App\Models\RegistoTempo;
use App\Services\Tempos\HorasProjetos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Página de um projeto (carregar no nome na lista de Projetos, ou na página do cliente): dados,
 * horas (total, faturáveis, progresso face à estimativa), despesas, quem registou horas e quanto,
 * membros (se privado) e as atribuições em curso e futuras — notas §47.
 *
 * Quem vê: o mesmo que a lista (`visiveisPara`): um projeto privado dá 404 a quem não é membro, sem
 * revelar que existe. As horas por pessoa são as que as Atribuições já mostram a toda a gente (§43);
 * taxa e valor em euros só para quem gere os projetos, como na lista.
 */
class Detalhe extends Component
{
    public ProjetoTempo $projeto;

    public function mount(ProjetoTempo $projeto): void
    {
        abort_unless(ProjetoTempo::visiveisPara(auth()->user())->whereKey($projeto->id)->exists(), 404);
        $this->projeto = $projeto;
    }

    public function render()
    {
        $p = $this->projeto->loadMissing(['cliente:id,nome,deleted_at', 'membros.utilizador:id,nome']);
        $podeGerir = Gate::allows('tempos-gerir-projetos');
        $hoje = CarbonImmutable::now(config('tempos.fuso'))->startOfDay();

        // Horas por pessoa (só registos terminados). Faturável só conta se o projeto o for.
        $porPessoa = RegistoTempo::query()->toBase()
            ->join('utilizadores as u', 'u.id', '=', 'registos_tempo.tecnico_id')
            ->where('registos_tempo.projeto_id', $p->id)
            ->whereNull('registos_tempo.deleted_at')
            ->whereNotNull('registos_tempo.duracao_seg')
            ->groupBy('u.id', 'u.nome')
            ->selectRaw('u.id, u.nome, sum(registos_tempo.duracao_seg) as total')
            ->selectRaw('sum(case when registos_tempo.faturavel then registos_tempo.duracao_seg else 0 end) as faturavel')
            ->selectRaw('max(registos_tempo.inicio) as ultimo')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($l) => (object) [
                'nome' => $l->nome,
                'total' => (int) $l->total,
                'faturavel' => $p->faturavel ? (int) $l->faturavel : 0,
                'ultimo' => CarbonImmutable::parse($l->ultimo)->setTimezone(config('tempos.fuso')),
            ]);

        $total = (int) $porPessoa->sum('total');
        $faturavel = (int) $porPessoa->sum('faturavel');

        $despesas = DespesaTempo::query()->where('projeto_id', $p->id)
            ->selectRaw("coalesce(sum(case when estado = 'aprovada' then valor_cent else 0 end), 0) as aprovadas, count(*) filter (where estado = 'pendente') as pendentes")
            ->first();

        return view('livewire.projetos.detalhe', [
            'porPessoa' => $porPessoa,
            'total' => $total,
            'faturavel' => $faturavel,
            'progresso' => $p->estimativa_seg ? round($total * 100 / $p->estimativa_seg, 1) : null,
            'despesasCent' => (int) $despesas->aprovadas,
            'despesasPendentes' => (int) $despesas->pendentes,
            'atribuicoes' => AtribuicaoTempo::with('utilizador:id,nome')->where('projeto_id', $p->id)
                ->where('ate', '>=', $hoje->toDateString())->orderBy('de')->orderBy('id')->get(),
            'membros' => $p->publico ? collect() : $p->membros->map(fn ($m) => $m->nomeVisivel())->sort()->values(),
            'podeGerir' => $podeGerir,
            // Dinheiro só para quem gere (na lista também só eles veem o valor).
            'valorCent' => $podeGerir ? (app(HorasProjetos::class)->porProjeto([$p->id])[$p->id]['valor_cent'] ?? 0) : null,
            'hoje' => $hoje,
        ])->layout('components.layouts.app', ['ativo' => 'projetos', 'titulo' => $p->nome]);
    }
}

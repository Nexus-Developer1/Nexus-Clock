<?php

namespace App\Services\Tempos;

use App\Models\DespesaTempo;
use App\Models\ProjetoTempo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Relatório Despesas (como o "Expense report" do Clockify): as despesas dos Tempos no período, com
 * filtros por pessoas, cliente (do projeto), projeto, categoria, estado e nota; totais e recibos.
 */
class RelatorioDespesas
{
    public const ORDENS = ['data', 'membro', 'projeto', 'categoria', 'valor', 'estado'];

    /**
     * @param  array{membros?: list<int>|null, clientes?: list<int>, projetos?: list<int>, categorias?: list<int>, estado?: string, nota?: string}  $filtros
     * @return Builder<DespesaTempo>
     */
    public function consulta(array $filtros, CarbonImmutable $de, CarbonImmutable $ate, string $ordem = '-data'): Builder
    {
        $membros = $filtros['membros'] ?? null;
        $projetos = $filtros['projetos'] ?? [];
        $nota = trim((string) ($filtros['nota'] ?? ''));
        $desc = str_starts_with($ordem, '-') ? 'desc' : 'asc';

        $q = DespesaTempo::query()
            ->with(['utilizador:id,nome', 'projeto:id,nome,cor,cliente_id', 'projeto.cliente:id,nome', 'categoria:id,nome'])
            ->whereBetween('data', [$de->toDateString(), $ate->toDateString()])
            ->when($membros !== null, fn ($q) => $q->whereIn('utilizador_id', $membros))
            ->when(($filtros['clientes'] ?? []) !== [], fn ($q) => $q->whereIn('projeto_id', ProjetoTempo::withTrashed()->whereIn('cliente_id', $filtros['clientes'])->select('id')))
            ->when($projetos !== [], fn ($q) => $q->where(function ($w) use ($projetos) {
                $w->whereIn('projeto_id', array_values(array_filter($projetos)) ?: [-1]);
                if (in_array(0, $projetos, true)) {
                    $w->orWhereNull('projeto_id');
                }
            }))
            ->when(($filtros['categorias'] ?? []) !== [], fn ($q) => $q->whereIn('categoria_id', $filtros['categorias']))
            ->when(isset(DespesaTempo::ESTADOS[$filtros['estado'] ?? '']), fn ($q) => $q->where('estado', $filtros['estado']))
            ->when($nota !== '', fn ($q) => $q->where('nota', 'ilike', '%'.addcslashes($nota, '%_\\').'%'));

        match (ltrim($ordem, '-')) {
            'membro' => $q->orderByRaw('(select lower(u.nome) from utilizadores u where u.id = despesas_tempos.utilizador_id) '.$desc),
            'projeto' => $q->orderByRaw('(select lower(p.nome) from projetos_tempos p where p.id = despesas_tempos.projeto_id) '.$desc.' nulls last'),
            'categoria' => $q->orderByRaw('(select lower(c.nome) from categorias_despesa_tempos c where c.id = despesas_tempos.categoria_id) '.$desc),
            'valor' => $q->orderBy('valor_cent', $desc),
            'estado' => $q->orderBy('estado', $desc),
            default => $q->orderBy('data', $desc),
        };

        return $q->orderBy('data', $desc)->orderBy('id', $desc);
    }

    /** @return array{total: int, faturavel: int, registos: int, pendentes: int, recibos: int} */
    public function totais(array $filtros, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $t = $this->consulta($filtros, $de, $ate)->reorder()->toBase()
            ->selectRaw('coalesce(sum(valor_cent), 0) as total')
            ->selectRaw('coalesce(sum(case when faturavel then valor_cent else 0 end), 0) as faturavel')
            ->selectRaw('count(*) as registos')
            ->selectRaw("count(*) filter (where estado = 'pendente') as pendentes")
            ->selectRaw('count(recibo_caminho) as recibos')
            ->first();

        return ['total' => (int) $t->total, 'faturavel' => (int) $t->faturavel, 'registos' => (int) $t->registos, 'pendentes' => (int) $t->pendentes, 'recibos' => (int) $t->recibos];
    }

    /** Um ZIP com os recibos das despesas (nomes: data, pessoa, valor e nome original). Null se não houver. */
    public function zipRecibos(iterable $despesas): ?string
    {
        $disco = Storage::disk(DespesaTempo::DISCO);
        $caminho = tempnam(sys_get_temp_dir(), 'recibos');
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::OVERWRITE);
        $n = 0;

        foreach ($despesas as $d) {
            if (! $d->recibo_caminho || ! $disco->exists($d->recibo_caminho)) {
                continue;
            }
            $pessoa = preg_replace('/[^\pL\pN]+/u', '-', (string) ($d->utilizador?->nome ?? 'sem-nome'));
            $nome = sprintf('%s_%s_%s_%d_%s', $d->data->format('Y-m-d'), trim($pessoa, '-'), number_format($d->valor_cent / 100, 2, ',', ''), $d->id, basename((string) $d->recibo_nome));
            $zip->addFromString($nome, $disco->get($d->recibo_caminho));
            $n++;
        }
        $zip->close();

        if ($n === 0) {
            @unlink($caminho);

            return null;
        }

        return $caminho;
    }
}

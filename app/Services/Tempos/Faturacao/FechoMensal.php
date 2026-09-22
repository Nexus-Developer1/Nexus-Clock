<?php

namespace App\Services\Tempos\Faturacao;

use App\Jobs\AtualizarConsumoContratos;
use App\Models\ExportacaoFaturacao;
use App\Models\MesTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Fecho mensal (regra 12). Fechar: só admin; marca `fechado_em` em todos os registos do mês, de todos
 * os técnicos, e refresca a view de consumo. A partir daí ninguém mexe nesses dias sem permissão
 * explícita de reabrir, e não se submetem semanas que os incluam.
 *
 * Reabrir: só com permissão explícita (tempos-reabrir), com motivo, e fica na auditoria. Registos já
 * faturados continuam intocáveis (regra 4).
 */
class FechoMensal
{
    public function __construct(private readonly MesesFechados $mesesFechados) {}

    public function fechar(User $autor, CarbonImmutable $mes): MesTempo
    {
        Gate::forUser($autor)->authorize('tempos-fechar-mes');

        $inicio = MesTempo::inicioDoMes($mes);
        $fim = $inicio->endOfMonth()->startOfDay();

        if ($this->mesesFechados->estaFechado($inicio)) {
            throw ValidationException::withMessages(['mes' => 'Este mês já está fechado.']);
        }

        if ($fim->gte(CarbonImmutable::now(config('tempos.fuso'))->startOfDay())) {
            throw ValidationException::withMessages(['mes' => 'Só se fecha um mês depois de ele acabar.']);
        }

        // Sem buracos: os meses anteriores com horas por fechar têm de ser fechados primeiro.
        $anteriorPorFechar = RegistoTempo::query()
            ->where('inicio', '<', RegistoTempo::inicioDoDia($inicio))
            ->whereNull('fechado_em')
            ->orderBy('inicio')
            ->first(['inicio']);
        if ($anteriorPorFechar) {
            throw ValidationException::withMessages(['mes' => 'Feche primeiro '.MesTempo::rotulo(MesTempo::inicioDoMes($anteriorPorFechar->dia())).', que ainda tem horas por fechar.']);
        }

        if (RegistoTempo::query()->noPeriodo($inicio, $fim)->whereNull('fim')->exists()) {
            throw ValidationException::withMessages(['mes' => 'Há cronómetros a correr com início neste mês. Parem-se antes de fechar.']);
        }

        $registos = 0;
        $estado = DB::transaction(function () use ($autor, $inicio, $fim, &$registos) {
            $agora = now();
            $registos = RegistoTempo::query()->noPeriodo($inicio, $fim)->whereNull('fechado_em')->update(['fechado_em' => $agora]);

            return MesTempo::updateOrCreate(['mes' => $inicio->toDateString()], [
                'estado' => MesTempo::FECHADO, 'fechado_em' => $agora, 'fechado_por' => $autor->id,
            ]);
        });

        $this->mesesFechados->esquecer();
        Auditor::registar('tempo_mes_fechado', $estado, ['mes' => $inicio->format('Y-m'), 'registos' => $registos]);
        AtualizarConsumoContratos::dispatch();

        return $estado;
    }

    public function reabrir(User $autor, CarbonImmutable $mes, string $motivo): MesTempo
    {
        Gate::forUser($autor)->authorize('tempos-reabrir');

        $inicio = MesTempo::inicioDoMes($mes);
        $fim = $inicio->endOfMonth()->startOfDay();
        $estado = MesTempo::where('mes', $inicio->toDateString())->first();

        if (! $estado?->estaFechado()) {
            throw ValidationException::withMessages(['mes' => 'Este mês não está fechado.']);
        }

        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Indique o motivo da reabertura.']);
        }

        // Sem buracos: não se reabre um mês com meses seguintes fechados.
        if (MesTempo::where('estado', MesTempo::FECHADO)->where('mes', '>', $inicio->toDateString())->exists()) {
            throw ValidationException::withMessages(['mes' => 'Reabra primeiro os meses seguintes, que estão fechados.']);
        }

        $registos = 0;
        DB::transaction(function () use ($autor, $inicio, $fim, $estado, &$registos) {
            // Os já faturados ficam como estão (regra 4).
            $registos = RegistoTempo::query()->noPeriodo($inicio, $fim)->whereNull('faturado_em')->update(['fechado_em' => null]);
            $estado->update(['estado' => MesTempo::ABERTO, 'reaberto_em' => now(), 'reaberto_por' => $autor->id]);
        });

        $this->mesesFechados->esquecer();
        Auditor::registar('tempo_mes_reaberto', $estado, [
            'mes' => $inicio->format('Y-m'),
            'motivo' => trim($motivo),
            'registos' => $registos,
            'exportado' => ExportacaoFaturacao::emVigor()->where('mes', $inicio->toDateString())->exists(),
        ]);
        AtualizarConsumoContratos::dispatch();

        return $estado;
    }
}

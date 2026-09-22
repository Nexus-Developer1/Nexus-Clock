<?php

namespace App\Services\Tempos;

use App\Enums\ModoArredondamento;
use App\Enums\PeriodoHorasIncluidas;
use App\Jobs\AtualizarConsumoContratos;
use App\Models\Contrato;
use App\Models\ContratoHorasIncluidas;
use App\Models\Tarifa;
use App\Models\User;
use App\Services\Auditor;
use App\Services\Tempos\Faturacao\MesesFechados;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Criar, alterar e apagar as horas incluídas de um contrato (só admin). Só pode haver UMAS horas
 * incluídas ativas por contrato numa data: validades sobrepostas são recusadas. Depois de gravar
 * refresca a view de consumo (os relatórios mudam logo). Tudo fica na auditoria.
 *
 * Dados: periodo, horas (texto, aceita vírgula), transita, excedente_faturavel, tarifa_excedente_id,
 * arredondamento_min, arredondamento_modo, valido_de, valido_ate.
 */
class GestorHorasIncluidas
{
    public const MINUTOS_ARREDONDAMENTO = [0, 5, 6, 10, 15, 30, 60];

    /** @param array<string, mixed> $dados */
    public function guardar(User $autor, Contrato $contrato, ?ContratoHorasIncluidas $horas, array $dados): ContratoHorasIncluidas
    {
        Gate::forUser($autor)->authorize('tempos-gerir-tarifas');

        if ($horas && (int) $horas->contrato_id !== $contrato->id) {
            abort(404);
        }

        $erros = [];

        $periodo = PeriodoHorasIncluidas::tryFrom((string) ($dados['periodo'] ?? ''));
        if (! $periodo) {
            $erros['periodo'] = 'Escolha o período.';
        }

        $textoHoras = str_replace([' ', ','], ['', '.'], (string) ($dados['horas'] ?? ''));
        if (! preg_match('/^\d{1,6}(\.\d{1,2})?$/', $textoHoras)) {
            $erros['horas'] = 'Indique as horas incluídas (ex.: 10 ou 7,5).';
        }

        $minutos = (int) ($dados['arredondamento_min'] ?? 15);
        if (! in_array($minutos, self::MINUTOS_ARREDONDAMENTO, true)) {
            $erros['arredondamento_min'] = 'Arredondamento inválido.';
        }

        $modo = ModoArredondamento::tryFrom((string) ($dados['arredondamento_modo'] ?? 'cima'));
        if (! $modo) {
            $erros['arredondamento_modo'] = 'Modo de arredondamento inválido.';
        }

        $tarifaExcedente = filled($dados['tarifa_excedente_id'] ?? null) ? (int) $dados['tarifa_excedente_id'] : null;
        if ($tarifaExcedente && ! Tarifa::whereKey($tarifaExcedente)->exists()) {
            $erros['tarifa_excedente_id'] = 'A tarifa escolhida já não existe.';
        }

        $de = $this->data($dados['valido_de'] ?? null);
        $ate = $this->data($dados['valido_ate'] ?? null);
        if (! $de) {
            $erros['valido_de'] = 'Indique a data de início.';
        } elseif ($ate && $ate->lt($de)) {
            $erros['valido_ate'] = 'O fim não pode ser antes do início.';
        }

        // Meses fechados: as condições desses dias não mudam (nem por datas, nem por valores).
        if ($erros === []) {
            $fechados = app(MesesFechados::class);
            $antes = $horas ? $fechados->intersecoes($horas->valido_de, $horas->valido_ate) : [];
            $mudouValores = $horas && ($horas->periodo !== $periodo || (float) $horas->horas_incluidas !== (float) $textoHoras
                || $horas->transita !== (bool) ($dados['transita'] ?? false) || $horas->excedente_faturavel !== (bool) ($dados['excedente_faturavel'] ?? true)
                || $horas->tarifa_excedente_id !== $tarifaExcedente || $horas->arredondamento_min !== $minutos || $horas->arredondamento_modo !== $modo);

            if ($fechados->intersecoes($de, $ate) !== $antes || ($antes !== [] && $mudouValores)) {
                $erros['valido_de'] = 'Esta alteração mexe nas horas incluídas de meses já fechados ('
                    .$fechados->rotulos($horas ? $horas->valido_de->min($de) : $de, null).'). Reabra-os primeiro, ou termine estas e crie outras a partir do mês seguinte.';
            }
        }

        if ($erros === [] && ($outras = $this->sobrepostas($contrato, $de, $ate, $horas?->id))->isNotEmpty()) {
            $outra = $outras->first();
            $erros['valido_de'] = 'Este contrato já tem horas incluídas de '.$outra->valido_de->format('d/m/Y')
                .($outra->valido_ate ? ' a '.$outra->valido_ate->format('d/m/Y') : ' em diante')
                .'. Só pode haver umas ativas em cada data: termine as anteriores ou ajuste as datas.';
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        $antes = $horas ? $this->paraAuditoria($horas) : null;
        $horas ??= new ContratoHorasIncluidas(['contrato_id' => $contrato->id]);
        $horas->fill([
            'periodo' => $periodo,
            'horas_incluidas' => $textoHoras,
            'transita' => (bool) ($dados['transita'] ?? false),
            'excedente_faturavel' => (bool) ($dados['excedente_faturavel'] ?? true),
            'tarifa_excedente_id' => $tarifaExcedente,
            'arredondamento_min' => $minutos,
            'arredondamento_modo' => $modo,
            'valido_de' => $de,
            'valido_ate' => $ate,
        ])->save();

        Auditor::registar($antes ? 'tempo_horas_incluidas_alteradas' : 'tempo_horas_incluidas_criadas', $horas, array_filter([
            'contrato' => $contrato->numero,
            'antes' => $antes,
            'depois' => $this->paraAuditoria($horas),
        ]));

        AtualizarConsumoContratos::dispatch();

        return $horas;
    }

    public function apagar(User $autor, ContratoHorasIncluidas $horas): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-tarifas');

        if (app(MesesFechados::class)->intersecoes($horas->valido_de, $horas->valido_ate) !== []) {
            throw ValidationException::withMessages(['horas' => 'Estas horas incluídas valem em meses já fechados ('
                .app(MesesFechados::class)->rotulos($horas->valido_de, $horas->valido_ate).'). Não podem ser apagadas; termine-as no fim do último mês fechado.']);
        }

        $horas->delete();
        Auditor::registar('tempo_horas_incluidas_apagadas', $horas, ['contrato' => $horas->contrato?->numero] + $this->paraAuditoria($horas));
        AtualizarConsumoContratos::dispatch();
    }

    /**
     * Avisos que não bloqueiam (ex.: validade fora das datas do contrato).
     *
     * @return list<string>
     */
    public function avisos(Contrato $contrato, ContratoHorasIncluidas $horas): array
    {
        $avisos = [];

        if ($contrato->data_inicio && $horas->valido_de->lt($contrato->data_inicio)) {
            $avisos[] = 'Começam antes do início do contrato ('.$contrato->data_inicio->format('d/m/Y').').';
        }
        if ($contrato->data_fim && ($horas->valido_ate === null || $horas->valido_ate->gt($contrato->data_fim))) {
            $avisos[] = 'Vão além do fim do contrato ('.$contrato->data_fim->format('d/m/Y').').';
        }

        return $avisos;
    }

    /** Horas incluídas do contrato cuja validade se cruza com [$de, $ate] ($ate null = sem fim). */
    public function sobrepostas(Contrato $contrato, CarbonImmutable $de, ?CarbonImmutable $ate, ?int $excepto = null): Collection
    {
        return ContratoHorasIncluidas::query()
            ->where('contrato_id', $contrato->id)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->sobrepostasA($de, $ate)
            ->orderBy('valido_de')
            ->get();
    }

    private function data(mixed $valor): ?CarbonImmutable
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function paraAuditoria(ContratoHorasIncluidas $h): array
    {
        return [
            'periodo' => $h->periodo?->value,
            'horas' => (string) $h->horas_incluidas,
            'transita' => $h->transita,
            'excedente_faturavel' => $h->excedente_faturavel,
            'tarifa_excedente_id' => $h->tarifa_excedente_id,
            'arredondamento' => $h->arredondamento_min.' min '.$h->arredondamento_modo?->value,
            'de' => $h->valido_de?->toDateString(),
            'ate' => $h->valido_ate?->toDateString(),
        ];
    }
}

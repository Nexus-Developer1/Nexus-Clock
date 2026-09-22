<?php

namespace App\Services\Tempos;

use App\Enums\AmbitoTarifa;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Tarifa;
use App\Models\User;
use App\Services\Auditor;
use App\Services\Tempos\Faturacao\MesesFechados;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Criar, alterar e apagar tarifas (só admin). Valida o âmbito, os valores e a validade, e recusa
 * duas tarifas do MESMO âmbito com validades que se sobreponham — senão a resolução (regra 11)
 * teria de escolher entre duas. Tudo fica na auditoria (as tarifas pesam na faturação e na margem).
 *
 * Dados: ambito_tipo ('' = global | contrato | cliente | tecnico), ambito_id, preco (texto, €/h),
 * custo (texto, €/h, opcional), valido_de, valido_ate (opcional).
 */
class GestorTarifas
{
    /** @param array<string, mixed> $dados */
    public function guardar(User $autor, ?Tarifa $tarifa, array $dados): Tarifa
    {
        Gate::forUser($autor)->authorize('tempos-gerir-tarifas');

        $erros = [];
        $ambito = AmbitoTarifa::tryFrom((string) ($dados['ambito_tipo'] ?? ''));
        $ambitoId = $ambito && filled($dados['ambito_id'] ?? null) ? (int) $dados['ambito_id'] : null;

        if ($ambito && ! $this->alvoExiste($ambito, $ambitoId)) {
            $erros['ambito_id'] = match ($ambito) {
                AmbitoTarifa::Contrato => 'Escolha o contrato.',
                AmbitoTarifa::Cliente => 'Escolha o cliente.',
                AmbitoTarifa::Tecnico => 'Escolha o técnico.',
            };
        }

        $preco = $this->valor($dados['preco'] ?? null, 'preco', $erros);
        if ($preco === null && ! isset($erros['preco'])) {
            $erros['preco'] = 'Indique o preço por hora.';
        }
        $custo = $this->valor($dados['custo'] ?? null, 'custo', $erros);

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
            $antes = $tarifa ? $fechados->intersecoes($tarifa->valido_de, $tarifa->valido_ate) : [];
            $mudouValores = $tarifa && ($tarifa->preco_hora_cent !== $preco || $tarifa->custo_hora_cent !== $custo
                || $tarifa->ambito_tipo !== $ambito || $tarifa->ambito_id !== $ambitoId);

            if ($fechados->intersecoes($de, $ate) !== $antes || ($antes !== [] && $mudouValores)) {
                $meses = $fechados->rotulos($tarifa ? $tarifa->valido_de->min($de) : $de, $tarifa && $tarifa->valido_ate === null || $ate === null ? null : ($tarifa ? $tarifa->valido_ate->max($ate) : $ate));
                $erros['valido_de'] = 'Esta alteração mexe no preço de meses já fechados ('.$meses.'). Reabra-os primeiro, ou crie uma tarifa nova a partir do mês seguinte.';
            }
        }

        if ($erros === [] && ($outra = $this->sobrepostas($ambito, $ambitoId, $de, $ate, $tarifa?->id)->first())) {
            $erros['valido_de'] = 'Já existe uma tarifa para '.mb_strtolower(self::rotuloAlvo($outra), 'UTF-8').' '.self::rotuloValidade($outra).'. Termine-a antes ou ajuste as datas.';
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        $antes = $tarifa?->only(['ambito_tipo', 'ambito_id', 'preco_hora_cent', 'custo_hora_cent', 'valido_de', 'valido_ate']);
        $tarifa ??= new Tarifa;
        $tarifa->fill([
            'ambito_tipo' => $ambito,
            'ambito_id' => $ambitoId,
            'preco_hora_cent' => $preco,
            'custo_hora_cent' => $custo,
            'valido_de' => $de,
            'valido_ate' => $ate,
        ])->save();

        Auditor::registar($antes ? 'tempo_tarifa_alterada' : 'tempo_tarifa_criada', $tarifa, array_filter([
            'alvo' => self::rotuloAlvo($tarifa),
            'antes' => $antes ? $this->paraAuditoria($antes) : null,
            'depois' => $this->paraAuditoria($tarifa->only(['ambito_tipo', 'ambito_id', 'preco_hora_cent', 'custo_hora_cent', 'valido_de', 'valido_ate'])),
        ]));

        app(ResolvedorTarifa::class)->esquecer();

        return $tarifa;
    }

    public function apagar(User $autor, Tarifa $tarifa): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-tarifas');

        if (app(MesesFechados::class)->intersecoes($tarifa->valido_de, $tarifa->valido_ate) !== []) {
            throw ValidationException::withMessages(['tarifa' => 'Esta tarifa vale em meses já fechados ('
                .app(MesesFechados::class)->rotulos($tarifa->valido_de, $tarifa->valido_ate).'). Não pode ser apagada; termine-a no fim do último mês fechado.']);
        }

        $tarifa->delete();
        Auditor::registar('tempo_tarifa_apagada', $tarifa, ['alvo' => self::rotuloAlvo($tarifa), 'validade' => self::rotuloValidade($tarifa)]);
        app(ResolvedorTarifa::class)->esquecer();
    }

    /** Tarifas do mesmo âmbito cuja validade se cruza com [$de, $ate] ($ate null = sem fim). */
    public function sobrepostas(?AmbitoTarifa $ambito, ?int $ambitoId, CarbonImmutable $de, ?CarbonImmutable $ate, ?int $excepto = null): Collection
    {
        return Tarifa::query()
            ->doAmbito($ambito, $ambitoId)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->where(fn ($q) => $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', $de->toDateString()))
            ->when($ate, fn ($q) => $q->where('valido_de', '<=', $ate->toDateString()))
            ->orderBy('valido_de')
            ->get();
    }

    /** "Global", "Contrato CT-1 (ACME)", "Cliente ACME", "Técnico Ana Martins". */
    public static function rotuloAlvo(Tarifa $tarifa): string
    {
        return match ($tarifa->ambito_tipo) {
            null => 'Global',
            AmbitoTarifa::Contrato => ($c = Contrato::withTrashed()->with('cliente')->find($tarifa->ambito_id))
                ? 'Contrato '.$c->numero.' ('.($c->cliente?->nome ?? '—').')' : 'Contrato removido',
            AmbitoTarifa::Cliente => 'Cliente '.(Cliente::withTrashed()->find($tarifa->ambito_id)?->nome ?? 'removido'),
            AmbitoTarifa::Tecnico => 'Técnico '.(User::find($tarifa->ambito_id)?->nome ?? 'removido'),
        };
    }

    public static function rotuloValidade(Tarifa $t): string
    {
        return 'de '.$t->valido_de->format('d/m/Y').($t->valido_ate ? ' a '.$t->valido_ate->format('d/m/Y') : ' em diante');
    }

    private function alvoExiste(AmbitoTarifa $ambito, ?int $id): bool
    {
        return $id !== null && match ($ambito) {
            AmbitoTarifa::Contrato => Contrato::whereKey($id)->exists(),
            AmbitoTarifa::Cliente => Cliente::whereKey($id)->exists(),
            AmbitoTarifa::Tecnico => User::comAcessoAosTempos()->whereKey($id)->exists(),
        };
    }

    /** @param array<string, string> $erros */
    private function valor(mixed $texto, string $campo, array &$erros): ?int
    {
        try {
            return Dinheiro::paraCentimos($texto === null ? null : (string) $texto);
        } catch (InvalidArgumentException $e) {
            $erros[$campo] = $e->getMessage();

            return null;
        }
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

    /** @param array<string, mixed> $valores */
    private function paraAuditoria(array $valores): array
    {
        return [
            'ambito' => $valores['ambito_tipo'] instanceof AmbitoTarifa ? $valores['ambito_tipo']->value : $valores['ambito_tipo'],
            'ambito_id' => $valores['ambito_id'],
            'preco' => Dinheiro::formatar($valores['preco_hora_cent']),
            'custo' => Dinheiro::formatar($valores['custo_hora_cent']),
            'de' => $valores['valido_de']?->toDateString(),
            'ate' => $valores['valido_ate']?->toDateString(),
        ];
    }
}

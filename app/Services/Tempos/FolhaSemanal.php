<?php

namespace App\Services\Tempos;

use App\Enums\EstadoSemanaTempo;
use App\Enums\OrigemRegistoTempo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Intervencao;
use App\Models\LinhaSemana;
use App\Models\MesTempo;
use App\Models\RegistoTempo;
use App\Models\SemanaTempo;
use App\Models\User;
use App\Services\Auditor;
use App\Services\Tempos\Faturacao\MesesFechados;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A folha de horas semanal de um técnico: monta as linhas (cliente / contrato / intervenção) com
 * as horas de segunda a domingo e trata de tudo o que se faz na grelha — gravar uma célula, mexer
 * na descrição/faturável/etiquetas de uma linha, acrescentar e retirar linhas, copiar a semana
 * anterior, submeter, aprovar, rejeitar e reabrir.
 *
 * As horas vivem em `registos_tempo` (uma célula = os registos daquele dia e daquela linha) e são
 * SEMPRE gravadas pelo GravadorRegistos, que aplica as regras 1–7. `linhas_semana` só guarda o que
 * os registos não guardam (linhas ainda vazias e linhas persistentes retiradas).
 *
 * De onde vem cada linha da semana:
 *  1. `linhas_semana` da semana (acrescentadas à mão ou copiadas);
 *  2. combinações com registos na semana (ex.: vindas do cronómetro ou gravadas pelo admin);
 *  3. PERSISTENTES: combinações com horas na semana anterior, vazias, a menos que retiradas.
 */
class FolhaSemanal
{
    public const DIAS = 7;

    // Acima disto num dia, a folha avisa (não bloqueia).
    public const AVISO_DIA_SEG = 10 * 3600;

    public function __construct(private readonly GravadorRegistos $gravador) {}

    public static function chave(?int $clienteId, ?int $contratoId, ?int $intervencaoId): string
    {
        return ($clienteId ?? '-').'|'.($contratoId ?? '-').'|'.($intervencaoId ?? '-');
    }

    /** @return array{0: int|null, 1: int|null, 2: int|null} */
    public static function partesDaChave(string $chave): array
    {
        return array_map(fn (string $p) => $p === '-' ? null : (int) $p, array_pad(explode('|', $chave), 3, '-'));
    }

    public static function segunda(CarbonInterface|string $dia): CarbonImmutable
    {
        return SemanaTempo::segundaDe($dia);
    }

    /** Segunda-feira da semana corrente, no fuso da empresa. */
    public static function segundaAtual(): CarbonImmutable
    {
        return self::segunda(CarbonImmutable::now(config('tempos.fuso'))->toDateString());
    }

    public function estado(User $tecnico, CarbonInterface $segunda): EstadoSemanaTempo
    {
        return SemanaTempo::estadoDe($tecnico->id, $segunda);
    }

    /** Quem está a ver pode mexer nesta folha? (a UI fica só de leitura quando não). */
    public function podeEditar(User $autor, User $tecnico, CarbonInterface $segunda): bool
    {
        $estado = $this->estado($tecnico, $segunda);

        if (Gate::forUser($autor)->allows('tempos-editar-todos')) {
            return ! $estado->bloqueiaTodos();
        }

        return $autor->id === $tecnico->id && ! $estado->bloqueiaTecnico();
    }

    /**
     * Linhas da folha, ordenadas por cliente, contrato e intervenção.
     *
     * @return list<array{chave: string, cliente_id: int|null, contrato_id: int|null, intervencao_id: int|null,
     *     cliente: string, contrato: string|null, intervencao: string|null, intervencao_concluida: bool,
     *     descricao: string|null, faturavel: bool, etiquetas: list<string>, origem: string,
     *     dias: array<int, array{segundos: int|null, registos: int, a_correr: bool}>, total: int}>
     */
    public function linhas(User $tecnico, CarbonInterface $segunda): array
    {
        $segunda = self::segunda($segunda);
        $registos = $this->registosDaSemana($tecnico, $segunda);
        $guardadas = LinhaSemana::where('tecnico_id', $tecnico->id)->where('semana_inicio', $segunda->toDateString())->get()
            ->keyBy(fn (LinhaSemana $l) => self::chave($l->cliente_id, $l->contrato_id, $l->intervencao_id));

        $linhas = [];
        $acrescentar = function (string $chave, object $origemAtributos, string $origem) use (&$linhas) {
            [$cliente, $contrato, $intervencao] = self::partesDaChave($chave);
            $linhas[$chave] = [
                'chave' => $chave,
                'cliente_id' => $cliente,
                'contrato_id' => $contrato,
                'intervencao_id' => $intervencao,
                'descricao' => $origemAtributos->descricao,
                'faturavel' => (bool) $origemAtributos->faturavel,
                'etiquetas' => $origemAtributos->etiquetas,
                'origem' => $origem,
            ];
        };

        // 1. Guardadas (acrescentadas, copiadas) — as retiradas não entram.
        foreach ($guardadas as $chave => $linha) {
            if (! $linha->removida) {
                $acrescentar($chave, $linha, 'linha');
            }
        }

        // 2. Com registos na semana (atributos do primeiro registo). Aparecem mesmo que retiradas:
        //    horas nunca ficam escondidas.
        foreach ($registos->groupBy(fn (RegistoTempo $r) => self::chave($r->cliente_id, $r->contrato_id, $r->intervencao_id)) as $chave => $doGrupo) {
            if (! isset($linhas[$chave])) {
                $acrescentar($chave, $doGrupo->first(), 'registos');
            }
        }

        // 3. Persistentes: com horas na semana anterior, vazias, a menos que retiradas nesta semana.
        $anterior = $segunda->subWeek();
        $guardadasAnteriores = LinhaSemana::where('tecnico_id', $tecnico->id)->where('semana_inicio', $anterior->toDateString())->get()
            ->keyBy(fn (LinhaSemana $l) => self::chave($l->cliente_id, $l->contrato_id, $l->intervencao_id));

        $comHorasAntes = RegistoTempo::query()->doTecnico($tecnico)->noPeriodo($anterior, $anterior->addDays(6))
            ->where('duracao_seg', '>', 0)->orderBy('inicio')->orderBy('id')->get()
            ->groupBy(fn (RegistoTempo $r) => self::chave($r->cliente_id, $r->contrato_id, $r->intervencao_id));

        foreach ($comHorasAntes as $chave => $doGrupo) {
            if (! isset($linhas[$chave]) && ! ($guardadas->get($chave)?->removida ?? false)) {
                $acrescentar($chave, $guardadasAnteriores->get($chave) ?? $doGrupo->first(), 'persistente');
            }
        }

        return $this->completar(array_values($linhas), $registos, $segunda);
    }

    /**
     * Grava uma célula (texto como o técnico o escreveu: 1:30, 1,5, 90m, 1h30). Vazio ou zero apaga.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function gravarCelula(User $autor, User $tecnico, CarbonInterface $segunda, string $chave, int $dia, ?string $texto): void
    {
        $segunda = self::segunda($segunda);

        if ($dia < 0 || $dia >= self::DIAS) {
            throw ValidationException::withMessages(['celula' => 'Dia inválido.']);
        }

        try {
            $segundos = LeitorDuracao::ler($texto);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['celula' => $e->getMessage()]);
        }

        $linha = $this->linhaOuFalha($tecnico, $segunda, $chave);
        $data = $segunda->addDays($dia);
        $doDia = $this->registosDaCombinacao($tecnico, $linha, $data, $data);

        if ($doDia->count() > 1) {
            throw ValidationException::withMessages(['celula' => 'Este dia tem vários registos nesta linha (ex.: do cronómetro). Altere-os um a um.']);
        }

        $registo = $doDia->first();
        $vazio = $segundos === null || $segundos === 0;

        if ($registo && $vazio) {
            $this->gravador->apagar($autor, $registo);
        } elseif ($registo) {
            $this->gravador->atualizar($autor, $registo, ['dia' => $data->toDateString(), 'duracao_seg' => $segundos]);
        } elseif (! $vazio) {
            $this->gravador->criar($autor, [
                'tecnico_id' => $tecnico->id,
                'cliente_id' => $linha['cliente_id'],
                'contrato_id' => $linha['contrato_id'],
                'intervencao_id' => $linha['intervencao_id'],
                'dia' => $data->toDateString(),
                'duracao_seg' => $segundos,
                'faturavel' => $linha['faturavel'],
                'descricao' => $linha['descricao'],
                'etiquetas' => $linha['etiquetas'],
                'origem' => OrigemRegistoTempo::Timesheet,
            ]);
        }
    }

    /**
     * Descrição, faturável e etiquetas de uma linha: ficam na linha e passam para todos os registos
     * dessa linha na semana.
     *
     * @param  array{descricao?: string|null, faturavel?: bool, etiquetas?: list<string>}  $dados
     */
    public function atualizarLinha(User $autor, User $tecnico, CarbonInterface $segunda, string $chave, array $dados): void
    {
        $segunda = self::segunda($segunda);
        $this->autorizar($autor, $tecnico, $segunda);
        $linha = $this->linhaOuFalha($tecnico, $segunda, $chave);

        $atributos = [
            'descricao' => array_key_exists('descricao', $dados) ? (trim((string) $dados['descricao']) ?: null) : $linha['descricao'],
            'faturavel' => array_key_exists('faturavel', $dados) ? (bool) $dados['faturavel'] : $linha['faturavel'],
            'etiquetas' => array_key_exists('etiquetas', $dados) ? array_values($dados['etiquetas']) : $linha['etiquetas'],
        ];

        DB::transaction(function () use ($autor, $tecnico, $segunda, $linha, $atributos) {
            $this->guardarLinha($tecnico, $segunda, $linha, $atributos + ['removida' => false]);

            foreach ($this->registosDaCombinacao($tecnico, $linha, $segunda, $segunda->addDays(6)) as $registo) {
                $this->gravador->atualizar($autor, $registo, $atributos);
            }
        });
    }

    /**
     * Acrescenta uma linha (vazia) à semana. A combinação tem de ser coerente (regra 6) e ainda não
     * estar na folha.
     */
    public function adicionarLinha(User $autor, User $tecnico, CarbonInterface $segunda, ?int $clienteId, ?int $contratoId, ?int $intervencaoId): void
    {
        $segunda = self::segunda($segunda);
        $this->autorizar($autor, $tecnico, $segunda);

        if (! $clienteId || ! Cliente::whereKey($clienteId)->exists()) {
            throw ValidationException::withMessages(['cliente_id' => 'Indique o cliente.']);
        }

        if ($erros = $this->gravador->errosDeLigacao($clienteId, $contratoId, $intervencaoId)) {
            throw ValidationException::withMessages($erros);
        }

        $chave = self::chave($clienteId, $contratoId, $intervencaoId);
        if (collect($this->linhas($tecnico, $segunda))->contains('chave', $chave)) {
            throw ValidationException::withMessages(['cliente_id' => 'Essa linha já está na folha desta semana.']);
        }

        $this->guardarLinha($tecnico, $segunda, ['cliente_id' => $clienteId, 'contrato_id' => $contratoId, 'intervencao_id' => $intervencaoId], [
            'descricao' => null, 'faturavel' => true, 'etiquetas' => [], 'removida' => false,
        ]);
    }

    /** Retira uma linha SEM horas na semana (e fica retirada: não volta como persistente). */
    public function removerLinha(User $autor, User $tecnico, CarbonInterface $segunda, string $chave): void
    {
        $segunda = self::segunda($segunda);
        $this->autorizar($autor, $tecnico, $segunda);
        $linha = $this->linhaOuFalha($tecnico, $segunda, $chave);

        if ($linha['total'] > 0) {
            throw ValidationException::withMessages(['linha' => 'Esta linha tem horas nesta semana. Apague as horas antes de a retirar.']);
        }

        $this->guardarLinha($tecnico, $segunda, $linha, [
            'descricao' => $linha['descricao'], 'faturavel' => $linha['faturavel'], 'etiquetas' => $linha['etiquetas'], 'removida' => true,
        ]);
    }

    /**
     * Copia a semana anterior: as linhas (com descrição, faturável e etiquetas) e as horas, dia a
     * dia. Não escreve por cima de horas que já existam nesta semana.
     *
     * @return array{linhas: int, registos: int}
     */
    public function copiarSemanaAnterior(User $autor, User $tecnico, CarbonInterface $segunda): array
    {
        $segunda = self::segunda($segunda);
        $this->autorizar($autor, $tecnico, $segunda);

        $anteriores = array_filter($this->linhas($tecnico, $segunda->subWeek()), fn (array $l) => $l['origem'] !== 'persistente' || $l['total'] > 0);
        if ($anteriores === []) {
            throw ValidationException::withMessages(['copiar' => 'A semana anterior não tem linhas para copiar.']);
        }

        $atuais = collect($this->linhas($tecnico, $segunda))->keyBy('chave');
        $contagem = ['linhas' => 0, 'registos' => 0];

        DB::transaction(function () use ($autor, $tecnico, $segunda, $anteriores, $atuais, &$contagem) {
            foreach ($anteriores as $linha) {
                $atual = $atuais->get($linha['chave']);
                $atributos = ['descricao' => $linha['descricao'], 'faturavel' => $linha['faturavel'], 'etiquetas' => $linha['etiquetas']];

                if (! $atual || $atual['origem'] === 'persistente') {
                    $this->guardarLinha($tecnico, $segunda, $linha, $atributos + ['removida' => false]);
                    $contagem['linhas']++;
                }

                foreach ($linha['dias'] as $dia => $celula) {
                    if (! $celula['segundos'] || ($atual['dias'][$dia]['registos'] ?? 0) > 0) {
                        continue;
                    }

                    $this->gravador->criar($autor, [
                        'tecnico_id' => $tecnico->id,
                        'cliente_id' => $linha['cliente_id'],
                        'contrato_id' => $linha['contrato_id'],
                        'intervencao_id' => $linha['intervencao_id'],
                        'dia' => $segunda->addDays($dia)->toDateString(),
                        'duracao_seg' => $celula['segundos'],
                        'origem' => OrigemRegistoTempo::Timesheet,
                    ] + $atributos);
                    $contagem['registos']++;
                }
            }
        });

        return $contagem;
    }

    /** Submete a semana: fica só de leitura para o técnico até ser aprovada, rejeitada ou reaberta. */
    public function submeter(User $autor, User $tecnico, CarbonInterface $segunda): SemanaTempo
    {
        $segunda = self::segunda($segunda);

        if ($autor->id !== $tecnico->id && ! Gate::forUser($autor)->allows('tempos-editar-todos')) {
            throw new AuthorizationException('Só pode submeter as suas semanas.');
        }

        $estado = $this->estado($tecnico, $segunda);
        if ($estado->entregue()) {
            throw ValidationException::withMessages(['semana' => $estado === EstadoSemanaTempo::Aprovada ? 'Esta semana já foi aprovada.' : 'Esta semana já foi submetida.']);
        }

        if ($segunda->gt(self::segundaAtual())) {
            throw ValidationException::withMessages(['semana' => 'Não pode submeter uma semana que ainda não começou.']);
        }

        // Regra 12: não se submetem semanas que cruzem um mês fechado.
        foreach ([$segunda, $segunda->addDays(6)] as $dia) {
            if (app(MesesFechados::class)->estaFechado($dia)) {
                throw ValidationException::withMessages(['semana' => 'A semana inclui dias de '.MesTempo::rotulo(MesTempo::inicioDoMes($dia)).', que já está fechado.']);
            }
        }

        // Com um cronómetro a correr nesta semana, as horas ainda não estão todas.
        if (RegistoTempo::query()->doTecnico($tecnico)->noPeriodo($segunda, $segunda->addDays(6))->whereNull('fim')->exists()) {
            throw ValidationException::withMessages(['semana' => 'Pare o cronómetro antes de submeter a semana.']);
        }

        return DB::transaction(function () use ($tecnico, $segunda) {
            $agora = now();

            // Voltar a submeter limpa a rejeição e a aprovação anteriores (o histórico fica na auditoria).
            $semana = SemanaTempo::updateOrCreate(
                ['tecnico_id' => $tecnico->id, 'semana_inicio' => $segunda->toDateString()],
                ['estado' => EstadoSemanaTempo::Submetida, 'submetida_em' => $agora,
                    'aprovada_em' => null, 'aprovada_por' => null, 'rejeitada_em' => null, 'rejeitada_por' => null, 'motivo_rejeicao' => null],
            );

            RegistoTempo::query()->doTecnico($tecnico)->noPeriodo($segunda, $segunda->addDays(6))
                ->update(['submetido_em' => $agora]);

            return $semana;
        });
    }

    /** Aprova uma semana submetida (só quem gere os tempos). Aprovada, ninguém a altera sem a reabrir. */
    public function aprovar(User $autor, User $tecnico, CarbonInterface $segunda): SemanaTempo
    {
        $segunda = self::segunda($segunda);
        Gate::forUser($autor)->authorize('tempos-editar-todos');

        $semana = $this->semanaNoEstado($tecnico, $segunda, [EstadoSemanaTempo::Submetida], 'Só se aprova uma semana submetida.');
        $semana->update(['estado' => EstadoSemanaTempo::Aprovada, 'aprovada_em' => now(), 'aprovada_por' => $autor->id]);

        Auditor::registar('tempo_semana_aprovada', $semana, [
            'tecnico' => $tecnico->nome,
            'semana_inicio' => $segunda->toDateString(),
        ]);

        return $semana;
    }

    /**
     * Rejeita uma semana submetida, com motivo: volta a ser editável pelo técnico e fica em falta até ser
     * submetida de novo. Fica na auditoria.
     */
    public function rejeitar(User $autor, User $tecnico, CarbonInterface $segunda, string $motivo): SemanaTempo
    {
        $segunda = self::segunda($segunda);
        Gate::forUser($autor)->authorize('tempos-editar-todos');

        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Indique o motivo da rejeição: é o que o técnico vai ler.']);
        }

        $semana = $this->semanaNoEstado($tecnico, $segunda, [EstadoSemanaTempo::Submetida], 'Só se rejeita uma semana submetida.');

        DB::transaction(function () use ($autor, $tecnico, $segunda, $semana, $motivo) {
            $semana->update(['estado' => EstadoSemanaTempo::Rejeitada, 'rejeitada_em' => now(), 'rejeitada_por' => $autor->id, 'motivo_rejeicao' => $motivo]);

            RegistoTempo::query()->doTecnico($tecnico)->noPeriodo($segunda, $segunda->addDays(6))
                ->update(['submetido_em' => null]);
        });

        Auditor::registar('tempo_semana_rejeitada', $semana, [
            'tecnico' => $tecnico->nome,
            'semana_inicio' => $segunda->toDateString(),
            'motivo' => $motivo,
        ]);

        return $semana;
    }

    /** Reabre uma semana submetida ou aprovada (só admin), para a corrigir. Fica na auditoria. */
    public function reabrir(User $autor, User $tecnico, CarbonInterface $segunda): SemanaTempo
    {
        $segunda = self::segunda($segunda);
        Gate::forUser($autor)->authorize('tempos-editar-todos');

        $semana = $this->semanaNoEstado($tecnico, $segunda, [EstadoSemanaTempo::Submetida, EstadoSemanaTempo::Aprovada], 'Só se reabre uma semana submetida ou aprovada.');
        $estavaAprovada = $semana->estado === EstadoSemanaTempo::Aprovada;

        DB::transaction(function () use ($autor, $tecnico, $segunda, $semana) {
            $semana->update(['estado' => EstadoSemanaTempo::Reaberta, 'reaberta_por' => $autor->id, 'aprovada_em' => null, 'aprovada_por' => null]);

            RegistoTempo::query()->doTecnico($tecnico)->noPeriodo($segunda, $segunda->addDays(6))
                ->update(['submetido_em' => null]);
        });

        Auditor::registar('tempo_semana_reaberta', $semana, [
            'tecnico' => $tecnico->nome,
            'semana_inicio' => $segunda->toDateString(),
            'estava_aprovada' => $estavaAprovada,
        ]);

        return $semana;
    }

    // --- internos ---

    /** @param list<EstadoSemanaTempo> $estados */
    private function semanaNoEstado(User $tecnico, CarbonImmutable $segunda, array $estados, string $erro): SemanaTempo
    {
        $semana = SemanaTempo::where('tecnico_id', $tecnico->id)->where('semana_inicio', $segunda->toDateString())->first();

        if (! $semana || ! in_array($semana->estado, $estados, true)) {
            throw ValidationException::withMessages(['semana' => $erro]);
        }

        return $semana;
    }

    /** @return Collection<int, RegistoTempo> */
    private function registosDaSemana(User $tecnico, CarbonImmutable $segunda): Collection
    {
        return RegistoTempo::query()->doTecnico($tecnico)->noPeriodo($segunda, $segunda->addDays(6))
            ->orderBy('inicio')->orderBy('id')->get();
    }

    /**
     * @param  array{cliente_id: int|null, contrato_id: int|null, intervencao_id: int|null}  $linha
     * @return Collection<int, RegistoTempo>
     */
    private function registosDaCombinacao(User $tecnico, array $linha, CarbonImmutable $de, CarbonImmutable $ate): Collection
    {
        $query = RegistoTempo::query()->doTecnico($tecnico)->noPeriodo($de, $ate)->terminados();
        foreach (['cliente_id', 'contrato_id', 'intervencao_id'] as $coluna) {
            $linha[$coluna] === null ? $query->whereNull($coluna) : $query->where($coluna, $linha[$coluna]);
        }

        return $query->orderBy('id')->get();
    }

    private function linhaOuFalha(User $tecnico, CarbonImmutable $segunda, string $chave): array
    {
        return collect($this->linhas($tecnico, $segunda))->firstWhere('chave', $chave)
            ?? throw ValidationException::withMessages(['linha' => 'Esta linha já não existe na folha. Recarregue a página.']);
    }

    private function autorizar(User $autor, User $tecnico, CarbonImmutable $segunda): void
    {
        if (! $this->podeEditar($autor, $tecnico, $segunda)) {
            throw new AuthorizationException('Não pode alterar esta folha de horas.');
        }
    }

    /**
     * @param  array{cliente_id: int|null, contrato_id: int|null, intervencao_id: int|null}  $combinacao
     * @param  array<string, mixed>  $atributos
     */
    private function guardarLinha(User $tecnico, CarbonImmutable $segunda, array $combinacao, array $atributos): void
    {
        $linha = LinhaSemana::query()
            ->where('tecnico_id', $tecnico->id)
            ->where('semana_inicio', $segunda->toDateString())
            ->daCombinacao($combinacao['cliente_id'], $combinacao['contrato_id'], $combinacao['intervencao_id'])
            ->first() ?? new LinhaSemana([
                'tecnico_id' => $tecnico->id,
                'semana_inicio' => $segunda->toDateString(),
                'cliente_id' => $combinacao['cliente_id'],
                'contrato_id' => $combinacao['contrato_id'],
                'intervencao_id' => $combinacao['intervencao_id'],
            ]);

        $linha->fill($atributos)->save();
    }

    /**
     * Junta nomes, células por dia e totais, e ordena.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  Collection<int, RegistoTempo>  $registos
     */
    private function completar(array $linhas, Collection $registos, CarbonImmutable $segunda): array
    {
        $ids = fn (string $coluna) => collect($linhas)->pluck($coluna)->filter()->unique()->values();
        $clientes = Cliente::withTrashed()->whereIn('id', $ids('cliente_id'))->pluck('nome', 'id');
        $contratos = Contrato::withTrashed()->whereIn('id', $ids('contrato_id'))->pluck('numero', 'id');
        $intervencoes = Intervencao::withTrashed()->with('equipamento')->whereIn('id', $ids('intervencao_id'))->get()->keyBy('id');

        $porCelula = [];
        foreach ($registos as $registo) {
            $dia = (int) $segunda->diffInDays(CarbonImmutable::parse($registo->dia()->toDateString()));
            $chave = self::chave($registo->cliente_id, $registo->contrato_id, $registo->intervencao_id);
            if ($registo->duracao_seg === null) {
                $porCelula[$chave][$dia]['a_correr'] = true; // cronómetro a correr: não conta até parar

                continue;
            }
            $porCelula[$chave][$dia]['segundos'] = ($porCelula[$chave][$dia]['segundos'] ?? 0) + $registo->duracao_seg;
            $porCelula[$chave][$dia]['registos'] = ($porCelula[$chave][$dia]['registos'] ?? 0) + 1;
        }

        foreach ($linhas as &$linha) {
            $intervencao = $linha['intervencao_id'] ? $intervencoes->get($linha['intervencao_id']) : null;
            $linha['cliente'] = $clientes[$linha['cliente_id']] ?? '—';
            $linha['contrato'] = $linha['contrato_id'] ? ($contratos[$linha['contrato_id']] ?? '—') : null;
            $linha['intervencao'] = $intervencao?->rotulo();
            $linha['intervencao_concluida'] = (bool) $intervencao?->estaConcluida();
            $linha['dias'] = [];
            for ($d = 0; $d < self::DIAS; $d++) {
                $linha['dias'][$d] = [
                    'segundos' => $porCelula[$linha['chave']][$d]['segundos'] ?? null,
                    'registos' => $porCelula[$linha['chave']][$d]['registos'] ?? 0,
                    'a_correr' => $porCelula[$linha['chave']][$d]['a_correr'] ?? false,
                ];
            }
            $linha['total'] = (int) collect($linha['dias'])->sum('segundos');
        }
        unset($linha);

        usort($linhas, fn (array $a, array $b) => [mb_strtolower($a['cliente']), $a['contrato'] ?? '', $a['intervencao_id'] ?? 0]
            <=> [mb_strtolower($b['cliente']), $b['contrato'] ?? '', $b['intervencao_id'] ?? 0]);

        return $linhas;
    }
}

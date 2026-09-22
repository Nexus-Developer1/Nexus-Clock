<?php

namespace App\Services\Tempos;

use App\Enums\PapelEquipa;
use App\Models\GrupoEquipa;
use App\Models\LembreteEquipa;
use App\Models\MembroEquipa;
use App\Models\TaxaMembro;
use App\Models\User;
use App\Services\Auditor;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Equipa: membros (plenos e limitados), papéis, taxas com histórico, grupos e lembretes. Tudo o que
 * altera exige `tempos-gerir-equipa` e fica na auditoria. Os membros plenos são as pessoas com acesso
 * aos Tempos no portal — a conta e o acesso gerem-se lá; aqui só o que é da equipa.
 */
class GestorEquipa
{
    /**
     * Garante uma linha em membros_equipa para cada pessoa com acesso aos Tempos. Papel inicial: quem
     * é administrador no portal fica Administrador; os outros, Membro.
     */
    public function sincronizar(): void
    {
        $semLinha = User::comAcessoAosTempos()
            ->whereNotIn('id', MembroEquipa::withTrashed()->whereNotNull('utilizador_id')->select('utilizador_id'))
            ->get();

        foreach ($semLinha as $u) {
            MembroEquipa::create([
                'utilizador_id' => $u->id,
                'papel' => $u->ehAdminTempos() ? PapelEquipa::Administrador : PapelEquipa::Membro,
            ]);
        }
    }

    // --- Membros ---

    public function mudarPapel(User $autor, MembroEquipa $membro, string $papel): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $novo = PapelEquipa::tryFrom($papel) ?? throw ValidationException::withMessages(['papel' => 'Papel inválido.']);
        if ($novo === PapelEquipa::Proprietario && $membro->limitado) {
            throw ValidationException::withMessages(['papel' => 'Um membro limitado não pode ser proprietário.']);
        }

        $antes = $membro->papel;

        DB::transaction(function () use ($membro, $novo, $autor) {
            // Só há um proprietário: passar a propriedade faz do anterior administrador.
            if ($novo === PapelEquipa::Proprietario) {
                MembroEquipa::where('papel', PapelEquipa::Proprietario)->whereKeyNot($membro->id)
                    ->update(['papel' => PapelEquipa::Administrador, 'alterado_por' => $autor->id]);
            }
            $membro->forceFill(['papel' => $novo, 'alterado_por' => $autor->id])->save();

            // Quem deixa de ser gestor de equipa deixa de estar atribuído como gestor.
            if ($novo !== PapelEquipa::GestorEquipa) {
                MembroEquipa::where('gestor_id', $membro->id)->update(['gestor_id' => null]);
            }
        });

        Auditor::registar('tempo_membro_papel', $membro, ['membro' => $membro->nomeVisivel(), 'antes' => $antes->value, 'depois' => $novo->value]);
    }

    /**
     * Nova taxa a partir de uma data (hoje por omissão). Texto vazio = sem taxa a partir dessa data. As
     * horas antes da data mantêm a taxa que tinham.
     */
    public function mudarTaxa(User $autor, MembroEquipa $membro, string $tipo, ?string $valor, ?string $apartir = null): TaxaMembro
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        if (! isset(TaxaMembro::TIPOS[$tipo])) {
            throw ValidationException::withMessages(['tipo' => 'Tipo de taxa inválido.']);
        }

        try {
            $centimos = Dinheiro::paraCentimos($valor);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['valor' => $e->getMessage()]);
        }

        try {
            $dia = blank($apartir) ? CarbonImmutable::now(config('tempos.fuso'))->startOfDay() : CarbonImmutable::parse((string) $apartir)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages(['apartir' => 'Data inválida.']);
        }

        $taxa = TaxaMembro::updateOrCreate(
            ['membro_id' => $membro->id, 'tipo' => $tipo, 'valido_de' => $dia->toDateString()],
            ['valor_cent' => $centimos, 'criado_por' => $autor->id],
        );

        Auditor::registar('tempo_membro_taxa', $membro, [
            'membro' => $membro->nomeVisivel(), 'tipo' => $tipo, 'valor_cent' => $centimos, 'valido_de' => $dia->toDateString(),
        ]);

        return $taxa;
    }

    /**
     * Um dos campos de trabalho do membro: início da semana (1–7), dias de trabalho (lista de 1–7, pelo
     * menos um), capacidade diária (duração escrita à mão, vazio = sem) ou gestor de equipa atribuído
     * (um membro com o papel Gestor de equipa, que não seja o próprio; vazio = nenhum).
     */
    public function atualizarCampo(User $autor, MembroEquipa $membro, string $campo, mixed $valor): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $antes = $membro->getAttribute($campo === 'capacidade' ? 'capacidade_diaria_seg' : $campo);

        switch ($campo) {
            case 'inicio_semana':
                if (! isset(MembroEquipa::DIAS[(int) $valor])) {
                    throw ValidationException::withMessages(['inicio_semana' => 'Escolha o dia em que começa a semana.']);
                }
                $membro->inicio_semana = (int) $valor;
                break;

            case 'dias_trabalho':
                $dias = array_values(array_intersect(array_map('intval', (array) $valor), array_keys(MembroEquipa::DIAS)));
                if ($dias === []) {
                    throw ValidationException::withMessages(['dias_trabalho' => 'Tem de haver pelo menos um dia de trabalho.']);
                }
                $membro->dias_trabalho = $dias;
                break;

            case 'capacidade':
                try {
                    $membro->capacidade_diaria_seg = LeitorDuracao::ler(is_string($valor) ? $valor : null) ?: null;
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['capacidade' => $e->getMessage()]);
                }
                break;

            case 'gestor_id':
                $gestorId = blank($valor) ? null : (int) $valor;
                if ($gestorId !== null) {
                    $gestor = MembroEquipa::find($gestorId);
                    if (! $gestor || $gestor->papel !== PapelEquipa::GestorEquipa) {
                        throw ValidationException::withMessages(['gestor_id' => 'O gestor atribuído tem de ter o papel Gestor de equipa.']);
                    }
                    if ($gestor->id === $membro->id) {
                        throw ValidationException::withMessages(['gestor_id' => 'Uma pessoa não pode ser a própria gestora de equipa.']);
                    }
                }
                $membro->gestor_id = $gestorId;
                break;

            default:
                throw ValidationException::withMessages(['campo' => 'Campo desconhecido.']);
        }

        $membro->alterado_por = $autor->id;
        $membro->save();

        Auditor::registar('tempo_membro_campo', $membro, [
            'membro' => $membro->nomeVisivel(), 'campo' => $campo,
            'antes' => $antes, 'depois' => $membro->getAttribute($campo === 'capacidade' ? 'capacidade_diaria_seg' : $campo),
        ]);
    }

    /** Liga ou desliga um dia de trabalho. */
    public function alternarDiaTrabalho(User $autor, MembroEquipa $membro, int $dia): void
    {
        $dias = $membro->dias_trabalho;
        $dias = in_array($dia, $dias, true) ? array_values(array_diff($dias, [$dia])) : [...$dias, $dia];
        sort($dias);

        $this->atualizarCampo($autor, $membro, 'dias_trabalho', $dias);
    }

    public function criarLimitado(User $autor, string $nome, ?string $email = null): MembroEquipa
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $membro = new MembroEquipa(['limitado' => true, 'papel' => PapelEquipa::Membro]);
        $this->preencherLimitado($membro, $nome, $email);
        $membro->alterado_por = $autor->id;
        $membro->save();

        Auditor::registar('tempo_membro_limitado_criado', $membro, ['nome' => $membro->nome]);

        return $membro;
    }

    public function atualizarLimitado(User $autor, MembroEquipa $membro, string $nome, ?string $email): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');
        $this->soLimitado($membro);

        $this->preencherLimitado($membro, $nome, $email);
        $membro->alterado_por = $autor->id;
        $membro->save();

        Auditor::registar('tempo_membro_limitado_alterado', $membro, ['nome' => $membro->nome]);
    }

    public function apagarLimitado(User $autor, MembroEquipa $membro): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');
        $this->soLimitado($membro);

        $membro->grupos()->detach();
        $membro->delete();

        Auditor::registar('tempo_membro_limitado_apagado', $membro, ['nome' => $membro->nome]);
    }

    // --- Grupos ---

    public function criarGrupo(User $autor, string $nome): GrupoEquipa
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $grupo = new GrupoEquipa;
        $grupo->nome = $this->nomeDeGrupo($nome);
        $grupo->alterado_por = $autor->id;
        $grupo->save();

        Auditor::registar('tempo_grupo_criado', $grupo, ['nome' => $grupo->nome]);

        return $grupo;
    }

    public function renomearGrupo(User $autor, GrupoEquipa $grupo, string $nome): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $grupo->nome = $this->nomeDeGrupo($nome, $grupo->id);
        $grupo->alterado_por = $autor->id;
        $grupo->save();

        Auditor::registar('tempo_grupo_alterado', $grupo, ['nome' => $grupo->nome]);
    }

    /** @param list<int> $membroIds */
    public function definirMembrosDoGrupo(User $autor, GrupoEquipa $grupo, array $membroIds): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $ids = MembroEquipa::whereKey(array_map('intval', $membroIds))->pluck('id')->all();
        $grupo->membros()->sync($ids);

        Auditor::registar('tempo_grupo_membros', $grupo, ['nome' => $grupo->nome, 'membros' => $ids]);
    }

    /** Põe ou tira um membro de um grupo (o "+ Grupo" da tabela de membros). */
    public function alternarGrupo(User $autor, MembroEquipa $membro, GrupoEquipa $grupo): bool
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $resultado = $grupo->membros()->toggle([$membro->id]);
        $entrou = $resultado['attached'] !== [];

        Auditor::registar('tempo_grupo_membros', $grupo, ['nome' => $grupo->nome, ($entrou ? 'entrou' : 'saiu') => $membro->id]);

        return $entrou;
    }

    public function apagarGrupo(User $autor, GrupoEquipa $grupo): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        DB::transaction(function () use ($grupo) {
            // Os lembretes que usavam o grupo deixam de o usar.
            LembreteEquipa::whereRaw('? = any(grupos)', [$grupo->id])->get()
                ->each(fn (LembreteEquipa $l) => $l->forceFill(['grupos' => array_values(array_diff($l->grupos, [$grupo->id]))])->save());
            $grupo->delete();
        });

        Auditor::registar('tempo_grupo_apagado', $grupo, ['nome' => $grupo->nome]);
    }

    // --- Lembretes ---

    /**
     * @param  array{destinatarios?: string, grupos?: list<int>, periodo?: string, horas_minimas?: string|float, dias?: list<int>, hora?: int|string, ativo?: bool}  $dados
     */
    public function guardarLembrete(User $autor, ?LembreteEquipa $lembrete, array $dados): LembreteEquipa
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $erros = [];
        $destinatarios = $dados['destinatarios'] ?? 'todos';
        $grupos = GrupoEquipa::whereKey(array_map('intval', $dados['grupos'] ?? []))->pluck('id')->all();
        $dias = array_values(array_intersect(array_map('intval', $dados['dias'] ?? []), array_keys(LembreteEquipa::DIAS)));
        $horas = (float) str_replace(',', '.', (string) ($dados['horas_minimas'] ?? ''));
        $hora = (int) ($dados['hora'] ?? -1);

        if (! in_array($destinatarios, ['todos', 'grupos'], true)) {
            $erros['destinatarios'] = 'Escolha a quem vai o lembrete.';
        } elseif ($destinatarios === 'grupos' && $grupos === []) {
            $erros['grupos'] = 'Escolha pelo menos um grupo.';
        }
        if (! in_array($dados['periodo'] ?? 'dia', ['dia', 'semana'], true)) {
            $erros['periodo'] = 'Período inválido.';
        }
        if ($horas <= 0 || $horas > (($dados['periodo'] ?? 'dia') === 'semana' ? 168 : 24)) {
            $erros['horas_minimas'] = 'Indique as horas mínimas (ex.: 8 por dia ou 40 por semana).';
        }
        if ($dias === []) {
            $erros['dias'] = 'Escolha pelo menos um dia.';
        }
        if ($hora < 0 || $hora > 23) {
            $erros['hora'] = 'Escolha a hora.';
        }
        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        $lembrete ??= new LembreteEquipa;
        $lembrete->fill([
            'destinatarios' => $destinatarios,
            'grupos' => $destinatarios === 'grupos' ? $grupos : [],
            'periodo' => $dados['periodo'] ?? 'dia',
            'horas_minimas' => $horas,
            'dias' => $dias,
            'hora' => $hora,
            'ativo' => (bool) ($dados['ativo'] ?? true),
        ]);
        $lembrete->alterado_por = $autor->id;
        $lembrete->save();

        Auditor::registar('tempo_lembrete_guardado', $lembrete, $lembrete->only(['destinatarios', 'grupos', 'periodo', 'horas_minimas', 'dias', 'hora', 'ativo']));

        return $lembrete;
    }

    public function apagarLembrete(User $autor, LembreteEquipa $lembrete): void
    {
        Gate::forUser($autor)->authorize('tempos-gerir-equipa');

        $lembrete->delete();
        Auditor::registar('tempo_lembrete_apagado', $lembrete, []);
    }

    // --- internos ---

    private function preencherLimitado(MembroEquipa $membro, string $nome, ?string $email): void
    {
        $erros = [];
        $nome = trim(preg_replace('/\s+/u', ' ', $nome));
        $email = trim((string) $email);

        if ($nome === '') {
            $erros['nome'] = 'Indique o nome.';
        } elseif (mb_strlen($nome) > 255) {
            $erros['nome'] = 'O nome não pode passar de 255 caracteres.';
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros['email'] = 'Email inválido.';
        }
        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        $membro->nome = $nome;
        $membro->email = $email ?: null;
    }

    private function soLimitado(MembroEquipa $membro): void
    {
        if (! $membro->limitado) {
            throw ValidationException::withMessages(['membro' => 'Os membros plenos gerem-se no portal.']);
        }
    }

    private function nomeDeGrupo(string $nome, ?int $ignorar = null): string
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome));

        if ($nome === '') {
            throw ValidationException::withMessages(['nome' => 'Indique o nome do grupo.']);
        }
        if (GrupoEquipa::whereRaw('lower(nome) = lower(?)', [$nome])->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar))->exists()) {
            throw ValidationException::withMessages(['nome' => 'Já existe um grupo chamado «'.$nome.'».']);
        }

        return $nome;
    }
}

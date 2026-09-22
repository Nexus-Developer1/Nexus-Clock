<?php

namespace App\Services\Tempos;

use App\Models\RelatorioPartilhado;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Relatórios partilhados: criar (qualquer pessoa com acesso, a partir de um relatório que está a ver),
 * alterar e apagar (quem o criou, ou quem gere a equipa). Nome com 2 a 250 caracteres; envio por email
 * com 1 a 10 destinatários válidos, frequência e hora. Tudo fica na auditoria.
 */
class GestorPartilhados
{
    public const MAXIMO_DESTINATARIOS = 10;

    /**
     * @param  array{nome?: string, publico?: bool, sempre_atual?: bool, bloquear_datas?: bool, email_ativo?: bool, email_frequencia?: string, email_hora?: int|string, email_destinatarios?: string|list<string>}  $dados
     * @param  array<string, mixed>  $parametros  estado do relatório (período, filtros, agrupamentos…)
     */
    public function criar(User $autor, string $tipo, array $dados, array $parametros): RelatorioPartilhado
    {
        if (! isset(RelatorioPartilhado::TIPOS[$tipo])) {
            throw ValidationException::withMessages(['tipo' => 'Este relatório não pode ser partilhado.']);
        }

        $relatorio = new RelatorioPartilhado;
        $relatorio->tipo = $tipo;
        $relatorio->token = Str::random(40);
        $relatorio->parametros = $parametros;
        $relatorio->criado_por = $autor->id;
        $this->preencher($relatorio, $dados + ['nome' => '']);
        $relatorio->save();

        Auditor::registar('tempo_relatorio_partilhado', $relatorio, ['nome' => $relatorio->nome, 'publico' => $relatorio->publico]);

        return $relatorio;
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(User $autor, RelatorioPartilhado $relatorio, array $dados, ?array $parametros = null): RelatorioPartilhado
    {
        $this->autorizar($autor, $relatorio);

        $this->preencher($relatorio, $dados);
        if ($parametros !== null) {
            $relatorio->parametros = $parametros;
        }
        $campos = array_keys($relatorio->getDirty());
        $relatorio->save();

        if ($campos !== []) {
            Auditor::registar('tempo_relatorio_partilhado_alterado', $relatorio, ['nome' => $relatorio->nome, 'campos' => $campos]);
        }

        return $relatorio;
    }

    public function apagar(User $autor, RelatorioPartilhado $relatorio): void
    {
        $this->autorizar($autor, $relatorio);

        $relatorio->delete();
        Auditor::registar('tempo_relatorio_partilhado_apagado', $relatorio, ['nome' => $relatorio->nome]);
    }

    /** Um novo link: o antigo deixa de funcionar. */
    public function novoLink(User $autor, RelatorioPartilhado $relatorio): RelatorioPartilhado
    {
        $this->autorizar($autor, $relatorio);

        $relatorio->forceFill(['token' => Str::random(40)])->save();
        Auditor::registar('tempo_relatorio_partilhado_novo_link', $relatorio, ['nome' => $relatorio->nome]);

        return $relatorio;
    }

    public function podeGerir(User $autor, RelatorioPartilhado $relatorio): bool
    {
        return (int) $relatorio->criado_por === $autor->id || $autor->can('tempos-gerir-equipa');
    }

    private function autorizar(User $autor, RelatorioPartilhado $relatorio): void
    {
        if (! $this->podeGerir($autor, $relatorio)) {
            throw new AuthorizationException('Só quem criou o relatório (ou quem gere a equipa) o pode alterar.');
        }
    }

    /** @param array<string, mixed> $dados */
    private function preencher(RelatorioPartilhado $r, array $dados): void
    {
        $erros = [];

        if (array_key_exists('nome', $dados)) {
            $nome = trim(preg_replace('/\s+/u', ' ', (string) $dados['nome']));
            if (mb_strlen($nome) < 2 || mb_strlen($nome) > 250) {
                $erros['nome'] = 'O nome tem de ter entre 2 e 250 caracteres.';
            }
            $r->nome = $nome;
        }

        foreach (['publico', 'sempre_atual', 'bloquear_datas', 'email_ativo'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $r->{$campo} = (bool) $dados[$campo];
            }
        }

        // Um período de datas à escolha não tem "período corrente".
        if (($r->parametros['tipo'] ?? '') === 'datas') {
            $r->sempre_atual = false;
        }

        if (array_key_exists('email_frequencia', $dados)) {
            if (! isset(RelatorioPartilhado::FREQUENCIAS[$dados['email_frequencia']])) {
                $erros['email_frequencia'] = 'Escolha a frequência.';
            }
            $r->email_frequencia = (string) $dados['email_frequencia'];
        }

        if (array_key_exists('email_hora', $dados)) {
            $hora = filter_var($dados['email_hora'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
            if ($hora === false) {
                $erros['email_hora'] = 'Escolha a hora.';
            }
            $r->email_hora = $hora === false ? 8 : $hora;
        }

        if (array_key_exists('email_destinatarios', $dados)) {
            $lista = is_array($dados['email_destinatarios']) ? $dados['email_destinatarios'] : preg_split('/[\s,;]+/', (string) $dados['email_destinatarios']);
            $lista = array_values(array_unique(array_map('mb_strtolower', array_filter(array_map('trim', $lista)))));
            if ($invalidos = array_filter($lista, fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
                $erros['email_destinatarios'] = 'Email inválido: '.implode(', ', $invalidos).'.';
            } elseif (count($lista) > self::MAXIMO_DESTINATARIOS) {
                $erros['email_destinatarios'] = 'No máximo '.self::MAXIMO_DESTINATARIOS.' destinatários.';
            }
            $r->email_destinatarios = $lista;
        }

        if ($r->email_ativo && $r->email_destinatarios === [] && ! isset($erros['email_destinatarios'])) {
            $erros['email_destinatarios'] = 'Indique pelo menos um email.';
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }
}

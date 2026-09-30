<?php

namespace App\Services\Tempos;

use App\Models\DefinicaoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Campos obrigatórios nos registos (como os «Required fields» do Clockify, notas §58). Quem gere a equipa
 * escolhe-os em Equipa › Regras; valem para toda a gente, admins incluídos. O GravadorRegistos exige-os a
 * qualquer registo terminado — o cronómetro começa sem eles, mas só para com eles.
 */
class CamposObrigatorios
{
    public const CHAVE = 'campos_obrigatorios';

    public const CAMPOS = ['projeto' => 'Projeto', 'descricao' => 'Descrição', 'etiquetas' => 'Etiquetas'];

    /** @return list<string> */
    public function ativos(): array
    {
        return $this->limpar((array) DefinicaoTempo::valor(self::CHAVE, []));
    }

    /** @param list<string> $campos */
    public function definir(User $autor, array $campos): void
    {
        if (! Gate::forUser($autor)->allows('tempos-gerir-equipa')) {
            throw new AuthorizationException('Só quem gere a equipa muda as regras.');
        }

        $antes = $this->ativos();
        $depois = $this->limpar($campos);

        DefinicaoTempo::updateOrCreate(['chave' => self::CHAVE], ['valor' => $depois, 'alterado_por' => $autor->id]);

        if ($antes !== $depois) {
            Auditor::registar('tempo_campos_obrigatorios', null, ['antes' => $antes, 'depois' => $depois]);
        }
    }

    /**
     * Erros de um registo terminado a que falte um campo obrigatório, por campo do registo.
     *
     * @return array<string, string>
     */
    public function erros(RegistoTempo $registo): array
    {
        $ativos = $this->ativos();
        $erros = [];

        if (in_array('projeto', $ativos, true) && $registo->projeto_id === null) {
            $erros['projeto_id'] = 'O projeto é obrigatório.';
        }
        if (in_array('descricao', $ativos, true) && trim((string) $registo->descricao) === '') {
            $erros['descricao'] = 'A descrição é obrigatória.';
        }
        if (in_array('etiquetas', $ativos, true) && (array) $registo->etiquetas === []) {
            $erros['etiquetas'] = 'Indique pelo menos uma etiqueta.';
        }

        return $erros;
    }

    /**
     * Só campos conhecidos, sem repetidos, pela ordem da lista.
     *
     * @return list<string>
     */
    private function limpar(array $campos): array
    {
        return array_values(array_filter(array_keys(self::CAMPOS), fn ($c) => in_array($c, $campos, true)));
    }
}

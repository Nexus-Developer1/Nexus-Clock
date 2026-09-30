<?php

namespace App\Services\Tempos;

use App\Models\DefinicaoTempo;
use App\Models\RegistoTempo;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Campos obrigatórios (como os «Required fields» do Clockify, notas §58 e §59): quem gere a equipa escolhe
 * em Equipa › Regras, por sítio — registos de horas, despesas, clientes e projetos — que campos têm de vir
 * preenchidos. Valem para toda a gente, admins incluídos. Cada serviço de gravação pergunta aqui (o
 * GravadorRegistos, o GestorDespesas, o GestorClientes e o GestorProjetos).
 */
class CamposObrigatorios
{
    /** Por sítio: título na página e campos que podem ser obrigatórios (os sempre obrigatórios não entram). */
    public const AREAS = [
        'registos' => ['titulo' => 'Registos de horas', 'campos' => ['projeto' => 'Projeto', 'descricao' => 'Descrição', 'etiquetas' => 'Etiquetas']],
        'despesas' => ['titulo' => 'Despesas', 'campos' => ['recibo' => 'Recibo', 'projeto' => 'Projeto']],
        'clientes' => ['titulo' => 'Clientes', 'campos' => ['email' => 'Email', 'morada' => 'Morada', 'nif' => 'NIF']],
        'projetos' => ['titulo' => 'Projetos', 'campos' => ['cliente' => 'Cliente', 'estimativa' => 'Estimativa de horas']],
    ];

    /** @return list<string> */
    public function ativos(string $area = 'registos'): array
    {
        return $this->limpar($area, (array) DefinicaoTempo::valor($this->chave($area), []));
    }

    public function exige(string $area, string $campo): bool
    {
        return in_array($campo, $this->ativos($area), true);
    }

    /** @param list<string> $campos */
    public function definir(User $autor, string $area, array $campos): void
    {
        if (! Gate::forUser($autor)->allows('tempos-gerir-equipa')) {
            throw new AuthorizationException('Só quem gere a equipa muda as regras.');
        }

        $antes = $this->ativos($area);
        $depois = $this->limpar($area, $campos);

        DefinicaoTempo::updateOrCreate(['chave' => $this->chave($area)], ['valor' => $depois, 'alterado_por' => $autor->id]);

        if ($antes !== $depois) {
            Auditor::registar('tempo_campos_obrigatorios', null, ['area' => $area, 'antes' => $antes, 'depois' => $depois]);
        }
    }

    /**
     * Erros de um registo de horas terminado a que falte um campo obrigatório, por campo do registo.
     *
     * @return array<string, string>
     */
    public function erros(RegistoTempo $registo): array
    {
        $ativos = $this->ativos('registos');
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

    private function chave(string $area): string
    {
        if (! isset(self::AREAS[$area])) {
            throw new InvalidArgumentException('Sítio desconhecido: '.$area);
        }

        return 'campos_obrigatorios.'.$area;
    }

    /**
     * Só campos conhecidos do sítio, sem repetidos, pela ordem da lista.
     *
     * @return list<string>
     */
    private function limpar(string $area, array $campos): array
    {
        return array_values(array_filter(array_keys(self::AREAS[$area]['campos']), fn ($c) => in_array($c, $campos, true)));
    }
}

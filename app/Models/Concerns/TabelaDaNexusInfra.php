<?php

namespace App\Models\Concerns;

use LogicException;

// Modelos de tabelas que pertencem à Nexus Infra (clientes, contratos, intervenções…). Esta
// aplicação só as LÊ: qualquer tentativa de gravar ou apagar rebenta, para que um engano nunca
// chegue à base partilhada. Nos testes e na base de desenvolvimento é preciso criar estes dados,
// e aí liga-se `tempos.escrever_tabelas_da_nexus_infra` (TestCase e DatabaseSeeder).
trait TabelaDaNexusInfra
{
    public function getConnectionName(): ?string
    {
        return config('database.suite');
    }

    protected static function bootTabelaDaNexusInfra(): void
    {
        $travar = function ($modelo): void {
            if (! config('tempos.escrever_tabelas_da_nexus_infra')) {
                throw new LogicException('A tabela '.$modelo->getTable().' pertence à Nexus Infra e é só de leitura nesta aplicação.');
            }
        };

        static::saving($travar);
        static::deleting($travar);
    }
}

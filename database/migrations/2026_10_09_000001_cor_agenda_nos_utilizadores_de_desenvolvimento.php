<?php

use App\Providers\AppServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `utilizadores.cor_agenda` é uma coluna da Nexus Infra (a cor de cada pessoa na agenda da IFE), que
 * o Calendário do Suporte lê para as iniciais (notas §75). Em produção já existe e esta migração não
 * faz nada. Só nas bases descartáveis (tempos_dev, tempos_testing), onde a tabela vem do
 * `garantir_tabelas_partilhadas`, é que a cria — nunca toca na estrutura de uma base da suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->descartavel() || Schema::hasColumn('utilizadores', 'cor_agenda')) {
            return;
        }

        Schema::table('utilizadores', function (Blueprint $table) {
            $table->string('cor_agenda', 7)->nullable();
        });
    }

    public function down(): void
    {
        if ($this->descartavel() && Schema::hasColumn('utilizadores', 'cor_agenda')) {
            Schema::table('utilizadores', fn (Blueprint $table) => $table->dropColumn('cor_agenda'));
        }
    }

    private function descartavel(): bool
    {
        return in_array(Schema::getConnection()->getDatabaseName(), AppServiceProvider::BASES_DESCARTAVEIS, true);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Linhas da folha de horas de um técnico numa semana: uma por combinação cliente / contrato /
// intervenção, com a descrição, o faturável e as etiquetas DA LINHA (não da célula — decisão da
// especificação). As horas vivem em `registos_tempo`; esta tabela só guarda o que não cabe lá:
//  - linhas acrescentadas e ainda sem horas (senão desapareciam ao recarregar);
//  - linhas persistentes que o técnico retirou (`removida`), para não voltarem a aparecer.
// Uma linha que só existe por ter registos (ex.: cronómetro) não precisa de estar aqui.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linhas_semana', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tecnico_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->date('semana_inicio');
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->foreignId('intervencao_id')->nullable()->constrained('intervencoes')->nullOnDelete();
            $table->text('descricao')->nullable();
            $table->boolean('faturavel')->default(true);
            $table->boolean('removida')->default(false);
            $table->timestampsTz();

            $table->index(['tecnico_id', 'semana_inicio']);
        });

        DB::statement("alter table linhas_semana add column etiquetas text[] not null default '{}'");

        DB::statement('alter table linhas_semana add constraint linhas_semana_segunda check (extract(isodow from semana_inicio) = 1)');

        // Uma linha por combinação na semana — contrato e intervenção vazios contam como iguais.
        DB::statement('create unique index linhas_semana_combinacao
            on linhas_semana (tecnico_id, semana_inicio, cliente_id, contrato_id, intervencao_id) nulls not distinct');
    }

    public function down(): void
    {
        Schema::dropIfExists('linhas_semana');
    }
};

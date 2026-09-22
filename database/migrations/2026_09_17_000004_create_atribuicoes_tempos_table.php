<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Atribuições (como o "Schedule" do Clockify): uma pessoa num projeto, entre duas datas, com um número de
// horas por dia (nos dias úteis, ou também ao fim de semana). O relatório Atribuições compara estas
// horas agendadas com as registadas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atribuicoes_tempos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilizador_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->foreignId('projeto_id')->nullable()->constrained('projetos_tempos')->nullOnDelete();
            $table->date('de');
            $table->date('ate');
            $table->integer('horas_dia_seg');
            $table->boolean('fins_de_semana')->default(false);
            $table->text('nota')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['utilizador_id', 'de', 'ate']);
            $table->index('projeto_id');
        });

        DB::statement('alter table atribuicoes_tempos add constraint atribuicoes_tempos_valores check (ate >= de and horas_dia_seg between 60 and 86400)');
    }

    public function down(): void
    {
        Schema::dropIfExists('atribuicoes_tempos');
    }
};

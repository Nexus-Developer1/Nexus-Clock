<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Mais campos por membro da equipa (como no Clockify): dia em que começa a semana, dias de trabalho,
// capacidade diária (horas de trabalho por dia, em segundos) e gestor de equipa atribuído.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membros_equipa', function (Blueprint $table) {
            $table->smallInteger('inicio_semana')->default(1); // 1 = segunda … 7 = domingo
            $table->integer('capacidade_diaria_seg')->nullable();
            $table->foreignId('gestor_id')->nullable()->constrained('membros_equipa')->nullOnDelete();
        });

        DB::statement("alter table membros_equipa add column dias_trabalho smallint[] not null default '{1,2,3,4,5}'");
        DB::statement('alter table membros_equipa add constraint membros_equipa_campos check (
            inicio_semana between 1 and 7
            and (capacidade_diaria_seg is null or capacidade_diaria_seg between 0 and 86400)
            and (gestor_id is null or gestor_id <> id))');
    }

    public function down(): void
    {
        DB::statement('alter table membros_equipa drop constraint membros_equipa_campos');

        Schema::table('membros_equipa', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gestor_id');
            $table->dropColumn(['inicio_semana', 'capacidade_diaria_seg', 'dias_trabalho']);
        });
    }
};

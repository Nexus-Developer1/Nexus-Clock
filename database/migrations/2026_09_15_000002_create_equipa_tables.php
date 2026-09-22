<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Página Equipa (como a do Clockify):
//  - membros_equipa: uma linha por pessoa da equipa. Membro PLENO = pessoa da suite com acesso aos
//    Tempos (utilizador_id; a conta e o acesso gerem-se no portal). Membro LIMITADO = sem conta,
//    só nome e email, criado aqui. Papel ao estilo do Clockify, guardado aqui.
//  - taxas_membros: taxa faturável e taxa de custo por membro, COM HISTÓRICO (cada mudança vale a
//    partir de uma data; valor nulo = sem taxa a partir dessa data).
//  - grupos_equipa + grupo_membro: grupos de pessoas.
//  - lembretes_equipa: lembretes por email a quem registou menos horas do que o mínimo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membros_equipa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilizador_id')->nullable()->unique()->constrained('utilizadores')->nullOnDelete();
            $table->boolean('limitado')->default(false);
            $table->string('nome')->nullable();   // só limitados (os plenos têm o nome da conta)
            $table->string('email')->nullable();  // só limitados
            $table->string('papel', 20)->default('membro');
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("alter table membros_equipa add constraint membros_equipa_valores check (
            papel in ('proprietario', 'administrador', 'gestor_projeto', 'gestor_equipa', 'membro')
            and (limitado = false or (nome is not null and papel <> 'proprietario')))");
        // Um só proprietário.
        DB::statement("create unique index membros_equipa_um_proprietario on membros_equipa (papel) where papel = 'proprietario' and deleted_at is null");

        Schema::create('taxas_membros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membro_id')->constrained('membros_equipa')->cascadeOnDelete();
            $table->string('tipo', 10);                 // faturavel | custo
            $table->integer('valor_cent')->nullable();  // €/h; null = sem taxa a partir desta data
            $table->date('valido_de');
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['membro_id', 'tipo', 'valido_de']);
        });

        DB::statement("alter table taxas_membros add constraint taxas_membros_valores check (
            tipo in ('faturavel', 'custo') and (valor_cent is null or valor_cent >= 0))");

        Schema::create('grupos_equipa', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('create unique index grupos_equipa_nome_unico on grupos_equipa (lower(nome))');

        Schema::create('grupo_membro', function (Blueprint $table) {
            $table->foreignId('grupo_id')->constrained('grupos_equipa')->cascadeOnDelete();
            $table->foreignId('membro_id')->constrained('membros_equipa')->cascadeOnDelete();
            $table->primary(['grupo_id', 'membro_id']);
        });

        Schema::create('lembretes_equipa', function (Blueprint $table) {
            $table->id();
            $table->string('destinatarios', 10)->default('todos'); // todos | grupos
            $table->string('periodo', 10)->default('dia');          // dia (anterior) | semana (anterior)
            $table->decimal('horas_minimas', 5, 2);
            $table->smallInteger('hora');                           // 0–23, hora de Lisboa
            $table->boolean('ativo')->default(true);
            $table->date('enviado_em')->nullable();                 // último dia em que saiu
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("alter table lembretes_equipa add column dias smallint[] not null default '{1,2,3,4,5}'"); // 1 = segunda … 7 = domingo
        DB::statement("alter table lembretes_equipa add column grupos bigint[] not null default '{}'");
        DB::statement("alter table lembretes_equipa add constraint lembretes_equipa_valores check (
            destinatarios in ('todos', 'grupos') and periodo in ('dia', 'semana')
            and hora between 0 and 23 and horas_minimas > 0 and cardinality(dias) > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('lembretes_equipa');
        Schema::dropIfExists('grupo_membro');
        Schema::dropIfExists('grupos_equipa');
        Schema::dropIfExists('taxas_membros');
        Schema::dropIfExists('membros_equipa');
    }
};

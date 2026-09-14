<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Registos de tempo dos técnicos. Durações SEMPRE em segundos, nunca arredondadas aqui.
// `inicio` é timestamptz em UTC: na timesheet é a meia-noite (Europe/Lisbon) do dia; no
// cronómetro é a hora real. `fim` null = cronómetro a correr (máx. um por técnico).
//
// Chaves estrangeiras para tabelas da Nexus Infra com SET NULL (convenção da suite): apagar uma
// pessoa, cliente, contrato ou intervenção lá não pode rebentar. Por isso as colunas são nullable
// na base de dados; a obrigatoriedade (técnico, cliente) é imposta pela aplicação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registos_tempo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tecnico_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->foreignId('intervencao_id')->nullable()->constrained('intervencoes')->nullOnDelete();
            $table->timestampTz('inicio');
            $table->timestampTz('fim')->nullable();
            $table->integer('duracao_seg')->nullable();
            $table->boolean('faturavel')->default(true);
            $table->text('descricao')->nullable();
            $table->string('origem', 20)->default('timesheet'); // timesheet | cronometro | importacao
            $table->timestampTz('submetido_em')->nullable();    // semana submetida pelo técnico
            $table->timestampTz('fechado_em')->nullable();      // fecho mensal para faturação
            $table->timestampTz('faturado_em')->nullable();     // incluído num export de faturação
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tecnico_id', 'inicio']);
            $table->index(['contrato_id', 'inicio']);
            $table->index(['cliente_id', 'inicio']);
        });

        // Etiquetas (ex.: deslocação, remoto) — text[], sem equivalente no Blueprint.
        DB::statement("alter table registos_tempo add column etiquetas text[] not null default '{}'");

        DB::statement("alter table registos_tempo add constraint registos_tempo_valores check (
            origem in ('timesheet', 'cronometro', 'importacao')
            and (duracao_seg is null or (duracao_seg >= 0 and duracao_seg <= 86400))
            and (fim is null or duracao_seg is not null))");

        // Máximo UM cronómetro a correr por técnico (regra 5).
        DB::statement('create unique index registos_tempo_um_cronometro_por_tecnico
            on registos_tempo (tecnico_id) where fim is null and deleted_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('registos_tempo');
    }
};

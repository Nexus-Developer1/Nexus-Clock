<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Relatórios partilhados (como o "Share report" do Clockify): um link secreto para um relatório com os
// filtros e agrupamentos de quem o criou. Público = qualquer pessoa com o link; privado = só quem tem
// acesso aos Tempos. "Sempre atual" abre no período corrente (esta semana, este mês, este ano);
// "bloquear datas" impede quem vê de mudar o período. Pode ser enviado por email com regularidade.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relatorios_partilhados', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('nome', 250);
            $table->string('tipo', 20)->default('resumo');
            $table->boolean('publico')->default(true);
            $table->boolean('sempre_atual')->default(true);
            $table->boolean('bloquear_datas')->default(false);
            $table->jsonb('parametros');
            $table->boolean('email_ativo')->default(false);
            $table->string('email_frequencia', 10)->default('semanal'); // diaria | semanal | mensal
            $table->smallInteger('email_hora')->default(8);
            $table->timestampTz('email_enviado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("alter table relatorios_partilhados add column email_destinatarios text[] not null default '{}'");
        DB::statement("alter table relatorios_partilhados add constraint relatorios_partilhados_valores check (
            char_length(nome) between 2 and 250
            and email_frequencia in ('diaria', 'semanal', 'mensal')
            and email_hora between 0 and 23
            and cardinality(email_destinatarios) <= 10
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('relatorios_partilhados');
    }
};

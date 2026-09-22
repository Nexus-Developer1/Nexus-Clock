<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Exportações de faturação de um mês fechado (regra 13). Guardam uma FOTOGRAFIA das linhas (cliente,
// contrato, descrição, horas arredondadas, preço, valor) — o CSV e os PDF saem sempre desta
// fotografia, mesmo que tarifas ou horas incluídas mudem depois. Voltar a exportar anula a anterior
// (fica no histórico) e só com confirmação explícita. Os registos faturados apontam para a exportação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacoes_faturacao', function (Blueprint $table) {
            $table->id();
            $table->date('mes');
            $table->jsonb('linhas');
            $table->bigInteger('total_cent')->default(0);
            $table->integer('segundos')->default(0);
            $table->integer('registos')->default(0);
            $table->foreignId('criada_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampTz('anulada_em')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();

            $table->index('mes');
        });

        // Uma só exportação em vigor por mês.
        DB::statement('create unique index exportacoes_faturacao_uma_por_mes on exportacoes_faturacao (mes) where anulada_em is null');

        Schema::table('registos_tempo', function (Blueprint $table) {
            $table->foreignId('exportacao_faturacao_id')->nullable()->after('faturado_em')
                ->constrained('exportacoes_faturacao')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registos_tempo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exportacao_faturacao_id');
        });

        Schema::dropIfExists('exportacoes_faturacao');
    }
};

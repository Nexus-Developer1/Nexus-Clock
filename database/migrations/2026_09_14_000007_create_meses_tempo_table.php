<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Estado de cada mês para a faturação (regra 12): aberto ou fechado. Fechar marca `fechado_em` em
// todos os registos do mês; reabrir (com permissão explícita) desfaz e fica na auditoria. Sem linha =
// aberto. Uma linha por mês (dia 1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meses_tempo', function (Blueprint $table) {
            $table->id();
            $table->date('mes')->unique();
            $table->string('estado', 10)->default('aberto'); // aberto | fechado
            $table->timestampTz('fechado_em')->nullable();
            $table->foreignId('fechado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampTz('reaberto_em')->nullable();
            $table->foreignId('reaberto_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("alter table meses_tempo add constraint meses_tempo_valores check (
            estado in ('aberto', 'fechado') and extract(day from mes) = 1)");
    }

    public function down(): void
    {
        Schema::dropIfExists('meses_tempo');
    }
};

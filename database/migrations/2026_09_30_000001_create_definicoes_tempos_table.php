<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Definições do Suporte que quem gere a equipa muda na aplicação (a primeira: os campos obrigatórios
// nos registos, notas §58). Uma linha por chave, com o valor em JSON.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('definicoes_tempos', function (Blueprint $table) {
            $table->string('chave', 100)->primary();
            $table->jsonb('valor');
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('definicoes_tempos');
    }
};

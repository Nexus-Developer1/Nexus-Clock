<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A página Equipa › Regras saiu (notas §60): não era o que o utilizador queria. A tabela das definições
// só servia para ela e estava vazia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('definicoes_tempos');
    }

    public function down(): void
    {
        Schema::create('definicoes_tempos', function (Blueprint $table) {
            $table->string('chave', 100)->primary();
            $table->jsonb('valor');
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
        });
    }
};

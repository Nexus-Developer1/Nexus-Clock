<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Projeto (opcional) de cada registo, para as horas contarem por projeto (aprovado a 2026-09-17).
// Apagar o projeto deixa o registo sem projeto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registos_tempo', function (Blueprint $table) {
            $table->foreignId('projeto_id')->nullable()->after('contrato_id')->constrained('projetos_tempos')->nullOnDelete();
            $table->index('projeto_id');
        });
    }

    public function down(): void
    {
        Schema::table('registos_tempo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('projeto_id');
        });
    }
};

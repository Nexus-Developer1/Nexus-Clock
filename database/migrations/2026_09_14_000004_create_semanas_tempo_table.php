<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Estado da semana de cada técnico na timesheet (rascunho → submetida → reaberta).
// Semana começa à segunda-feira. Uma linha por técnico por semana.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semanas_tempo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tecnico_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->date('semana_inicio');
            $table->string('estado', 20)->default('rascunho'); // rascunho | submetida | reaberta
            $table->timestampTz('submetida_em')->nullable();
            $table->foreignId('reaberta_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tecnico_id', 'semana_inicio']);
        });

        DB::statement("alter table semanas_tempo add constraint semanas_tempo_valores check (
            estado in ('rascunho', 'submetida', 'reaberta')
            and extract(isodow from semana_inicio) = 1)");
    }

    public function down(): void
    {
        Schema::dropIfExists('semanas_tempo');
    }
};

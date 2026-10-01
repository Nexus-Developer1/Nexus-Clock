<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A pausa do cronómetro saiu a pedido (notas §68): as colunas que só ela usava também.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table registos_tempo drop constraint if exists registos_tempo_pausa');
        Schema::table('registos_tempo', function (Blueprint $table) {
            $table->dropColumn(['pausado_em', 'pausa_seg']);
        });
    }

    public function down(): void
    {
        Schema::table('registos_tempo', function (Blueprint $table) {
            $table->timestampTz('pausado_em')->nullable();
            $table->integer('pausa_seg')->default(0);
        });
        DB::statement('alter table registos_tempo add constraint registos_tempo_pausa check (pausa_seg >= 0 and (pausado_em is null or fim is null))');
    }
};

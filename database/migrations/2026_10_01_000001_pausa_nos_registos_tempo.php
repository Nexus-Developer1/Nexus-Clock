<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Pausa no cronómetro (notas §67): um só registo, com a pausa descontada. `pausado_em` = desde quando
// está em pausa (só num cronómetro a correr); `pausa_seg` = pausas já acumuladas. A duração de um
// registo com pausas é (fim − início) − pausa_seg.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registos_tempo', function (Blueprint $table) {
            $table->timestampTz('pausado_em')->nullable();
            $table->integer('pausa_seg')->default(0);
        });

        DB::statement('alter table registos_tempo add constraint registos_tempo_pausa check (pausa_seg >= 0 and (pausado_em is null or fim is null))');
    }

    public function down(): void
    {
        DB::statement('alter table registos_tempo drop constraint if exists registos_tempo_pausa');
        Schema::table('registos_tempo', function (Blueprint $table) {
            $table->dropColumn(['pausado_em', 'pausa_seg']);
        });
    }
};

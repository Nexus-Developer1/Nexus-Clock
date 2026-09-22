<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Aprovação das semanas (um só nível): rascunho → submetida → aprovada, ou → rejeitada (com motivo,
// volta a ser editável pelo técnico). Reaberta continua a existir para corrigir uma semana entregue.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('semanas_tempo', function (Blueprint $table) {
            $table->timestampTz('aprovada_em')->nullable();
            $table->foreignId('aprovada_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampTz('rejeitada_em')->nullable();
            $table->foreignId('rejeitada_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->text('motivo_rejeicao')->nullable();
        });

        DB::statement('alter table semanas_tempo drop constraint semanas_tempo_valores');
        DB::statement("alter table semanas_tempo add constraint semanas_tempo_valores check (
            estado in ('rascunho', 'submetida', 'aprovada', 'rejeitada', 'reaberta')
            and extract(isodow from semana_inicio) = 1)");
    }

    public function down(): void
    {
        DB::statement("update semanas_tempo set estado = 'submetida' where estado = 'aprovada'");
        DB::statement("update semanas_tempo set estado = 'reaberta' where estado = 'rejeitada'");
        DB::statement('alter table semanas_tempo drop constraint semanas_tempo_valores');
        DB::statement("alter table semanas_tempo add constraint semanas_tempo_valores check (
            estado in ('rascunho', 'submetida', 'reaberta')
            and extract(isodow from semana_inicio) = 1)");

        Schema::table('semanas_tempo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aprovada_por');
            $table->dropConstrainedForeignId('rejeitada_por');
            $table->dropColumn(['aprovada_em', 'rejeitada_em', 'motivo_rejeicao']);
        });
    }
};

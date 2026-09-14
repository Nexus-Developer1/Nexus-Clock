<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Horas incluídas num contrato, por período, com validade. Tabela própria (e não colunas em
// `contratos`, que pertence à Nexus Infra) porque as condições mudam a meio do contrato e é
// preciso histórico. Só uma ativa por contrato numa dada data — validado na aplicação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrato_horas_incluidas', function (Blueprint $table) {
            $table->id();
            // SET NULL como o resto da suite: apagar um contrato na Nexus Infra não pode rebentar.
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->string('periodo', 20);                     // mensal | trimestral | anual | total
            $table->decimal('horas_incluidas', 8, 2);
            $table->boolean('transita')->default(false);       // horas não usadas passam ao período seguinte
            $table->boolean('excedente_faturavel')->default(true);
            $table->foreignId('tarifa_excedente_id')->nullable()->constrained('tarifas')->nullOnDelete();
            $table->smallInteger('arredondamento_min')->default(15); // 0 = sem arredondamento
            $table->string('arredondamento_modo', 10)->default('cima'); // cima | proximo | baixo
            $table->date('valido_de');
            $table->date('valido_ate')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contrato_id', 'valido_de']);
        });

        DB::statement("alter table contrato_horas_incluidas add constraint contrato_horas_incluidas_valores check (
            periodo in ('mensal', 'trimestral', 'anual', 'total')
            and arredondamento_modo in ('cima', 'proximo', 'baixo')
            and horas_incluidas >= 0
            and arredondamento_min >= 0
            and (valido_ate is null or valido_ate >= valido_de))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_horas_incluidas');
    }
};

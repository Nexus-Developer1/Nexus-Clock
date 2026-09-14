<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tarifas horárias: preço de venda ao cliente e custo interno (margem), em cêntimos por hora.
// Âmbito: contrato, cliente ou técnico (ambito_tipo + ambito_id); sem âmbito = tarifa global.
// A resolução para um registo é contrato → cliente → técnico → global, válida na data do registo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas', function (Blueprint $table) {
            $table->id();
            $table->string('ambito_tipo', 20)->nullable(); // contrato | cliente | tecnico | null (global)
            $table->unsignedBigInteger('ambito_id')->nullable();
            $table->integer('preco_hora_cent');             // €/h faturado ao cliente
            $table->integer('custo_hora_cent')->nullable(); // €/h custo interno
            $table->date('valido_de');
            $table->date('valido_ate')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ambito_tipo', 'ambito_id', 'valido_de']);
        });

        DB::statement("alter table tarifas add constraint tarifas_ambito_valido check (
            (ambito_tipo is null and ambito_id is null)
            or (ambito_tipo in ('contrato', 'cliente', 'tecnico') and ambito_id is not null))");
        DB::statement('alter table tarifas add constraint tarifas_valores_positivos check (
            preco_hora_cent >= 0 and (custo_hora_cent is null or custo_hora_cent >= 0))');
        DB::statement('alter table tarifas add constraint tarifas_validade_ordenada check (
            valido_ate is null or valido_ate >= valido_de)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas');
    }
};

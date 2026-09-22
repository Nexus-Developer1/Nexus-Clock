<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Clientes dos Tempos (página Clientes): lista própria desta aplicação, geridas aqui — não são os
// clientes da Nexus Infra (esses vêm do ERP e são só de leitura). Arquivar tira-os da lista de
// ativos; apagar (só arquivados) é soft delete. O nome é único entre os não apagados.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes_tempos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('email')->nullable();
            $table->text('morada')->nullable();
            $table->text('nota')->nullable();
            $table->char('moeda', 3)->default('EUR');
            $table->timestampTz('arquivado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Até 3 emails em cópia.
        DB::statement("alter table clientes_tempos add column emails_cc text[] not null default '{}'");
        DB::statement('alter table clientes_tempos add constraint clientes_tempos_cc check (cardinality(emails_cc) <= 3)');
        DB::statement('create unique index clientes_tempos_nome_unico on clientes_tempos (lower(nome)) where deleted_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes_tempos');
    }
};

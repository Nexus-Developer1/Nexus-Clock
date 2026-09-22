<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Projetos dos Tempos (página Projetos, como no Clockify): lista própria, com cliente da página
// Clientes, cor, acesso público ou privado (privado = só administradores e os membros escolhidos),
// faturável com taxa própria (sem taxa = a taxa faturável de cada membro), estimativa de horas e
// favoritos por pessoa. Arquivar tira-os da lista de ativos; apagar (só arquivados) é soft delete.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projetos_tempos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->foreignId('cliente_id')->nullable()->constrained('clientes_tempos')->nullOnDelete();
            $table->char('cor', 7)->default('#16a34a');
            $table->boolean('publico')->default(true);
            $table->boolean('faturavel')->default(true);
            $table->integer('taxa_cent')->nullable();      // €/h; null = taxa faturável do membro
            $table->integer('estimativa_seg')->nullable(); // horas previstas (progresso)
            $table->text('nota')->nullable();
            $table->timestampTz('arquivado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("alter table projetos_tempos add constraint projetos_tempos_cor check (cor ~ '^#[0-9a-f]{6}$')");
        DB::statement('alter table projetos_tempos add constraint projetos_tempos_valores check ((taxa_cent is null or taxa_cent >= 0) and (estimativa_seg is null or estimativa_seg > 0))');
        // O mesmo nome pode repetir-se em clientes diferentes, não no mesmo cliente.
        DB::statement('create unique index projetos_tempos_nome_unico on projetos_tempos (lower(nome), coalesce(cliente_id, 0)) where deleted_at is null');

        // Membros de um projeto privado.
        Schema::create('projeto_membro', function (Blueprint $table) {
            $table->foreignId('projeto_id')->constrained('projetos_tempos')->cascadeOnDelete();
            $table->foreignId('membro_id')->constrained('membros_equipa')->cascadeOnDelete();
            $table->primary(['projeto_id', 'membro_id']);
        });

        // Favoritos de cada pessoa (aparecem primeiro na lista).
        Schema::create('projeto_favoritos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->constrained('projetos_tempos')->cascadeOnDelete();
            $table->foreignId('utilizador_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['projeto_id', 'utilizador_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projeto_favoritos');
        Schema::dropIfExists('projeto_membro');
        Schema::dropIfExists('projetos_tempos');
    }
};

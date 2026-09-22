<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Despesas dos Tempos (como as "Expenses" do Clockify) — próprias desta aplicação, separadas das da
// Nexus Infra (decisão do utilizador, 2026-09-17): quem, quando, projeto, categoria, valor, faturável,
// nota e recibo; estado pendente/aprovada/rejeitada, decidido por quem gere a equipa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_despesa_tempos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->timestampTz('arquivada_em')->nullable();
            $table->timestampsTz();
        });
        DB::statement('create unique index categorias_despesa_tempos_nome_unico on categorias_despesa_tempos (lower(nome))');

        // Categorias iniciais (as da folha de despesas da empresa); geridas depois na página Despesas.
        $agora = now();
        DB::table('categorias_despesa_tempos')->insert(array_map(
            fn (string $nome) => ['nome' => $nome, 'created_at' => $agora, 'updated_at' => $agora],
            ['Combustíveis', 'Portagens e estacionamento', 'Refeições', 'Alojamento', 'Transportes', 'Material', 'Outras despesas'],
        ));

        Schema::create('despesas_tempos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilizador_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->date('data');
            $table->foreignId('projeto_id')->nullable()->constrained('projetos_tempos')->nullOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_despesa_tempos')->nullOnDelete();
            $table->integer('valor_cent');
            $table->boolean('faturavel')->default(false);
            $table->text('nota')->nullable();
            $table->string('recibo_caminho')->nullable();
            $table->string('recibo_nome')->nullable();
            $table->string('estado', 10)->default('pendente'); // pendente | aprovada | rejeitada
            $table->foreignId('decidido_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampTz('decidido_em')->nullable();
            $table->string('motivo_rejeicao', 500)->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->foreignId('alterado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['data', 'utilizador_id']);
        });

        DB::statement("alter table despesas_tempos add constraint despesas_tempos_valores check (
            valor_cent > 0 and estado in ('pendente', 'aprovada', 'rejeitada')
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('despesas_tempos');
        Schema::dropIfExists('categorias_despesa_tempos');
    }
};

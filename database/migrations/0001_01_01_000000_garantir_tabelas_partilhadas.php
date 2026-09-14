<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As tabelas abaixo pertencem à Nexus Infra (utilizadores, clientes, contratos, intervenções…)
 * e ao portal (aplicacoes, acessos). Em produção já existem e esta migração não faz
 * rigorosamente nada.
 *
 * Existe para que os testes e a base de desenvolvimento (que começam vazias) tenham onde
 * assentar, e para que as migrações dos tempos possam criar as chaves estrangeiras. Descreve
 * só as colunas que este módulo lê, tal como são lá; não altera nem toca em nada que exista.
 * Quando a estrutura lá mudar, é aqui que se acompanha.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('utilizadores')) {
            Schema::create('utilizadores', function (Blueprint $table) {
                $table->id();
                $table->string('nome');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->string('papel', 20)->default('tecnico');
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->boolean('ativo')->default(true);
                $table->boolean('faz_servicos')->default(true);
                $table->timestamp('password_alterada_em')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('clientes')) {
            Schema::create('clientes', function (Blueprint $table) {
                $table->id();
                $table->string('id_erp')->nullable();
                $table->string('nome');
                $table->string('nif')->nullable();
                $table->boolean('ativo')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('locais')) {
            Schema::create('locais', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
                $table->string('designacao');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('equipamentos')) {
            Schema::create('equipamentos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('local_id')->nullable()->constrained('locais')->nullOnDelete();
                $table->string('tipo');
                $table->string('fabricante')->nullable();
                $table->string('modelo')->nullable();
                $table->string('numero_serie')->nullable();
                $table->string('estado')->default('operacional');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('contratos')) {
            Schema::create('contratos', function (Blueprint $table) {
                $table->id();
                $table->string('numero');
                $table->foreignId('cliente_id')->constrained('clientes');
                $table->date('data_inicio');
                $table->date('data_fim');
                $table->string('estado')->default('rascunho');
                $table->string('tipo')->default('preventiva');
                $table->decimal('valor', 12, 2)->nullable();
                $table->boolean('renovacao_automatica')->default(false);
                $table->integer('periodo_aviso_dias')->default(30);
                $table->integer('visitas_incluidas')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('intervencoes')) {
            Schema::create('intervencoes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
                $table->foreignId('tecnico_id')->nullable()->constrained('utilizadores')->nullOnDelete();
                $table->unsignedBigInteger('contrato_id')->nullable();
                $table->string('tipo')->default('corretiva');
                $table->string('estado')->default('planeada'); // planeada | em_curso | concluida
                $table->timestamp('data_inicio')->nullable();
                $table->timestamp('data_fim')->nullable();
                $table->text('descricao_problema')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Registo de quem fez o quê. Pertence à Nexus Infra, que tem o ecrã para a consultar;
        // os tempos escrevem nela (fecho/reabertura de mês, anulação de registos faturados).
        if (! Schema::hasTable('auditoria')) {
            Schema::create('auditoria', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('utilizadores')->nullOnDelete();
                $table->string('email')->nullable();
                $table->string('acao')->index();
                $table->string('entidade_tipo')->nullable();
                $table->unsignedBigInteger('entidade_id')->nullable();
                $table->jsonb('detalhe')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('criado_em')->useCurrent();
            });
        }

        // Do portal: que aplicações existem e quem entra em cada uma (com que papel).
        if (! Schema::hasTable('aplicacoes')) {
            Schema::create('aplicacoes', function (Blueprint $table) {
                $table->id();
                $table->string('chave', 40)->unique();
                $table->string('nome', 80);
                $table->string('descricao', 200)->nullable();
                $table->string('url', 255);
                $table->string('icone', 40)->default('aplicacao');
                $table->unsignedSmallInteger('ordem')->default(0);
                $table->boolean('activa')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('acessos')) {
            Schema::create('acessos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('utilizador_id')->constrained('utilizadores')->cascadeOnDelete();
                $table->foreignId('aplicacao_id')->constrained('aplicacoes')->cascadeOnDelete();
                $table->string('papel', 40)->nullable();
                $table->string('contexto', 40)->nullable();
                $table->timestamps();

                $table->unique(['utilizador_id', 'aplicacao_id']);
            });
        }
    }

    public function down(): void
    {
        // Não se apagam tabelas que pertencem a outras aplicações.
    }
};

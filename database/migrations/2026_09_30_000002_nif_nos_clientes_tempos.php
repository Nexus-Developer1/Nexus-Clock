<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NIF dos clientes do Suporte (notas §59): opcional, a menos que Equipa › Regras o torne obrigatório.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes_tempos', function (Blueprint $table) {
            $table->string('nif', 20)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('clientes_tempos', function (Blueprint $table) {
            $table->dropColumn('nif');
        });
    }
};

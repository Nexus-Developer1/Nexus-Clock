<?php

use App\Jobs\AtualizarConsumoContratos;
use Illuminate\Support\Facades\Schedule;

// Consumo dos contratos (view materializada contrato_consumo_periodo) refrescado todas as noites
// às 03h de Lisboa — longe do sync do ERP da Nexus Infra (08h/13h/19h e domingo às 06h), ninguém
// a usar. Corre na fila (worker), não no processo do scheduler. Também é disparado após o fecho mensal.
Schedule::job(new AtualizarConsumoContratos)
    ->name('tempos-consumo-contratos')
    ->timezone('Europe/Lisbon')
    ->dailyAt('03:00')
    ->onOneServer();

<?php

use App\Jobs\AtualizarConsumoContratos;
use App\Jobs\EnviarLembretesEquipa;
use App\Jobs\EnviarRelatoriosPartilhados;
use Illuminate\Support\Facades\Schedule;

// Consumo dos contratos (view materializada contrato_consumo_periodo) refrescado todas as noites
// às 03h de Lisboa — longe do sync do ERP da Nexus Infra (08h/13h/19h e domingo às 06h), ninguém
// a usar. Corre na fila (worker), não no processo do scheduler.
Schedule::job(new AtualizarConsumoContratos)
    ->name('tempos-consumo-contratos')
    ->timezone('Europe/Lisbon')
    ->dailyAt('03:00')
    ->onOneServer();

// Lembretes da equipa (página Equipa): de hora a hora vê os que estão marcados para este dia e esta
// hora de Lisboa e avisa quem registou menos horas do que o mínimo.
Schedule::job(new EnviarLembretesEquipa)
    ->name('tempos-lembretes-equipa')
    ->timezone('Europe/Lisbon')
    ->hourly()
    ->onOneServer();

// Relatórios partilhados com envio por email: de hora a hora vê os marcados para esta hora de Lisboa.
Schedule::job(new EnviarRelatoriosPartilhados)
    ->name('tempos-relatorios-partilhados')
    ->timezone('Europe/Lisbon')
    ->hourly()
    ->onOneServer();

<?php

use Illuminate\Support\Facades\Route;

// Toda a aplicação é interna: sessão da suite + acesso a esta aplicação dado no portal.
Route::middleware(['auth', 'acesso'])->group(function () {
    // Página provisória até à timesheet semanal (Fase 2).
    Route::get('/', fn () => view('inicio'))->name('inicio');
});

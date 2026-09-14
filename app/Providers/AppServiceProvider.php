<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // Bases onde migrate:fresh / db:wipe podem correr. Todas as outras são (ou podem ser) a base
    // partilhada com a Nexus Infra e o portal — um migrate:fresh lá apagava TODAS as tabelas.
    public const BASES_DESCARTAVEIS = ['tempos_dev', 'tempos_testing'];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        DB::prohibitDestructiveCommands(
            ! in_array(config('database.connections.'.config('database.default').'.database'), self::BASES_DESCARTAVEIS, true)
        );
    }
}

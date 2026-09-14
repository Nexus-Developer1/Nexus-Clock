<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Tempos\ResolvedorTarifa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    // Bases onde migrate:fresh / db:wipe podem correr. Todas as outras são (ou podem ser) a base
    // partilhada com a Nexus Infra e o portal — um migrate:fresh lá apagava TODAS as tabelas.
    public const BASES_DESCARTAVEIS = ['tempos_dev', 'tempos_testing'];

    public function register(): void
    {
        // Memória de tarifas por pedido (ou por job): começa vazia em cada um.
        $this->app->scoped(ResolvedorTarifa::class);
    }

    public function boot(): void
    {
        DB::prohibitDestructiveCommands(
            ! in_array(config('database.connections.'.config('database.default').'.database'), self::BASES_DESCARTAVEIS, true)
        );

        // Permissões dos tempos. O papel (admin/técnico) vem do portal (acessos.papel); reabrir exige
        // além disso estar na lista explícita config('tempos.pode_reabrir').
        Gate::define('tempos-ver-todos', fn (User $utilizador) => $utilizador->ehAdminTempos());
        Gate::define('tempos-editar-todos', fn (User $utilizador) => $utilizador->ehAdminTempos());
        Gate::define('tempos-fechar-mes', fn (User $utilizador) => $utilizador->ehAdminTempos());
        Gate::define('tempos-reabrir', fn (User $utilizador) => $utilizador->podeReabrirTempos());
        Gate::define('tempos-exportar', fn (User $utilizador) => $utilizador->ehAdminTempos());
    }
}

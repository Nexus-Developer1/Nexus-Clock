<?php

use App\Http\Controllers\ReciboDespesaController;
use App\Livewire\Clientes\Listagem as ClientesListagem;
use App\Livewire\Equipa\Grupos as EquipaGrupos;
use App\Livewire\Equipa\Lembretes as EquipaLembretes;
use App\Livewire\Equipa\Limitados as EquipaLimitados;
use App\Livewire\Equipa\Membros as EquipaMembros;
use App\Livewire\Painel\Pagina as PainelPagina;
use App\Livewire\Projetos\Listagem as ProjetosListagem;
use App\Livewire\Relatorios\Atribuicoes as RelatorioAtribuicoes;
use App\Livewire\Relatorios\Despesas as RelatorioDespesas;
use App\Livewire\Relatorios\Detalhado as RelatorioDetalhado;
use App\Livewire\Relatorios\Partilhado as RelatorioPartilhado;
use App\Livewire\Relatorios\Partilhados as RelatoriosPartilhados;
use App\Livewire\Relatorios\Presencas as RelatorioPresencas;
use App\Livewire\Relatorios\Resumo as RelatorioResumo;
use App\Livewire\Relatorios\Semanal as RelatorioSemanal;
use App\Livewire\Tempos\Calendario as TemposCalendario;
use App\Livewire\Tempos\Cronometro as TemposCronometro;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Toda a aplicação é interna: sessão da suite + acesso a esta aplicação dado no portal.
Route::middleware(['auth', 'acesso'])->group(function () {
    // Acompanhar: cronómetro e calendário (as horas de quem está a ver).
    Route::get('/', TemposCronometro::class)->name('cronometro');
    Route::get('/calendario', TemposCalendario::class)->name('calendario');

    Route::get('/painel', PainelPagina::class)->name('painel');

    Route::redirect('/relatorios', '/relatorios/resumo')->name('relatorios');
    Route::get('/relatorios/resumo', RelatorioResumo::class)->name('relatorios.resumo');
    Route::get('/relatorios/detalhado', RelatorioDetalhado::class)->name('relatorios.detalhado');
    Route::get('/relatorios/partilhados', RelatoriosPartilhados::class)->name('relatorios.partilhados');
    Route::get('/relatorios/presencas', RelatorioPresencas::class)->name('relatorios.presencas');
    Route::get('/relatorios/atribuicoes', RelatorioAtribuicoes::class)->name('relatorios.atribuicoes');
    Route::redirect('/relatorios/tarefas', '/relatorios/atribuicoes');
    Route::get('/relatorios/despesas', RelatorioDespesas::class)->name('relatorios.despesas');
    Route::get('/despesas/{despesa}/recibo', ReciboDespesaController::class)->name('despesas.recibo');
    Route::get('/relatorios/semanal', RelatorioSemanal::class)->name('relatorios.semanal');

    Route::get('/projetos', ProjetosListagem::class)->name('projetos');
    // Equipa: membros plenos (acesso dado no portal), limitados, grupos e lembretes.
    Route::get('/equipa', EquipaMembros::class)->name('equipa');
    Route::get('/equipa/limitados', EquipaLimitados::class)->name('equipa.limitados');
    Route::get('/equipa/grupos', EquipaGrupos::class)->name('equipa.grupos');
    Route::get('/equipa/lembretes', EquipaLembretes::class)->name('equipa.lembretes');
    Route::get('/clientes', ClientesListagem::class)->name('clientes');
});

// Relatórios partilhados por link: sem sessão obrigatória (os privados pedem-na dentro do componente).
Route::get('/partilhado/{token}', RelatorioPartilhado::class)
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:60,1')
    ->name('partilhado');

// SÓ EM DESENVOLVIMENTO: sem o portal a correr localmente não há como entrar. Esta rota só existe
// com APP_ENV=local E numa base descartável (tempos_dev) — nunca em produção nem nos testes.
if (app()->environment('local') && in_array(config('database.connections.pgsql.database'), AppServiceProvider::BASES_DESCARTAVEIS, true)) {
    Route::middleware('web')->group(function () {
        Route::get('/dev/entrar', fn () => response(
            '<h1>Entrar (desenvolvimento)</h1><ul>'.User::comAcessoAosTempos()->orderBy('nome')->get()
                ->map(fn (User $u) => '<li><a href="'.e(route('dev.entrar', $u)).'">'.e($u->nome).' — '.e($u->papelTempos()).'</a></li>')
                ->implode('').'</ul>'
        ))->name('dev.escolher');

        Route::get('/dev/entrar/{utilizador}', function (User $utilizador) {
            Auth::login($utilizador);
            request()->session()->regenerate();

            // ?ir=/caminho leva direto a uma página (útil para capturas de ecrã locais).
            $destino = (string) request()->query('ir', '/');

            return redirect(str_starts_with($destino, '/') && ! str_starts_with($destino, '//') ? $destino : '/');
        })->name('dev.entrar');
    });
}

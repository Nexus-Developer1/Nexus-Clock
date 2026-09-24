<?php

namespace App\Providers;

use App\Http\Middleware\ExigeAcessoAplicacao;
use App\Livewire\Relatorios\Partilhado;
use App\Mail\Transport\GraphTransport;
use App\Models\User;
use App\Services\Tempos\Faturacao\MesesFechados;
use App\Services\Tempos\ResolvedorTarifa;
use App\Support\LivewireSubpasta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    // Bases onde migrate:fresh / db:wipe podem correr. Todas as outras são (ou podem ser) a base
    // partilhada com a Nexus Infra e o portal — um migrate:fresh lá apagava TODAS as tabelas.
    public const BASES_DESCARTAVEIS = ['tempos_dev', 'tempos_testing'];

    public function register(): void
    {
        // Memória de tarifas por pedido (ou por job): começa vazia em cada um.
        $this->app->scoped(ResolvedorTarifa::class);
        $this->app->scoped(MesesFechados::class);
    }

    public function boot(): void
    {
        DB::prohibitDestructiveCommands(
            ! in_array(config('database.connections.'.config('database.default').'.database'), self::BASES_DESCARTAVEIS, true)
        );

        // Transporte de email 'graph' (Microsoft Graph, app-only), copiado da Nexus Infra. Lazy: só
        // corre quando o mailer 'graph' é usado. Credenciais em config/services.php (via env).
        Mail::extend('graph', function () {
            $c = config('services.microsoft_graph');

            return new GraphTransport($c['tenant_id'], $c['client_id'], $c['client_secret'], $c['sender']);
        });

        // Permissões dos tempos. O papel (admin/técnico) vem do portal (acessos.papel); reabrir exige
        // além disso estar na lista explícita config('tempos.pode_reabrir').
        Gate::define('tempos-ver-todos', fn (User $utilizador) => $utilizador->ehAdminTempos());
        Gate::define('tempos-editar-todos', fn (User $utilizador) => $utilizador->ehAdminTempos());
        Gate::define('tempos-fechar-mes', fn (User $utilizador) => $utilizador->ehAdminTempos());
        Gate::define('tempos-reabrir', fn (User $utilizador) => $utilizador->podeReabrirTempos());
        Gate::define('tempos-exportar', fn (User $utilizador) => $utilizador->ehAdminTempos());
        // Tarifas e horas incluídas dos contratos (condições que pesam na faturação e na margem).
        Gate::define('tempos-gerir-tarifas', fn (User $utilizador) => $utilizador->ehAdminTempos());
        // Clientes dos Tempos (página Clientes): criar, alterar, arquivar e apagar. Aberto a toda a
        // gente a pedido (2026-09-22, notas §33): os técnicos também precisam de acrescentar clientes.
        // Para voltar a fechar aos admins basta repor $utilizador->ehAdminTempos() aqui.
        Gate::define('tempos-gerir-clientes', fn (User $utilizador) => $utilizador->temAcesso());
        // Equipa: papéis, taxas (incluindo custo), membros limitados, grupos e lembretes. Os técnicos
        // veem a equipa sem taxas e sem ações.
        Gate::define('tempos-gerir-equipa', fn (User $utilizador) => $utilizador->ehAdminTempos());
        // Projetos: criar, alterar, arquivar e apagar. Os técnicos veem os públicos e os privados de
        // que são membros, e marcam favoritos.
        Gate::define('tempos-gerir-projetos', fn (User $utilizador) => $utilizador->ehAdminTempos());
        // Despesas: cada um lança as suas; quem gere lança para outros, aprova, rejeita e gere categorias.
        Gate::define('tempos-gerir-despesas', fn (User $utilizador) => $utilizador->ehAdminTempos());
        // Aprovar, rejeitar e voltar a pendente: só quem está em config('tempos.aprovam_despesas') — a
        // pedido, só o Paulo Gouveia; ser admin não chega (notas §44).
        Gate::define('tempos-aprovar-despesas', fn (User $utilizador) => $utilizador->temAcesso()
            && in_array(strtolower((string) $utilizador->email), config('tempos.aprovam_despesas'), true));
        // Ver as despesas de toda a equipa (só ver: não altera nem decide). Aberto a toda a gente a
        // pedido (2026-09-24, notas §41). Para voltar a ser só de quem gere: $utilizador->ehAdminTempos().
        Gate::define('tempos-ver-despesas', fn (User $utilizador) => $utilizador->temAcesso());
        // Relatório Atribuições da equipa toda — o agendado E as horas registadas de cada um nesses
        // projetos (coluna «Registado»). Aberto a toda a gente a pedido (2026-09-24, notas §43); só
        // neste relatório: nos de tempo cada técnico continua a ver as suas. Fechar: ehAdminTempos().
        Gate::define('tempos-ver-atribuicoes', fn (User $utilizador) => $utilizador->temAcesso());

        // No servidor a aplicação vive em /tempos, ao lado do portal: o Livewire tem de o saber.
        LivewireSubpasta::registar();

        // Relatório partilhado por link: só as ações da lista fechada (notas §40).
        Partilhado::registarListaDeAcoes();

        // As ações do Livewire vão por /livewire/update, que só reaplica os middleware da lista do
        // Livewire: sem isto, a quem se retirasse o acesso no portal, a página já aberta continuava a
        // funcionar (notas §42).
        Livewire::addPersistentMiddleware([ExigeAcessoAplicacao::class]);
    }
}

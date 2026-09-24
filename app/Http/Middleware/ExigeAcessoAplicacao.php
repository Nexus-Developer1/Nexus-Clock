<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem entra aqui tem de ter esta aplicação atribuída no portal.
 *
 * A sessão é partilhada por toda a suite, por isso não basta estar autenticado: é preciso que
 * alguém tenha dado acesso a ESTA aplicação. Quem não tiver é devolvido à página de escolha do
 * portal, em vez de levar com um erro seco.
 */
class ExigeAcessoAplicacao
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        // Sem sessão, é o middleware de autenticação que decide.
        if (! $utilizador) {
            return $next($request);
        }

        if (! $utilizador->temAcesso()) {
            // Nas ações do Livewire (este middleware é persistente — notas §42) um redirect viria parar
            // dentro da página; um 403 trava a ação, e ao recarregar a página vai-se ao portal.
            abort_if($request->hasHeader('X-Livewire'), 403);

            return redirect()->away(config('app.portal_url'));
        }

        return $next($request);
    }
}

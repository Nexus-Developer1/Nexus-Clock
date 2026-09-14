<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Termina a sessão de quem tenha a conta desativada.
 *
 * A verificação na entrada (no portal) não chega: quem tenha marcado "manter sessão iniciada"
 * volta a entrar pelo cookie. Assim, a desativação faz efeito no pedido seguinte.
 */
class GarantirContaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        if ($utilizador && ! $utilizador->ativo) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->away(config('app.portal_url'));
        }

        return $next($request);
    }
}

<?php

use App\Http\Middleware\CabecalhosSeguranca;
use App\Http\Middleware\ExigeAcessoAplicacao;
use App\Http\Middleware\GarantirContaActiva;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'acesso' => ExigeAcessoAplicacao::class,
        ]);

        // Quem ficar com a conta desativada é posto fora no pedido seguinte. Cabeçalhos de segurança
        // em todas as respostas (notas §52).
        $middleware->web(append: [GarantirContaActiva::class, CabecalhosSeguranca::class]);

        // Sem sessão, vai-se ao portal fazer o login (esta aplicação não tem login próprio).
        $middleware->redirectGuestsTo(fn () => config('app.portal_url'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

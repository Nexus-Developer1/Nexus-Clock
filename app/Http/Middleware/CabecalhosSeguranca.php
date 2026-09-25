<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Os mesmos cabeçalhos que o vhost dá ao portal e aos procedimentos, postos pela aplicação (o bloco
 * /tempos do vhost partilhado não os tinha — notas §52): nenhum outro site mostra o Suporte dentro de
 * uma moldura (o truque de levar alguém a carregar em «Aprovar» sem saber), o browser não adivinha
 * tipos de ficheiro, e o endereço completo não vai para sites de fora.
 */
class CabecalhosSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        $resposta->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $resposta->headers->set('X-Content-Type-Options', 'nosniff');
        $resposta->headers->set('Referrer-Policy', 'same-origin');

        return $resposta;
    }
}

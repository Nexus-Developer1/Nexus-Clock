<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Instalação por subpasta (APP_URL com caminho, ex.: https://infra.nexus-solutions.pt:9443/tempos).
 *
 * O Livewire escreve na página o endereço dos seus pedidos com `toRoute(..., absolute: false)`, que
 * retira a raiz do pedido — e a raiz inclui a subpasta — ficando `/livewire/update`, na raiz do host
 * (onde vive a Nexus Ops). Damos-lhe uma rota com o mesmo handler e a subpasta à frente, só para o
 * endereço sair certo (`/tempos/livewire/update`). Os pedidos continuam a entrar pela rota normal
 * do Livewire, que fica registada na mesma, porque o Laravel retira a subpasta antes de encaminhar.
 * Sem subpasta, não faz nada.
 */
class LivewireSubpasta
{
    public static function registar(?string $appUrl = null): void
    {
        $base = self::caminhoBase($appUrl ?? (string) config('app.url'));
        if ($base === '') {
            return;
        }

        app(HandleRequests::class)->setUpdateRoute(fn ($handle) => Route::post($base.'/livewire/update', $handle)
            ->middleware('web')
            ->name('subpasta.livewire.update'));
    }

    /** "tempos" para https://host/tempos; "" quando a aplicação está na raiz do host. */
    public static function caminhoBase(string $url): string
    {
        return trim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}

<?php

namespace Tests\Feature;

use App\Support\LivewireSubpasta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// No servidor a aplicação vive em /tempos (subpasta do host): o endereço dos pedidos do Livewire tem
// de levar a subpasta, senão os cliques vão parar à raiz do host (a Nexus Ops).
class LivewireSubpastaTest extends TestCase
{
    use RefreshDatabase;

    public function test_na_raiz_do_host_o_endereco_fica_como_o_livewire_o_faz(): void
    {
        $this->assertSame('', LivewireSubpasta::caminhoBase('http://localhost'));
        $this->assertSame('/livewire/update', app('livewire')->getUpdateUri());
    }

    public function test_em_subpasta_o_endereco_leva_a_subpasta_e_os_pedidos_continuam_a_entrar(): void
    {
        $this->assertSame('tempos', LivewireSubpasta::caminhoBase('https://infra.nexus-solutions.pt:9443/tempos'));

        LivewireSubpasta::registar('https://infra.nexus-solutions.pt:9443/tempos');

        $this->assertSame('/tempos/livewire/update', app('livewire')->getUpdateUri());

        // A rota normal do Livewire continua a existir (é por ela que os pedidos entram, já sem a subpasta).
        $this->actingAs($this->tecnico())->post('/livewire/update', [])->assertNotFound(); // sem componentes: 404 do Livewire, não 405/419
    }
}

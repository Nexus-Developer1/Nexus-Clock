<?php

namespace App\Livewire\Equipa;

use Livewire\Attributes\Layout;

/**
 * Equipa › Limitados: pessoas sem conta na suite (ex.: subcontratados), criadas aqui com nome e email,
 * com as mesmas taxas, papel e grupos dos membros plenos.
 */
#[Layout('components.layouts.app', ['ativo' => 'equipa', 'titulo' => 'Equipa'])]
class Limitados extends Membros
{
    protected bool $limitados = true;
}

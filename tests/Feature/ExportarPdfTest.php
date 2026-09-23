<?php

namespace Tests\Feature;

use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Exportar em PDF (menu Exportar dos relatórios): a mesma tabela do CSV num PDF a sério.
class ExportarPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_gera_um_pdf_valido_com_acentos_e_euros(): void
    {
        $this->actingAs($this->admin());

        $resposta = Pdf::resposta('despesas-20260914-20260920.pdf', 'Relatório Despesas', 'Esta semana (14/09/2026 – 20/09/2026)',
            ['Data', 'Membro', 'Nota', 'Valor (€)'],
            [['15/09/2026', 'João Conceição', 'Portagens — Maia', '13,20'], ['16/09/2026', 'Ana', 'Almoço em deslocação', '18,50']],
        );

        $this->assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('despesas-20260914-20260920.pdf', $resposta->headers->get('Content-Disposition'));

        ob_start();
        $resposta->sendContent();
        $conteudo = ob_get_clean();

        $this->assertStringStartsWith('%PDF-', $conteudo);
        $this->assertGreaterThan(1000, strlen($conteudo));
    }

    public function test_sem_linhas_tambem_gera(): void
    {
        $resposta = Pdf::resposta('resumo.pdf', 'Relatório Resumo', 'Esta semana', ['Projeto', 'Duração'], []);

        ob_start();
        $resposta->sendContent();

        $this->assertStringStartsWith('%PDF-', ob_get_clean());
    }
}

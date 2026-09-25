<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

// PDF de um relatório com a MESMA tabela que o CSV (cabeçalho + linhas), para imprimir ou mandar a
// um cliente: a marca, o nome do relatório, o período, quem gerou e quando, e a tabela. Paisagem
// quando há muitas colunas. O dompdf fica lento com milhares de linhas, por isso o PDF corta em
// MAXIMO_LINHAS e diz quantas ficaram de fora — para tudo, há o CSV.
final class Pdf
{
    public const MAXIMO_LINHAS = 2000;

    /**
     * @param  list<string>  $cabecalho
     * @param  iterable<list<string|int|float|null>>  $linhas
     */
    public static function resposta(string $ficheiro, string $titulo, string $periodo, array $cabecalho, iterable $linhas): StreamedResponse
    {
        LimiteExportacoes::verificar();

        $linhas = collect($linhas)->values();

        $pdf = DomPdf::loadView('pdf.relatorio', [
            'titulo' => $titulo,
            'periodo' => $periodo,
            'cabecalho' => $cabecalho,
            'linhas' => $linhas->take(self::MAXIMO_LINHAS),
            'cortadas' => max(0, $linhas->count() - self::MAXIMO_LINHAS),
            'autor' => auth()->user()?->nome,
            'geradoEm' => CarbonImmutable::now(config('tempos.fuso')),
        ])->setPaper('a4', count($cabecalho) > 5 ? 'landscape' : 'portrait')
            // Só as letras usadas vão no ficheiro (a fonte inteira fazia cada PDF pesar ~860 KB).
            ->setOption('isFontSubsettingEnabled', true);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $ficheiro, ['Content-Type' => 'application/pdf']);
    }
}

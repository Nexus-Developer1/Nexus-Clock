<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

// CSV para abrir diretamente no Excel em português: separador ";", UTF-8 com BOM (acentos certos)
// e horas decimais com vírgula (ver Horas::decimal).
final class Csv
{
    /**
     * @param  list<string>  $cabecalho
     * @param  iterable<list<string|int|null>>  $linhas
     */
    public static function resposta(string $ficheiro, array $cabecalho, iterable $linhas): StreamedResponse
    {
        return response()->streamDownload(function () use ($cabecalho, $linhas) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, $cabecalho, ';', '"', '');
            foreach ($linhas as $linha) {
                fputcsv($saida, $linha, ';', '"', '');
            }
            fclose($saida);
        }, $ficheiro, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

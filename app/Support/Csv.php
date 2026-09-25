<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

// CSV para abrir diretamente no Excel em português: separador ";", UTF-8 com BOM (acentos certos)
// e horas decimais com vírgula (ver Horas::decimal).
final class Csv
{
    /** Primeiros caracteres que fazem o Excel (ou o LibreOffice) ler a célula como fórmula. */
    private const INICIO_DE_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  list<string>  $cabecalho
     * @param  iterable<list<string|int|null>>  $linhas
     */
    public static function resposta(string $ficheiro, array $cabecalho, iterable $linhas): StreamedResponse
    {
        LimiteExportacoes::verificar();

        return response()->streamDownload(function () use ($cabecalho, $linhas) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, array_map(self::celula(...), $cabecalho), ';', '"', '');
            foreach ($linhas as $linha) {
                fputcsv($saida, array_map(self::celula(...), $linha), ';', '"', '');
            }
            fclose($saida);
        }, $ficheiro, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Neutraliza fórmulas (CSV injection, notas §45): um texto escrito por alguém — nota, descrição,
     * nome de cliente — começado por = + - @ seria executado pelo Excel ao abrir o ficheiro (por
     * exemplo, um HYPERLINK que manda o conteúdo da folha para fora). Leva um apóstrofo à frente e
     * o Excel mostra-o como texto. Os números — incluindo negativos, horas «-1:30:00» e valores
     * «-12,50» — ficam como estão, senão as colunas de contas deixavam de somar.
     */
    public static function celula(mixed $valor): mixed
    {
        if (! is_string($valor) || $valor === '' || preg_match('/^[+-]?[\d\s.,:]+$/u', $valor)) {
            return $valor;
        }

        return in_array($valor[0], self::INICIO_DE_FORMULA, true) ? "'".$valor : $valor;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\DespesaTempo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ZIP dos recibos, já montado pela página Despesas (notas §52). A página não o devolve pela ação do
 * Livewire — ia inteiro em base64 dentro da resposta, e um ZIP grande esgotava a memória: manda o
 * browser para aqui, por um link assinado que vale 10 minutos. O ZIP é só de quem o pediu (o id vai
 * no nome) e apaga-se depois de descarregado.
 */
class ZipRecibosController extends Controller
{
    public function __invoke(Request $request, string $ficheiro): BinaryFileResponse
    {
        abort_unless(str_starts_with($ficheiro, 'recibos-'.$request->user()->id.'-'), 404);

        $disco = Storage::disk(DespesaTempo::DISCO);
        $caminho = 'zips/'.$ficheiro.'.zip';
        abort_unless($disco->exists($caminho), 404);

        $nome = (string) $request->query('nome');

        return response()->download($disco->path($caminho), preg_match('/^recibos-\d{8}-\d{8}\.zip$/', $nome) ? $nome : 'recibos.zip')
            ->deleteFileAfterSend();
    }
}

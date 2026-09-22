<?php

namespace App\Http\Controllers;

use App\Models\DespesaTempo;
use App\Services\Tempos\GestorDespesas;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Recibo de uma despesa: só quem a lançou ou quem gere as despesas; os ficheiros estão no disco privado.
class ReciboDespesaController extends Controller
{
    public function __invoke(DespesaTempo $despesa, GestorDespesas $gestor): StreamedResponse
    {
        abort_unless($gestor->podeVer(request()->user(), $despesa), 403);
        $caminho = $gestor->caminhoRecibo($despesa);
        abort_unless($caminho, 404);

        return Storage::disk(DespesaTempo::DISCO)->download($caminho, $despesa->recibo_nome ?: basename($caminho));
    }
}

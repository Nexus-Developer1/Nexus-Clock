<?php

namespace App\Models;

use App\Casts\ListaInteirosPostgres;
use Illuminate\Database\Eloquent\Model;

// Lembrete por email a quem registou menos horas do que o mínimo no dia ou na semana anterior.
// Sai nos dias da semana escolhidos, à hora escolhida (Lisboa), para todos os membros plenos ou só
// para os de alguns grupos. Escrever através de Services\Tempos\GestorEquipa.
class LembreteEquipa extends Model
{
    protected $table = 'lembretes_equipa';

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const DIAS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    /** @var list<string> */
    protected $fillable = ['destinatarios', 'grupos', 'periodo', 'horas_minimas', 'dias', 'hora', 'ativo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dias' => ListaInteirosPostgres::class,
            'grupos' => ListaInteirosPostgres::class,
            'horas_minimas' => 'float',
            'hora' => 'integer',
            'ativo' => 'boolean',
            'enviado_em' => 'immutable_date',
        ];
    }
}

<?php

namespace App\Models;

use App\Casts\ListaTextoPostgres;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Relatório partilhado por link (página Partilhados). Escrever através de
// Services\Tempos\GestorPartilhados.
class RelatorioPartilhado extends Model
{
    protected $table = 'relatorios_partilhados';

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const FREQUENCIAS = ['diaria' => 'Todos os dias', 'semanal' => 'Todas as segundas-feiras', 'mensal' => 'No dia 1 de cada mês'];

    public const TIPOS = ['resumo' => 'Resumo'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'tipo' => 'resumo',
        'publico' => true,
        'sempre_atual' => true,
        'bloquear_datas' => false,
        'email_ativo' => false,
        'email_frequencia' => 'semanal',
        'email_hora' => 8,
        'email_destinatarios' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'publico' => 'boolean',
            'sempre_atual' => 'boolean',
            'bloquear_datas' => 'boolean',
            'parametros' => 'array',
            'email_ativo' => 'boolean',
            'email_hora' => 'integer',
            'email_destinatarios' => ListaTextoPostgres::class,
            'email_enviado_em' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function url(): string
    {
        return route('partilhado', $this->token);
    }

    /**
     * Período a mostrar: o guardado ou, se "sempre atual", o corrente do mesmo tipo.
     *
     * @return array{tipo: string, inicio: string, fim: string}
     */
    public function periodo(?CarbonImmutable $hoje = null): array
    {
        $p = $this->parametros;
        $tipo = $p['tipo'] ?? 'semana';
        if (! $this->sempre_atual || $tipo === 'datas') {
            return ['tipo' => $tipo, 'inicio' => (string) ($p['inicio'] ?? ''), 'fim' => (string) ($p['fim'] ?? '')];
        }

        $hoje ??= CarbonImmutable::parse(CarbonImmutable::now(config('tempos.fuso'))->toDateString());

        return ['tipo' => $tipo, 'inicio' => (match ($tipo) {
            'mes' => $hoje->startOfMonth(),
            'ano' => $hoje->startOfYear(),
            default => $hoje->startOfWeek(),
        })->toDateString(), 'fim' => ''];
    }

    /** Descrição do período para quem cria: "Esta semana", "Este mês"… ou as datas guardadas. */
    public function rotuloPeriodo(): string
    {
        $p = $this->parametros;
        $tipo = $p['tipo'] ?? 'semana';

        if ($this->sempre_atual && $tipo !== 'datas') {
            return match ($tipo) {
                'mes' => 'Este mês',
                'ano' => 'Este ano',
                default => 'Esta semana',
            };
        }

        $de = CarbonImmutable::parse($p['inicio']);
        $ate = match ($tipo) {
            'mes' => $de->endOfMonth(),
            'ano' => $de->endOfYear(),
            'datas' => CarbonImmutable::parse($p['fim']),
            default => $de->addDays(6),
        };

        return $de->format('d/m/Y').' – '.$ate->format('d/m/Y');
    }
}

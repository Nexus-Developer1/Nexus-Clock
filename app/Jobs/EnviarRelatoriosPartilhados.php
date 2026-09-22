<?php

namespace App\Jobs;

use App\Models\RelatorioPartilhado;
use App\Notifications\RelatorioPartilhadoEmail;
use App\Services\Tempos\ResumoTempos;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// De hora a hora: os relatórios partilhados com envio por email marcado para esta hora (Lisboa) e para
// hoje (todos os dias; às segundas; no dia 1), que ainda não saíram hoje, vão por email aos
// destinatários com os totais do período e o link. Só se o autor ainda tiver acesso aos Tempos.
class EnviarRelatoriosPartilhados implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ResumoTempos $resumo): void
    {
        $agora = CarbonImmutable::now(config('tempos.fuso'));
        $hoje = $agora->startOfDay();

        $devidos = RelatorioPartilhado::query()
            ->with('autor')
            ->where('email_ativo', true)
            ->where('email_hora', $agora->hour)
            ->where(fn ($q) => $q->whereNull('email_enviado_em')->orWhere('email_enviado_em', '<', $hoje->utc()))
            ->get()
            ->filter(fn (RelatorioPartilhado $r) => match ($r->email_frequencia) {
                'semanal' => $agora->isMonday(),
                'mensal' => $agora->day === 1,
                default => true,
            });

        foreach ($devidos as $r) {
            $autor = $r->autor;
            if (! $autor || ! $autor->ativo || ! $autor->acessoAEstaAplicacao() || $r->email_destinatarios === []) {
                continue;
            }

            ['tipo' => $tipo, 'inicio' => $inicio, 'fim' => $fim] = $r->periodo(CarbonImmutable::parse($hoje->toDateString()));
            $de = CarbonImmutable::parse($inicio);
            $ate = match ($tipo) {
                'mes' => $de->endOfMonth()->startOfDay(),
                'ano' => $de->endOfYear()->startOfDay(),
                'datas' => CarbonImmutable::parse($fim),
                default => $de->addDays(6),
            };

            $p = $r->parametros;
            $veEquipa = $autor->can('tempos-ver-todos');
            $dados = $resumo->gerar($autor, [
                'membros' => $veEquipa ? (($p['membros'] ?? []) === [] ? null : array_map('intval', $p['membros'])) : [$autor->id],
                'clientes' => array_map('intval', $p['clientes'] ?? []),
                'projetos' => array_map('intval', $p['projetos'] ?? []),
                'etiquetas' => $p['etiquetas'] ?? [],
                'estado' => $p['estado'] ?? '',
                'descricao' => $p['descricao'] ?? '',
            ], $de, $ate, $p['agrupar1'] ?? 'projeto', null);

            $comValor = $veEquipa && ($p['mostrarValor'] ?? 'faturavel') === 'faturavel';

            Notification::route('mail', $r->email_destinatarios)->notify(new RelatorioPartilhadoEmail(
                nome: $r->nome,
                autor: $autor->nome,
                periodo: $de->format('d/m/Y').' – '.$ate->format('d/m/Y'),
                total: $dados['total'],
                faturavel: $dados['faturavel'],
                valor: $comValor ? $dados['valor'] : null,
                agrupamento: ResumoTempos::AGRUPAMENTOS[$p['agrupar1'] ?? 'projeto'] ?? 'Projeto',
                grupos: array_map(fn ($g) => ['nome' => $g['nome'], 'segundos' => $g['segundos']], array_slice($dados['grupos'], 0, 8)),
                url: $r->url(),
            ));

            $r->forceFill(['email_enviado_em' => now()])->save();
            Log::info('Relatório partilhado enviado por email.', ['relatorio' => $r->id, 'destinatarios' => count($r->email_destinatarios)]);
        }
    }
}

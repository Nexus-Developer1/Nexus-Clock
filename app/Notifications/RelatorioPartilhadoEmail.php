<?php

namespace App\Notifications;

use App\Services\Tempos\PainelTempos;
use App\Support\Dinheiro;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Relatório partilhado enviado por email (agendamento): totais do período, os grupos principais e o
// link. Pela FILA, com o aspeto dos emails da Nexus Infra.
class RelatorioPartilhadoEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{nome: string, segundos: int}>  $grupos
     */
    public function __construct(
        public string $nome,
        public string $autor,
        public string $periodo,
        public int $total,
        public int $faturavel,
        public ?int $valor,
        public string $agrupamento,
        public array $grupos,
        public string $url,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->nome.' · '.$this->periodo)
            ->view('emails.relatorio-partilhado', [
                'nome' => $this->nome,
                'autor' => $this->autor,
                'periodo' => $this->periodo,
                'total' => PainelTempos::hms($this->total),
                'faturavel' => PainelTempos::hms($this->faturavel),
                'valor' => $this->valor === null ? null : Dinheiro::formatar($this->valor),
                'agrupamento' => $this->agrupamento,
                'grupos' => array_map(fn ($g) => ['nome' => $g['nome'], 'horas' => PainelTempos::hms($g['segundos'])], $this->grupos),
                'url' => $this->url,
            ]);
    }
}

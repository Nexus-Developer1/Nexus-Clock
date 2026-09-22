<?php

namespace App\Notifications;

use App\Services\Tempos\LeitorDuracao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Lembrete da equipa: registou menos horas do que o mínimo no período. Pela FILA, com o aspeto dos
// emails da Nexus Infra.
class LembreteHoras extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $periodo  ex.: "dia 14/09" ou "semana de 07/09 a 13/09"
     */
    public function __construct(public string $periodo, public int $segundos, public int $minimoSeg) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Horas por registar · '.$this->periodo)
            ->view('emails.lembrete-horas', [
                'nome' => $notifiable->nome ?? null,
                'periodo' => $this->periodo,
                'horas' => LeitorDuracao::formatar($this->segundos) ?: '0:00',
                'minimo' => LeitorDuracao::formatar($this->minimoSeg),
                'url' => route('cronometro'),
            ]);
    }
}

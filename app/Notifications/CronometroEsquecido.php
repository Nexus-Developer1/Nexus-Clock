<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Cronómetro a correr há demasiado tempo (notas §61): pela FILA, com o aspeto dos outros emails do Suporte.
class CronometroEsquecido extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CarbonInterface $inicio, public ?string $descricao = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fuso = config('tempos.fuso');
        $inicio = CarbonImmutable::instance($this->inicio)->setTimezone($fuso);
        $hoje = CarbonImmutable::now($fuso)->startOfDay();
        $horas = (int) floor($inicio->diffInMinutes(CarbonImmutable::now($fuso), true) / 60);

        return (new MailMessage)
            ->subject('O seu cronómetro está a correr há '.$horas.' horas')
            ->view('emails.cronometro-esquecido', [
                'nome' => $notifiable->nome ?? null,
                'quando' => match (true) {
                    $inicio->isSameDay($hoje) => 'hoje',
                    $inicio->isSameDay($hoje->subDay()) => 'ontem',
                    default => 'dia '.$inicio->format('d/m'),
                },
                'hora' => $inicio->format('H:i'),
                'horas' => $horas,
                'descricao' => $this->descricao,
                'url' => route('cronometro'),
            ]);
    }
}

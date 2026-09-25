<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pedido de aprovação de uma despesa do Suporte, para quem aprova (config tempos.aprovam_despesas).
 * Quem aprova recebe também os pedidos do IFE, parecidos: por isso o assunto começa por «Suporte»,
 * a referência é SUP-<nº> (os números das duas aplicações repetem-se) e o corpo diz em destaque
 * que é uma despesa do Nexus Suporte (notas §44). Leva uma fotografia da despesa, não o modelo: o email
 * mostra o que foi submetido, mesmo que a despesa mude antes de a fila o enviar.
 */
class DespesaPorAprovar extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array{id: int, referencia: string, membro: string, por?: ?string, data: string, valor: string, faturavel: bool, projeto: ?string, cliente: ?string, categoria: string, nota: string, recibo: bool, url: string} $despesa */
    public function __construct(public array $despesa, public bool $reenvio = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->despesa;

        return (new MailMessage)
            ->subject('Suporte · Despesa '.$d['referencia'].' · '.$d['membro'].' · '.$d['valor'].($this->reenvio ? ' — corrigida, para aprovar' : ' — para aprovar'))
            ->view('emails.despesa-por-aprovar', ['d' => $d, 'reenvio' => $this->reenvio]);
    }
}

<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

// Transporte de email via Microsoft Graph (client-credentials / app-only). Chama a API REST
// oficial do Graph com o cliente HTTP do Laravel; envia como a mailbox configurada
// (MS_GRAPH_SENDER). Todas as credenciais vêm de config('services.microsoft_graph'), que por
// sua vez lê env() — nenhum segredo aqui. O token é cacheado até (quase) expirar.
class GraphTransport extends AbstractTransport
{
    private const URL_TOKEN = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const URL_SENDMAIL = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private const CHAVE_CACHE_TOKEN = 'ms_graph_token';

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $sender,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = $this->construirPayload($email, $message->getEnvelope());

        $resposta = Http::withToken($this->obterToken())
            ->acceptJson()
            ->post(sprintf(self::URL_SENDMAIL, $this->sender), $payload);

        if ($resposta->failed()) {
            throw new RuntimeException('Falha ao enviar email via Microsoft Graph: '.$resposta->status().' '.$resposta->body());
        }
    }

    /**
     * Constrói o payload de sendMail do Graph a partir de um email Symfony
     * (assunto, corpo, destinatários e anexos como fileAttachment).
     *
     * @return array<string, mixed>
     */
    public function construirPayload(Email $email, Envelope $envelope): array
    {
        $endereco = fn (Address $a) => ['emailAddress' => ['address' => $a->getAddress()]];

        $mensagem = [
            'subject' => (string) $email->getSubject(),
            'body' => [
                'contentType' => 'HTML',
                'content' => (string) ($email->getHtmlBody() ?? $email->getTextBody() ?? ''),
            ],
            'toRecipients' => array_map($endereco, $email->getTo()),
        ];

        if ($cc = $email->getCc()) {
            $mensagem['ccRecipients'] = array_map($endereco, $cc);
        }
        if ($bcc = $email->getBcc()) {
            $mensagem['bccRecipients'] = array_map($endereco, $bcc);
        }

        $anexos = [];
        foreach ($email->getAttachments() as $anexo) {
            $anexos[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $anexo->getFilename() ?? 'anexo',
                'contentType' => $anexo->getContentType(),
                'contentBytes' => base64_encode($anexo->getBody()),
            ];
        }
        if ($anexos !== []) {
            $mensagem['attachments'] = $anexos;
        }

        return ['message' => $mensagem, 'saveToSentItems' => false];
    }

    // Token app-only (client credentials), cacheado até quase expirar.
    private function obterToken(): string
    {
        if ($token = Cache::get(self::CHAVE_CACHE_TOKEN)) {
            return $token;
        }

        $resposta = Http::asForm()->post(sprintf(self::URL_TOKEN, $this->tenantId), [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
            'scope' => 'https://graph.microsoft.com/.default',
        ]);

        if ($resposta->failed()) {
            throw new RuntimeException('Falha a obter token do Microsoft Graph: '.$resposta->status().' '.$resposta->body());
        }

        $token = (string) $resposta->json('access_token');
        $expira = (int) $resposta->json('expires_in', 3600);
        Cache::put(self::CHAVE_CACHE_TOKEN, $token, now()->addSeconds(max(60, $expira - 60)));

        return $token;
    }

    public function __toString(): string
    {
        return 'graph';
    }
}

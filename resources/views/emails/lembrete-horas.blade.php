<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horas por registar</title>
</head>
{{-- Lembrete da equipa — mesmo layout dos emails da Nexus Infra: barra de cor, marca, texto e botão
     verde. Recebe $nome, $periodo, $horas, $minimo e $url. --}}
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                    <tr><td style="height:6px; line-height:6px; font-size:0; background-color:#a16207;">&nbsp;</td></tr>

                    <tr>
                        <td style="padding:28px 36px 6px;">
                            <div style="font-size:22px; font-weight:800; color:#16a34a; line-height:1;">Nexus Suporte</div>
                            <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#9ca3af; margin-top:3px;">Registo de horas e despesas</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 36px 4px;">
                            <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#111827;">{{ $nome ? 'Olá '.$nome.',' : 'Olá,' }}</h1>
                            <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">
                                No {{ $periodo }} registou <strong style="color:#111827;">{{ $horas }}</strong> horas, menos do que as <strong style="color:#111827;">{{ $minimo }}</strong> esperadas.
                            </p>
                            <p style="margin:0 0 22px; font-size:15px; line-height:1.6; color:#374151;">
                                Se faltam horas por registar, aproveite para as acrescentar.
                            </p>

                            @include('emails._botao', ['url' => $url, 'texto' => 'Abrir o Suporte'])
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 36px 28px; font-size:12px; line-height:1.5; color:#9ca3af;">
                            Lembrete automático configurado na página Equipa.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

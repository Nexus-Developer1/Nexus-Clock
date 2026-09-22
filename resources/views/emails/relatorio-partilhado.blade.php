<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $nome }}</title>
</head>
{{-- Relatório partilhado (envio agendado) — mesmo layout dos emails da Nexus Infra. Recebe $nome,
     $autor, $periodo, $total, $faturavel, $valor (ou null), $agrupamento, $grupos e $url. --}}
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">
                    <tr><td style="height:6px; line-height:6px; font-size:0; background-color:#16a34a;">&nbsp;</td></tr>

                    <tr>
                        <td style="padding:28px 36px 6px;">
                            <div style="font-size:22px; font-weight:800; color:#16a34a; line-height:1;">Nexus Infra</div>
                            <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#9ca3af; margin-top:3px;">Suporte</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 36px 4px;">
                            <h1 style="margin:0 0 4px; font-size:20px; font-weight:600; color:#111827;">{{ $nome }}</h1>
                            <p style="margin:0 0 20px; font-size:14px; color:#6b7280;">{{ $periodo }} · partilhado por {{ $autor }}</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px; border:1px solid #e5e7eb; border-radius:10px;">
                                <tr>
                                    <td style="padding:12px 16px;">
                                        <div style="font-size:12px; color:#6b7280;">Total</div>
                                        <div style="font-size:20px; font-weight:600; color:#111827;">{{ $total }}</div>
                                    </td>
                                    <td style="padding:12px 16px;">
                                        <div style="font-size:12px; color:#6b7280;">Faturável</div>
                                        <div style="font-size:16px; font-weight:600; color:#111827;">{{ $faturavel }}</div>
                                    </td>
                                    @if ($valor !== null)
                                        <td style="padding:12px 16px;">
                                            <div style="font-size:12px; color:#6b7280;">Valor</div>
                                            <div style="font-size:16px; font-weight:600; color:#111827;">{{ $valor }}</div>
                                        </td>
                                    @endif
                                </tr>
                            </table>

                            @if ($grupos !== [])
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px; font-size:14px;">
                                    <tr>
                                        <td style="padding:0 0 6px; font-size:12px; color:#6b7280;">{{ $agrupamento }}</td>
                                        <td style="padding:0 0 6px; font-size:12px; color:#6b7280; text-align:right;">Duração</td>
                                    </tr>
                                    @foreach ($grupos as $g)
                                        <tr>
                                            <td style="padding:7px 0; border-top:1px solid #f1f5f9; color:#111827;">{{ $g['nome'] }}</td>
                                            <td style="padding:7px 0; border-top:1px solid #f1f5f9; color:#111827; text-align:right; white-space:nowrap;">{{ $g['horas'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @include('emails._botao', ['url' => $url, 'texto' => 'Abrir o relatório'])
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 36px 28px; font-size:12px; line-height:1.5; color:#9ca3af;">
                            Envio automático de um relatório partilhado no Suporte.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

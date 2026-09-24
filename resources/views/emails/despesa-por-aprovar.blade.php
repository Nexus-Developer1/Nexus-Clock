<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Despesa {{ $d['referencia'] }} para aprovar</title>
</head>
{{-- Pedido de aprovação de uma despesa do Suporte (App\Notifications\DespesaPorAprovar). O mesmo layout
     dos outros emails, mas com a marca do Suporte bem visível e em destaque que é do Suporte — quem
     aprova recebe pedidos parecidos das duas aplicações (notas §44). --}}
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                    <tr><td style="height:6px; line-height:6px; font-size:0; background-color:#16a34a;">&nbsp;</td></tr>

                    <tr>
                        <td style="padding:28px 36px 6px;">
                            <div style="font-size:22px; font-weight:800; color:#16a34a; line-height:1;">Nexus Suporte</div>
                            <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#9ca3af; margin-top:3px;">Registo de horas e despesas</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 36px 0;">
                            <div style="background-color:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 14px; font-size:14px; line-height:1.5; color:#166534;">
                                Esta despesa é do <strong>Nexus Suporte</strong>. Aprova-se no Suporte, pelo botão abaixo.
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 36px 4px;">
                            <h1 style="margin:0 0 6px; font-size:20px; font-weight:600; color:#111827;">
                                Despesa {{ $d['referencia'] }} {{ $reenvio ? 'corrigida — ' : '' }}para aprovar
                            </h1>
                            <p style="margin:0 0 18px; font-size:15px; line-height:1.6; color:#374151;">
                                @if ($reenvio)
                                    {{ $d['membro'] }} corrigiu uma despesa que tinha sido rejeitada.
                                @else
                                    {{ $d['membro'] }} lançou uma despesa que precisa da sua aprovação.
                                @endif
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; line-height:1.5; color:#111827; border-top:1px solid #e5e7eb;">
                                @foreach ([
                                    'Referência' => $d['referencia'].' (Nexus Suporte)',
                                    'Membro' => $d['membro'],
                                    'Data' => $d['data'],
                                    'Valor' => $d['valor'].($d['faturavel'] ? ' · faturável ao cliente' : ' · não faturável'),
                                    'Projeto' => $d['projeto'] ? $d['projeto'].($d['cliente'] ? ' · '.$d['cliente'] : '') : 'Sem projeto',
                                    'Categoria' => $d['categoria'],
                                    'Nota' => $d['nota'] !== '' ? $d['nota'] : '—',
                                    'Recibo' => $d['recibo'] ? 'Sim (no Suporte)' : 'Sem recibo',
                                ] as $rotulo => $valor)
                                    {{-- Sem white-space:pre-line: o Gmail mete uma quebra no início de cada célula, que
                                         com pre-line aparecia e descia o valor uma linha. As quebras da nota vão por <br>. --}}
                                    <tr>
                                        <td width="120" valign="top" style="padding:8px 12px 8px 0; width:120px; color:#6b7280; vertical-align:top; border-bottom:1px solid #f3f4f6;">{{ $rotulo }}</td>
                                        <td valign="top" style="padding:8px 0; vertical-align:top; border-bottom:1px solid #f3f4f6;">{!! nl2br(e($valor), false) !!}</td>
                                    </tr>
                                @endforeach
                            </table>

                            <div style="height:22px; line-height:22px; font-size:0;">&nbsp;</div>
                            @include('emails._botao', ['url' => $d['url'], 'texto' => 'Abrir no Nexus Suporte'])
                            <div style="height:28px; line-height:28px; font-size:0;">&nbsp;</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

{{-- Botão dos emails, à prova de Outlook.
     O Outlook (motor do Word) ignora border-radius e trata o padding do <a> à sua maneira — o
     botão saía um retângulo grande e esquadrado. A forma fiável é desenhá-lo em VML só para o
     Outlook (v:roundrect) e manter o HTML normal para todos os outros clientes.
     Recebe: $url, $texto e (opcional) $cor. --}}
@php
    $cor = $cor ?? '#16a34a';
    // Largura em pixéis para o VML (o Outlook precisa de medida fixa): ~8px por caracter + folga.
    $largura = max(150, 44 + mb_strlen($texto) * 9);
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
    <tr><td>
        <!--[if mso]>
        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:42px; v-text-anchor:middle; width:{{ $largura }}px;" arcsize="24%" stroke="f" fillcolor="{{ $cor }}">
            <w:anchorlock/>
            <center style="color:#ffffff; font-family:Arial,sans-serif; font-size:14px; font-weight:bold;">{{ $texto }}</center>
        </v:roundrect>
        <![endif]-->
        <!--[if !mso]><!-- -->
        <a href="{{ $url }}" target="_blank" style="display:inline-block; background-color:{{ $cor }}; border-radius:10px; padding:12px 22px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;"><span style="color:#ffffff;">{{ $texto }}</span></a>
        <!--<![endif]-->
    </td></tr>
</table>

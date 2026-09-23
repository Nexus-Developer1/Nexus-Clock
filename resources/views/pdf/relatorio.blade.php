{{-- PDF de um relatório (App\Support\Pdf): a mesma tabela do CSV, com a marca e o período.
     HTML simples para o dompdf: sem Tailwind nem imagens, fonte DejaVu Sans (acentos e €). --}}
@php
    // Números, horas e valores alinham à direita.
    $numero = fn ($v) => is_int($v) || is_float($v) || (is_string($v) && $v !== '' && preg_match('/^-?[\d\s.,:]+$/u', $v));
@endphp
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }} — Nexus Suporte</title>
    <style>
        @page { margin: 18mm 14mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #111827; }
        .topo { border-bottom: 2px solid #16a34a; padding-bottom: 8px; margin-bottom: 14px; }
        .marca { font-size: 15pt; font-weight: bold; color: #16a34a; letter-spacing: 1px; }
        .marca span { font-size: 7pt; color: #6b7280; letter-spacing: 3px; margin-left: 4px; }
        h1 { font-size: 13pt; margin: 8px 0 2px; }
        .meta { color: #6b7280; font-size: 8pt; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f3f4f6; text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .3px; color: #4b5563; padding: 6px 6px; border-bottom: 1px solid #d1d5db; }
        td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        tr:nth-child(even) td { background: #fafafa; }
        .direita { text-align: right; white-space: nowrap; }
        .aviso { margin-top: 10px; color: #b45309; font-size: 8pt; }
        .vazio { padding: 24px 0; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <div class="topo">
        <div class="marca">NEXUS<span>SUPORTE</span></div>
        <h1>{{ $titulo }}</h1>
        <div class="meta">{{ $periodo }} · gerado em {{ $geradoEm->format('d/m/Y H:i') }}@if ($autor) por {{ $autor }}@endif</div>
    </div>

    @if ($linhas->isEmpty())
        <div class="vazio">Sem dados neste período.</div>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($cabecalho as $c)
                        <th>{{ $c }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($linhas as $linha)
                    <tr>
                        @foreach ($linha as $v)
                            <td class="{{ $numero($v) ? 'direita' : '' }}">{{ $v }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($cortadas > 0)
        <p class="aviso">Mostradas as primeiras {{ number_format($linhas->count(), 0, ',', ' ') }} linhas; ficaram {{ number_format($cortadas, 0, ',', ' ') }} de fora. Para tudo, exporte em CSV.</p>
    @endif
</body>
</html>

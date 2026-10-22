@php([$layoutLabel, $cols, $rows, $w, $h] = \App\Domain\Catalog\Labels::LAYOUTS[$layout])
@php($roll = $rows === 0)
@php($gap = $cols > 1 ? 2.5 : 0)
@php($side = $roll ? 0 : max(0, (210 - $cols * $w - ($cols - 1) * $gap) / 2))
@php($top = $roll ? 0 : max(0, (297 - $rows * $h) / 2))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Labels</title>
    <style>
        @page { size: {{ $roll ? "{$w}mm {$h}mm" : 'A4' }}; margin: {{ $roll ? '0' : "{$top}mm {$side}mm" }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #000; background: #fff; }
        .sheet { display: grid; grid-template-columns: repeat({{ $cols }}, {{ $w }}mm); grid-auto-rows: {{ $h }}mm; column-gap: {{ $gap }}mm; }
        .label { width: {{ $w }}mm; height: {{ $h }}mm; padding: 1.5mm 2mm; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; break-inside: avoid; }
        @if ($roll) .label { break-after: page; } @endif
        .name { font-weight: 700; line-height: 1.1; font-size: {{ $h < 25 ? '6.5pt' : '8pt' }}; max-height: 2.3em; overflow: hidden; }
        .price { font-weight: 800; font-size: {{ $kind === 'shelf' ? ($h < 25 ? '11pt' : '16pt') : ($h < 25 ? '7pt' : '9pt') }}; }
        .meta { font-size: 6pt; color: #333; display: flex; justify-content: space-between; gap: 4px; }
        .code svg { width: 100%; height: {{ $kind === 'barcode' ? ($h * 0.45) : ($h * 0.28) }}mm; display: block; }
        .digits { font-size: 6pt; text-align: center; letter-spacing: .5px; }
        .nocode { font-size: 6pt; color: #b45309; }
        .bar { padding: 10px 14px; background: #f1f5f9; font: 14px system-ui; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .bar button, .bar a { padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #000; text-decoration: none; font: inherit; cursor: pointer; }
        .bar .primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        @media print { .bar { display: none; } }
        @media screen { .sheet { padding: {{ $roll ? '8px' : "{$top}mm {$side}mm" }}; } .label { outline: 1px dashed #cbd5e1; } }
    </style>
</head>
<body>
<div class="bar">
    <button class="primary" onclick="window.print()">Print</button>
    <span>{{ count($labels) }} label(s) · {{ $layoutLabel }}. In the print window set <strong>Margins: none</strong> and <strong>Scale: 100%</strong>{{ $roll ? ', and choose the label printer' : '' }}. Try one sheet on plain paper first.</span>
    <a href="{{ url()->previous() }}">Back</a>
</div>
<div class="sheet">
    @for ($i = 0; $i < $skip; $i++)<div class="label"></div>@endfor
    @foreach ($labels as $l)
        <div class="label">
            @if ($kind === 'shelf')
                <div class="name">{{ $l['name'] }}</div>
                <div class="price">{{ $l['price'] ?? '—' }} <span class="meta" style="display:inline">/ {{ $l['unit'] }}</span></div>
            @else
                <div class="name">{{ $l['name'] }}</div>
            @endif
            @if ($l['barcode'])
                <div class="code">{!! \App\Domain\Catalog\Labels::svg($l['barcode'], 40) !!}<div class="digits">{{ $l['barcode'] }}</div></div>
            @else
                <div class="nocode">No barcode — {{ $l['sku'] }}</div>
            @endif
            <div class="meta"><span>{{ $l['sku'] }}{{ $l['location'] ? ' · '.$l['location'] : '' }}</span>@if ($kind === 'barcode')<strong>{{ $l['price'] }}</strong>@endif</div>
        </div>
    @endforeach
</div>
</body>
</html>

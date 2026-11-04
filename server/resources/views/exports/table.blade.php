<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 14mm 12mm 16mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #1d1d1b; }
        .head { border-bottom: 1.5px solid #1d1d1b; padding-bottom: 4px; margin-bottom: 8px; }
        .company { font-size: 9pt; font-weight: bold; }
        h1 { font-size: 13pt; margin: 2px 0; }
        h2 { font-size: 10pt; margin: 12px 0 4px; }
        .meta { color: #6b6b66; font-size: 7pt; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #eef2f5; text-align: left; font-size: 7pt; text-transform: uppercase; padding: 3px 4px; border-bottom: 1px solid #c8ced3; }
        td { padding: 3px 4px; border-bottom: 0.5px solid #dcdcdc; vertical-align: top; }
        tr:nth-child(even) td { background: #fafafa; }
        .num { text-align: right; white-space: nowrap; }
        .note { margin: 4px 0; font-size: 7.5pt; }
        .warn { color: #b42318; }
        .ref { position: fixed; bottom: -8mm; left: 0; font-size: 6pt; color: #b5b5b0; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        tfoot td { font-weight: bold; border-top: 1px solid #1d1d1b; border-bottom: 0; background: #fff; }
        .tiles { margin: 6px 0 4px; border-collapse: separate; border-spacing: 4px 0; }
        .tiles td { border: 1px solid #c8ced3; border-radius: 4px; padding: 5px 7px; background: #fff; width: 25%; }
        .tiles tr td { background: #fff; }
        .tiles .label { font-size: 6.5pt; text-transform: uppercase; color: #6b6b66; }
        .tiles .value { font-size: 11pt; font-weight: bold; white-space: nowrap; }
        .tiles .lead { background: #1d1d1b !important; color: #fff; border-color: #1d1d1b; }
        .tiles .lead .label { color: #d6d6d0; }
        .tiles .lead .value { font-size: 15pt; }
        .tiles .good .value { color: #067647; } .tiles .warn .value { color: #b54708; } .tiles .bad .value { color: #b42318; }
        .facts { margin: 6px 0 2px; }
        .facts td { border: 0; padding: 1.5px 4px; background: #fff !important; }
        .facts .k { color: #6b6b66; width: 16%; white-space: nowrap; }
        .sign { margin-top: 26px; }
        .sign td { border: 0; background: #fff !important; padding: 0 10px 0 0; width: 33%; }
        .sign .line { border-top: 0.8px solid #1d1d1b; padding-top: 3px; font-size: 7pt; color: #6b6b66; }
    </style>
</head>
<body>
<div class="ref">Ref {{ $ref }}@if (! empty($powered)) · {{ $powered }}@endif</div>
<div class="head">
    @if (! empty($logo))<img src="{{ $logo }}" alt="" style="float:right;max-height:34px;max-width:160px">@endif
    <div class="company">{{ $company }}</div>
    @if (! empty($intro['branch']))<div class="meta" style="font-size:7.5pt;color:#1d1d1b">{{ $intro['branch'] }}</div>@endif
    <h1>{{ $title }}</h1>
    <div class="meta">Printed {{ $at->format('j M Y, H:i') }} by {{ $by }}@if ($filters) · {{ implode(' · ', $filters) }}@endif</div>
</div>
@if ($truncated)
    <p class="note warn">Only the first {{ number_format(\App\Support\Exports\Exporter::PDF_ROW_LIMIT) }} rows of each table are in this PDF. Download the CSV for everything, or narrow the filters.</p>
@endif
@if (! empty($intro['tiles']))
    @foreach (array_chunk($intro['tiles'], 4) as $n => $row)
        <table class="tiles"><tr>
            @foreach ($row as $i => $t)
                <td class="{{ $n === 0 && $i === 0 ? 'lead' : '' }} {{ $t['tone'] ?? '' }}"><div class="label">{{ $t['label'] }}</div><div class="value">{{ $t['value'] }}</div></td>
            @endforeach
            @for ($i = count($row); $i < 4; $i++)<td style="border:0"></td>@endfor
        </tr></table>
    @endforeach
@endif
@if (! empty($intro['facts']))
    <table class="facts">
        @foreach (array_chunk($intro['facts'], 2, true) as $pair)
            <tr>@foreach ($pair as $k => $v)<td class="k">{{ $k }}</td><td>{{ $v }}</td>@endforeach @if (count($pair) < 2)<td></td><td></td>@endif</tr>
        @endforeach
    </table>
@endif
@foreach ($sheets as $sheet)
    @if (count($sheets) > 1 || ($sheet['title'] ?? '') !== $title)<h2>{{ $sheet['title'] }}</h2>@endif
    @if (! empty($sheet['note']))<p class="note">{{ $sheet['note'] }}</p>@endif
    <table>
        <thead><tr>@foreach ($sheet['headings'] as $i => $h)<th class="{{ in_array($i, $sheet['numeric'], true) ? 'num' : '' }}">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($sheet['rows'] as $row)
            <tr>@foreach ($row as $i => $v)<td class="{{ in_array($i, $sheet['numeric'], true) ? 'num' : '' }}">{{ $v }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ max(1, count($sheet['headings'])) }}">Nothing to show.</td></tr>
        @endforelse
        </tbody>
        @if (! empty($sheet['foot']))
            <tfoot><tr>@foreach (array_values($sheet['foot']) as $i => $v)<td class="{{ in_array($i, $sheet['numeric'], true) ? 'num' : '' }}">{{ $v }}</td>@endforeach</tr></tfoot>
        @endif
    </table>
@endforeach
@if (! empty($intro['sign']))
    <table class="sign"><tr>@foreach ($intro['sign'] as $who)<td><div class="line">{{ $who }}</div></td>@endforeach</tr></table>
@endif
</body>
</html>

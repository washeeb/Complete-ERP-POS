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
    </style>
</head>
<body>
<div class="ref">Ref {{ $ref }}@if (! empty($powered)) · {{ $powered }}@endif</div>
<div class="head">
    @if (! empty($logo))<img src="{{ $logo }}" alt="" style="float:right;max-height:34px;max-width:160px">@endif
    <div class="company">{{ $company }}</div>
    <h1>{{ $title }}</h1>
    <div class="meta">Printed {{ $at->format('j M Y, H:i') }} by {{ $by }}@if ($filters) · {{ implode(' · ', $filters) }}@endif</div>
</div>
@if ($truncated)
    <p class="note warn">Only the first {{ number_format(\App\Support\Exports\Exporter::PDF_ROW_LIMIT) }} rows of each table are in this PDF. Download the CSV for everything, or narrow the filters.</p>
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
    </table>
@endforeach
</body>
</html>

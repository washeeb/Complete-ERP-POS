<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    {!! \App\Domain\Licensing\Watermark::meta() !!}
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 11pt/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1d1d1b; background: #fff; }
        .page { max-width: 190mm; margin: 0 auto; padding: 12px; }
        header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #1d1d1b; padding-bottom: 10px; margin-bottom: 14px; }
        h1 { font-size: 18pt; margin: 0 0 4px; letter-spacing: .5px; }
        .muted { color: #6b6b66; } .right { text-align: right; } .small { font-size: 9pt; }
        .parties { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { font-size: 9pt; text-transform: uppercase; color: #6b6b66; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot th, tfoot td { border-bottom: 0; }
        .total { font-size: 13pt; font-weight: 700; }
        .box { border: 1px solid #ddd; border-radius: 6px; padding: 8px 10px; }
        .no-print { margin: 12px 0; }
        .wm { margin-top: 18px; font-size: 6pt; color: #b5b5b0; letter-spacing: .5px; }
        @media print { .no-print { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
<div class="page">
    <p class="no-print"><button onclick="window.print()">Print / Save as PDF</button></p>
    <header>
        <div>
            <h1>{{ \App\Domain\Licensing\Watermark::stamp($company['name']) }}</h1>
            <div class="small">{{ $company['address'] }} @if ($company['phone']) · {{ $company['phone'] }} @endif</div>
            <div class="small">@if ($company['tin']) TIN {{ $company['tin'] }} @endif @if ($company['vat_no']) · VAT {{ $company['vat_no'] }} @endif</div>
        </div>
        <div class="right">@yield('heading')</div>
    </header>
    @yield('content')
    <p class="wm">Ref {{ \App\Domain\Licensing\Watermark::reference() }}</p>
</div>
</body>
</html>

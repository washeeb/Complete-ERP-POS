<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 11pt/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1d1d1b; background: #fff; }
        .page { max-width: 190mm; margin: 0 auto; padding: 12px; }
        header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 3px solid #4338ca; padding-bottom: 10px; margin-bottom: 16px; }
        h1 { font-size: 20pt; margin: 0 0 4px; letter-spacing: .5px; }
        h2 { font-size: 12pt; margin: 18px 0 6px; color: #4338ca; }
        .muted { color: #6b6b66; } .small { font-size: 9pt; } .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { font-size: 9pt; text-transform: uppercase; color: #6b6b66; width: 34%; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .key { font-family: ui-monospace, Consolas, monospace; font-size: 7pt; word-break: break-all; border: 1px solid #ddd; border-radius: 6px; padding: 8px; background: #fafafa; }
        .total { font-size: 13pt; font-weight: 700; }
        .no-print { margin: 12px 0; }
        @media print { .no-print { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
<div class="page">
    <p class="no-print"><button onclick="window.print()">Print / Save as PDF</button></p>
    <header>
        <div>
            <h1>{{ config('brand.vendor') }}</h1>
            <div class="small">SyncLytics Enterprise Systems</div>
            <div class="small muted">{{ collect([config('brand.support_phone'), config('brand.support_email'), config('brand.website')])->filter()->implode(' · ') }}</div>
        </div>
        <div class="right">@yield('heading')</div>
    </header>
    @yield('content')
</div>
</body>
</html>

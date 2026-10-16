@php
    $brand = \App\Domain\Branding\Branding::forTenant(\App\Models\Tenant::find($sale->tenant_id));
    $m = fn (int $minor) => \App\Support\Money::format($minor, $sale->currency_code);
    $q = fn ($qty) => \App\Support\Qty::format(\App\Support\Qty::toMilli((string) $qty));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $sale->receipt_no }} (copy)</title>
    {!! \App\Domain\Licensing\Watermark::meta() !!}
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #000; font: 10pt/1.35 "Segoe UI", system-ui, sans-serif; }
        .receipt { width: 74mm; margin: 0 auto; padding: 8px 0; }
        .center { text-align: center; } .small { font-size: 8.5pt; } .muted { color: #444; }
        h1 { font-size: 12pt; margin: 4px 0 2px; }
        .logo { display: block; max-width: 40mm; max-height: 18mm; margin: 0 auto 4px; object-fit: contain; }
        .copy { border: 2px dashed #000; text-align: center; font-weight: 800; letter-spacing: 1px; padding: 4px; margin: 8px 0; }
        .copy span { display: block; font-weight: 400; letter-spacing: 0; font-size: 8.5pt; }
        table { width: 100%; border-collapse: collapse; } td { padding: 2px 0; vertical-align: top; } td.num { text-align: right; white-space: nowrap; }
        .rule { border-top: 1px dashed #000; margin: 6px 0; }
        .total td { font-weight: 800; font-size: 11pt; }
        .no-print { display: flex; gap: 8px; justify-content: center; margin: 12px 0; }
        .no-print button { font: inherit; padding: 8px 14px; border: 1px solid #888; border-radius: 6px; background: #fff; cursor: pointer; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<p class="no-print"><button onclick="window.print()">Print copy</button><button onclick="window.close()">Close</button></p>
<div class="receipt">
    <div class="center">
        @if ($brand['logo_url'])<img class="logo" src="{{ \App\Domain\Branding\Branding::logoDataUri($sale->tenant_id) }}" alt="">@endif
        <h1>{{ \App\Domain\Licensing\Watermark::stamp($company['name']) }}</h1>
        <div class="small">{{ $company['branch'] }}@if ($company['address']) · {{ $company['address'] }}@endif @if ($company['phone']) · {{ $company['phone'] }}@endif</div>
        @if ($company['tin'])<div class="small">TIN {{ $company['tin'] }}@if ($company['vat_no']) · VAT {{ $company['vat_no'] }}@endif</div>@endif
    </div>

    <div class="copy">COPY<span>Not the original receipt · reprinted {{ now()->format('j M Y H:i') }} by {{ $by }}</span></div>

    <table class="small">
        <tr><td>Receipt</td><td class="num">{{ $sale->receipt_no }}</td></tr>
        <tr><td>Sold</td><td class="num">{{ $sale->occurred_at?->format('j M Y H:i') }}</td></tr>
        <tr><td>Teller</td><td class="num">{{ $sale->cashier_name }}</td></tr>
        @if ($sale->till)<tr><td>Till</td><td class="num">{{ $sale->till->code }}</td></tr>@endif
    </table>
    <div class="rule"></div>
    <table>
        @foreach ($sale->lines->sortBy('line_no') as $l)
            <tr><td colspan="2">{{ $l->name }}</td></tr>
            <tr class="small"><td class="muted">{{ $q($l->qty) }} {{ $l->uom_code }} × {{ $m((int) $l->unit_price_minor) }}</td><td class="num">{{ $m((int) $l->line_total_minor) }}</td></tr>
        @endforeach
    </table>
    <div class="rule"></div>
    <table class="small">
        <tr><td>{{ $sale->prices_include_tax ? 'Prices include tax' : 'Before tax' }}</td><td class="num">{{ $m((int) $sale->net_minor) }}</td></tr>
        @foreach ((array) $sale->tax_breakdown as $t)
            <tr><td>{{ $t['name'] ?? $t['code'] ?? 'Tax' }}@isset($t['rate_bp']) {{ number_format($t['rate_bp'] / 100, 2) }}%@endisset</td><td class="num">{{ $m((int) ($t['amount_minor'] ?? 0)) }}</td></tr>
        @endforeach
    </table>
    <table><tr class="total"><td>TOTAL</td><td class="num">{{ $m((int) $sale->total_minor) }}</td></tr></table>
    <table class="small">
        @foreach ($sale->payments as $p)
            <tr><td>{{ ucfirst(str_replace('_', ' ', $p->method)) }}@if ($p->reference) · {{ $p->reference }}@endif</td><td class="num">{{ $m((int) ($p->tendered_minor ?: $p->amount_minor)) }}</td></tr>
        @endforeach
        @if ((int) $sale->change_minor > 0)<tr><td>Change</td><td class="num">{{ $m((int) $sale->change_minor) }}</td></tr>@endif
    </table>
    <div class="rule"></div>
    <div class="center small">
        <p>{{ $footer }}</p>
        <p class="muted">Ref {{ \App\Domain\Licensing\Watermark::reference() }}</p>
        @if ($brand['powered_by'])<p class="muted">{{ $brand['powered_by'] }}</p>@endif
        @foreach ($brand['credit'] ?? [] as $line)<div class="muted">{{ $line }}</div>@endforeach
    </div>
</div>
@if ($autoPrint)<script>addEventListener('load', () => setTimeout(() => window.print(), 300))</script>@endif
</body>
</html>

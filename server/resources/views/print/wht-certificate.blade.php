@extends('print.layout')
@php($m = fn (int $minor) => \App\Support\Money::display($minor, $doc->currency_code))
@section('title', 'Withholding tax certificate '.$doc->wht_certificate_no)
@section('heading')
    <h1>WITHHOLDING TAX CREDIT CERTIFICATE</h1>
    <div><strong>{{ $doc->wht_certificate_no }}</strong></div>
    <div class="small">{{ $doc->paid_on->format('j M Y') }}</div>
@endsection
@section('content')
    <p>This certifies that withholding tax was deducted from the payment below, under the Income Tax Act, 2015 (Act 896),
        and is paid to the Ghana Revenue Authority. The supplier may claim it as a credit against their income tax.</p>
    <table>
        <tbody>
            <tr><th style="text-align:left">Supplier (payee)</th><td>{{ $supplier->name }}</td></tr>
            <tr><th style="text-align:left">Supplier's TIN</th><td>{{ $supplier->tax_no ?: '—' }}</td></tr>
            <tr><th style="text-align:left">Payer</th><td>{{ $company['name'] ?? '' }}@if (! empty($company['tin'])), TIN {{ $company['tin'] }}@endif</td></tr>
            <tr><th style="text-align:left">Payment</th><td>{{ $doc->number }}, {{ \App\Models\SupplierPayment::METHODS[$doc->method] ?? $doc->method }}@if ($doc->reference), {{ $doc->reference }}@endif</td></tr>
            <tr><th style="text-align:left">Nature of supply</th><td>{{ \App\Models\Supplier::SUPPLY_KINDS[$supplier->supply_kind] ?? $supplier->supply_kind }}</td></tr>
            <tr><th style="text-align:left">Amount before VAT (tax base)</th><td class="num">{{ $m((int) $doc->wht_base_minor) }}</td></tr>
            <tr><th style="text-align:left">Rate</th><td class="num">{{ rtrim(rtrim(number_format($doc->wht_rate_bp / 100, 2), '0'), '.') }} %</td></tr>
            <tr><th style="text-align:left">Withholding tax deducted</th><td class="num"><strong>{{ $m((int) $doc->wht_minor) }}</strong></td></tr>
            <tr><th style="text-align:left">Amount paid to the supplier</th><td class="num">{{ $m($doc->cashMinor()) }}</td></tr>
        </tbody>
    </table>
    <p style="margin-top:40px">______________________________<br><span class="small">Authorised signature and stamp, {{ $company['name'] ?? '' }}</span></p>
    <p class="small muted">Recorded by {{ $doc->paid_by_name }}.</p>
@endsection

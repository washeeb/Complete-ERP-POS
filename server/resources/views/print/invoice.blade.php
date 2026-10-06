@extends('print.layout')
@php($m = fn (int $minor) => \App\Support\Money::display($minor, $doc->currency_code))
@section('title', 'Invoice '.$doc->number)
@section('heading')
    <h1>{{ $doc->status === 'credited' ? 'INVOICE (CREDITED)' : 'TAX INVOICE' }}</h1>
    <div><strong>{{ $doc->number }}</strong></div>
    <div class="small">Issued {{ $doc->issued_on->format('j M Y') }} · Due {{ $doc->due_on->format('j M Y') }}</div>
@endsection
@section('content')
    <div class="parties">
        <div class="box">
            <div class="small muted">Bill to</div>
            <strong>{{ $doc->customer_name }}</strong><br>
            @if ($customer?->address){!! nl2br(e($customer->address)) !!}<br>@endif
            @if ($doc->customer_tax_no)<span class="small">TIN {{ $doc->customer_tax_no }}</span><br>@endif
            @if ($customer?->phone)<span class="small">{{ $customer->phone }}</span>@endif
        </div>
        <div class="box small">
            @if ($doc->customer_ref)Your reference: <strong>{{ $doc->customer_ref }}</strong><br>@endif
            Customer code: {{ $customer?->code }}<br>
            Payment terms: {{ $customer?->payment_terms_days ? $customer->payment_terms_days.' days' : 'cash' }}
        </div>
    </div>
    <table>
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Tax</th><th class="num">Amount</th></tr></thead>
        <tbody>
        @foreach ($doc->lines as $l)
            <tr><td>{{ $l->name }}<br><span class="small muted">{{ $l->sku }}</span></td>
                <td class="num">{{ \App\Filament\Support\Fields::qty($l->qty, $l->uom_code) }}</td>
                <td class="num">{{ $m($l->unit_price_minor) }}</td><td class="num">{{ $m($l->tax_minor) }}</td><td class="num">{{ $m($l->total_minor) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="4" class="right">Before tax</td><td class="num">{{ $m($doc->net_minor) }}</td></tr>
            @foreach ($doc->tax_breakdown as $t)
                <tr><td colspan="4" class="right">{{ $t['name'] }}</td><td class="num">{{ $m($t['amount_minor']) }}</td></tr>
            @endforeach
            <tr><td colspan="4" class="right total">Total</td><td class="num total">{{ $m($doc->total_minor) }}</td></tr>
            <tr><td colspan="4" class="right">Still owed</td><td class="num">{{ $m($open) }}</td></tr>
        </tfoot>
    </table>
    <p class="small muted">Prices {{ $doc->prices_include_tax ? 'include' : 'exclude' }} tax. Please quote {{ $doc->number }} with your payment.</p>
@endsection

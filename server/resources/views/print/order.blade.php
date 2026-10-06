@extends('print.layout')
@php($m = fn (int $minor) => \App\Support\Money::display($minor, $doc->currency_code))
@php($isQuote = $doc->status === 'quotation' || ! $doc->number)
@section('title', ($isQuote ? 'Quotation ' : 'Order ').($doc->number ?? $doc->quote_number))
@section('heading')
    <h1>{{ $isQuote ? 'QUOTATION' : 'ORDER CONFIRMATION' }}</h1>
    <div><strong>{{ $isQuote ? $doc->quote_number : $doc->number }}</strong></div>
    <div class="small">{{ $doc->created_at->format('j M Y') }}@if ($isQuote && $doc->valid_until) · valid until {{ $doc->valid_until->format('j M Y') }}@endif</div>
@endsection
@section('content')
    <div class="parties">
        <div class="box"><div class="small muted">Customer</div><strong>{{ $customer?->name }}</strong><br>
            @if ($customer?->address){!! nl2br(e($customer->address)) !!}<br>@endif
            @if ($customer?->tax_no)<span class="small">TIN {{ $customer->tax_no }}</span>@endif</div>
        <div class="box small">@if ($doc->customer_ref)Your reference: <strong>{{ $doc->customer_ref }}</strong><br>@endif Prepared by {{ $doc->created_by_name }}</div>
    </div>
    <table>
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
        <tbody>@foreach ($doc->lines as $l)
            <tr><td>{{ $l->name }}<br><span class="small muted">{{ $l->sku }}</span></td><td class="num">{{ \App\Filament\Support\Fields::qty($l->qty, $l->uom_code) }}</td>
                <td class="num">{{ $m($l->unit_price_minor) }}</td><td class="num">{{ $m($l->total_minor) }}</td></tr>
        @endforeach</tbody>
        <tfoot>
            <tr><td colspan="3" class="right">Before tax</td><td class="num">{{ $m($doc->net_minor) }}</td></tr>
            <tr><td colspan="3" class="right">Tax</td><td class="num">{{ $m($doc->tax_minor) }}</td></tr>
            <tr><td colspan="3" class="right total">Total</td><td class="num total">{{ $m($doc->total_minor) }}</td></tr>
        </tfoot>
    </table>
    @if ($doc->note)<p>{!! nl2br(e($doc->note)) !!}</p>@endif
    @if ($isQuote)<p class="small muted">Prices are subject to availability and confirmed when you place the order.</p>@endif
@endsection

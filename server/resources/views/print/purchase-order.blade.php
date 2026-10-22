@extends('print.layout')
@php($cur = $doc->currency_code ?? \App\Models\Tenant::find($doc->tenant_id)->currency_code)
@php($m = fn (?int $minor) => $minor === null ? '' : \App\Support\Money::display($minor, $cur))
@section('title', 'Purchase order '.$doc->number)
@section('heading')
    <h1>PURCHASE ORDER</h1>
    <div><strong>{{ $doc->number }}</strong></div>
    <div class="small">{{ $doc->created_at->format('j M Y') }}@if ($doc->expected_on) · delivery by {{ $doc->expected_on->format('j M Y') }}@endif</div>
    @if (! in_array($doc->status, ['approved', 'partially_received', 'received'], true))<div class="small" style="color:#b45309"><strong>{{ \App\Models\PurchaseOrder::STATUSES[$doc->status] ?? $doc->status }} — not yet an order</strong></div>@endif
@endsection
@section('content')
    <div class="parties">
        <div class="box"><div class="small muted">Supplier</div><strong>{{ $supplier?->name }}</strong><br>
            @if ($supplier?->address){!! nl2br(e($supplier->address)) !!}<br>@endif
            <span class="small">@if ($supplier?->phone){{ $supplier->phone }}@endif @if ($supplier?->email) · {{ $supplier->email }}@endif @if ($supplier?->tax_no)<br>TIN {{ $supplier->tax_no }}@endif</span></div>
        <div class="box"><div class="small muted">Deliver to</div><strong>{{ $warehouse?->name }}</strong><br>
            @if ($company['address']){!! nl2br(e($company['address'])) !!}<br>@endif<span class="small">@if ($company['phone']){{ $company['phone'] }}@endif</span></div>
    </div>
    <table>
        <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Amount</th></tr></thead>
        <tbody>@foreach ($doc->lines as $l)
            <tr><td>{{ $l->line_no }}</td><td>{{ $l->name }}<br><span class="small muted">{{ $l->sku }}</span></td><td class="num">{{ \App\Filament\Support\Fields::qty((string) $l->qty, $l->uom_code) }}</td>
                <td class="num">{{ $m($l->unit_cost_minor) }}</td><td class="num">{{ $m($l->net_minor) }}</td></tr>
        @endforeach</tbody>
        <tfoot>
            <tr><td colspan="4" class="right">Before tax</td><td class="num">{{ $m($doc->net_minor) }}</td></tr>
            <tr><td colspan="4" class="right">Tax</td><td class="num">{{ $m($doc->tax_minor) }}</td></tr>
            <tr><td colspan="4" class="right total">Total</td><td class="num total">{{ $m($doc->total_minor) }}</td></tr>
        </tfoot>
    </table>
    @if ($doc->note)<p>{!! nl2br(e($doc->note)) !!}</p>@endif
    <p class="small muted">Please quote {{ $doc->number }} on your delivery note and invoice. Deliver only the items and quantities above.</p>
    <div class="parties" style="margin-top:28px">
        <div>Prepared by: <strong>{{ $doc->created_by_name }}</strong><br><br>Signature ______________________</div>
        <div>Approved by: <strong>{{ $doc->approved_by_name ?? '' }}</strong><br><br>Signature ______________________</div>
    </div>
@endsection

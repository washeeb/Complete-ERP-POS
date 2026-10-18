@extends('print.layout')
@php($q = fn ($l) => \App\Filament\Support\Fields::qty(\App\Support\Qty::format(\App\Support\Qty::toMilli((string) $l->qty)), $l->product->baseUom?->code))
@php($money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v))
@section('title', 'Return to supplier '.$r->number)
@section('heading')
    <h1>RETURN TO SUPPLIER</h1>
    <div><strong>{{ $r->number }}</strong></div>
    <div class="small">{{ $r->created_at->format('j M Y') }} · {{ \App\Models\SupplierReturn::STATUSES[$r->status] }}</div>
@endsection
@section('content')
    <div class="parties">
        <div class="box"><div class="small muted">To</div><strong>{{ $r->supplier->name }}</strong>
            @if ($r->supplier->phone)<div class="small">{{ $r->supplier->phone }}</div>@endif
            @if ($r->supplier->address)<div class="small">{{ $r->supplier->address }}</div>@endif</div>
        <div class="box"><div class="small muted">From</div><strong>{{ $r->warehouse->code }} · {{ $r->warehouse->name }}</strong>
            <div class="small">Reason: {{ \App\Models\SupplierReturn::REASONS[$r->reason] }}</div>
            <div class="small">Requested: {{ \App\Models\SupplierReturn::SETTLEMENTS[$r->settlement] }}</div></div>
    </div>
    <table>
        <thead><tr><th>#</th><th>Product</th><th>Batch</th><th>Expires</th><th class="num">Quantity</th><th class="num">Value</th></tr></thead>
        <tbody>
        @foreach ($r->lines as $l)
            <tr><td>{{ $l->line_no }}</td><td>{{ $l->product->name }} <span class="small muted">{{ $l->product->sku }}</span></td><td>{{ $l->batch_no }}</td>
                <td>{{ $l->expires_on?->format('j M Y') }}</td><td class="num">{{ $q($l) }}</td><td class="num">{{ $money($l->value_minor) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><th colspan="5">Total at cost</th><th class="num total">{{ $money($r->total_minor) }}</th></tr></tfoot>
    </table>
    @if ($r->note)<p class="small">{{ $r->note }}</p>@endif
    <p class="small" style="margin-top: 28px">Prepared by {{ $r->prepared_by_name }} @if ($r->approved_by_name) · approved by {{ $r->approved_by_name }} ({{ $r->posted_at?->format('j M Y') }}) @endif</p>
    <p class="small" style="margin-top: 24px">Released by (store) ______________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
    <p class="small" style="margin-top: 18px">Received by (supplier) ____________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
    @if ($r->settlement_ref)<p class="small">Settled: {{ $r->settlement_ref }} ({{ $r->settled_at?->format('j M Y') }})</p>@endif
@endsection

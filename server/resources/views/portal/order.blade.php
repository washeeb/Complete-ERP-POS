@extends('portal.layout')
@php
    $m = fn (int $minor) => \App\Support\Money::display($minor, $po->currency_code);
    $q = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    $live = in_array($po->status, ['approved', 'partially_received'], true);
@endphp
@section('title', 'Order '.$po->number)
@section('heading')
    <h1>Purchase order {{ $po->number }}</h1>
    <div class="muted">{{ \App\Models\PurchaseOrder::STATUSES[$po->status] ?? $po->status }} · {{ $po->approved_at?->format('j M Y') }}@if ($po->expected_on) · Deliver by {{ $po->expected_on->format('j M Y') }}@endif</div>
    <p style="margin-bottom:0"><a href="{{ route('supplier.portal', $token) }}">← Your page</a> · <a href="{{ route('supplier.portal.order-pdf', [$token, $po->id]) }}">Download the order (PDF)</a></p>
@endsection
@section('content')
    <div class="card scroll">
        <table class="table">
            <tr><th>#</th><th>Item</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Unit price</th><th class="num">Total</th></tr>
            @foreach ($po->lines as $l)
                <tr><td>{{ $l->line_no }}</td><td>{{ $l->name }}<div class="muted">{{ $l->sku }}</div></td><td class="num">{{ $q($l->qty) }} {{ $l->uom_code }}</td>
                    <td class="num">{{ $q($l->received_qty) }}</td><td class="num">{{ $m($l->unit_cost_minor) }}</td><td class="num">{{ $m($l->total_minor) }}</td></tr>
            @endforeach
            <tr><td colspan="5" class="num"><strong>Total with VAT</strong></td><td class="num"><strong>{{ $m((int) $po->total_minor) }}</strong></td></tr>
        </table>
        <p class="muted">Quote {{ $po->number }} on your delivery note and invoice, and deliver only the items and quantities on the order.</p>
    </div>

    <div class="card">
        <h2>Confirm the order</h2>
        @if ($po->supplier_confirmed_at)
            <p class="ok">Confirmed {{ $po->supplier_confirmed_at->format('j M Y H:i') }}: delivery on {{ $po->supplier_promised_on?->format('j M Y') }}.</p>
            @if ($po->supplier_reply)<p class="muted">{{ $po->supplier_reply }}</p>@endif
        @endif
        @if ($live)
            <form method="post">
                @csrf
                <input type="hidden" name="action" value="confirm">
                <div class="grid">
                    <div><label for="promised_on">We will deliver on</label><input id="promised_on" name="promised_on" type="date" required min="{{ today()->toDateString() }}"
                        value="{{ old('promised_on', $po->supplier_promised_on?->toDateString() ?? $po->expected_on?->toDateString()) }}"></div>
                    <div><label for="c_name">Your name</label><input id="c_name" name="name" required maxlength="120" autocomplete="name" value="{{ old('name') }}"></div>
                </div>
                <label for="reply">Anything the buyer should know (item short, substitute…)</label><input id="reply" name="reply" maxlength="500" value="{{ old('reply') }}">
                <button type="submit">{{ $po->supplier_confirmed_at ? 'Change the delivery date' : 'Confirm the order' }}</button>
            </form>
        @endif
    </div>

    @if ($live)
        <div class="card">
            <h2>Goods on the way</h2>
            @if ($po->dispatched_at)<p class="ok">Sent {{ $po->dispatched_at->format('j M Y H:i') }}: {{ $po->dispatch_ref }}.</p>@endif
            <form method="post">
                @csrf
                <input type="hidden" name="action" value="dispatch">
                <div class="grid">
                    <div><label for="reference">Delivery note / waybill no. (and vehicle)</label><input id="reference" name="reference" required maxlength="120" value="{{ old('reference') }}"></div>
                    <div><label for="d_name">Your name</label><input id="d_name" name="name" required maxlength="120" autocomplete="name"></div>
                </div>
                <button type="submit">Tell the store the goods are on the way</button>
            </form>
        </div>
    @endif
@endsection

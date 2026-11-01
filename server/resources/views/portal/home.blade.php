@extends('portal.layout')
@php
    $cur = $statement['currency'];
    $m = fn (?int $minor) => $minor === null ? '—' : \App\Support\Money::display($minor, $cur);
    $poStatus = fn ($po) => match (true) {
        $po->status === 'received' => 'Delivered',
        $po->status === 'closed' => 'Closed',
        $po->status === 'partially_received' => 'Part delivered',
        (bool) $po->dispatched_at => 'On the way',
        (bool) $po->supplier_confirmed_at => 'Confirmed for '.$po->supplier_promised_on?->format('j M'),
        default => 'Please confirm',
    };
@endphp
@section('title', $supplier->name)
@section('heading')
    <h1>{{ $supplier->name }}</h1>
    <div class="muted">Your supplier page: quotes, orders, invoices and payments.</div>
@endsection
@section('content')
    <div class="card">
        <h2>Requests for quotation</h2>
        @forelse ($rfqs as $i)
            <p style="margin:.4rem 0"><a href="{{ $i->link() }}">{{ $i->rfq->number }} · {{ $i->rfq->title }}</a>
                <span class="muted">· @if ($i->rfq->acceptsQuotes()) closes {{ $i->rfq->closes_at->format('j M H:i') }} · {{ \App\Models\RfqSupplier::STATUSES[$i->status] }}
                @elseif ($i->purchase_order_id) awarded to you @elseif ($i->rfq->status === 'awarded') decided @else closed @endif</span></p>
        @empty
            <p class="muted">None open.</p>
        @endforelse
    </div>

    <div class="card">
        <h2>Orders</h2>
        @if ($orders->isEmpty())<p class="muted">No orders in the last six months.</p>@else
            <div class="scroll"><table class="table">
                <tr><th>Order</th><th>Date</th><th class="num">Total</th><th>Deliver by</th><th>Status</th></tr>
                @foreach ($orders as $po)
                    <tr>
                        <td><a href="{{ route('supplier.portal.order', [$token, $po->id]) }}">{{ $po->number }}</a></td>
                        <td>{{ $po->approved_at?->format('j M Y') }}</td>
                        <td class="num">{{ \App\Support\Money::display((int) $po->total_minor, $po->currency_code) }}</td>
                        <td>{{ $po->expected_on?->format('j M Y') ?? '—' }}</td>
                        <td>@php($st = $poStatus($po))<span class="{{ $st === 'Please confirm' ? 'warn' : '' }}">{{ $st }}</span></td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="card">
        <h2>Your account with us</h2>
        <p>We owe you <strong>{{ $m(max(0, $statement['owed'])) }}</strong>@if ($statement['owed'] < 0) (you hold {{ $m(-$statement['owed']) }} paid in advance)@endif.</p>
        @if ($statement['open'])
            <div class="scroll"><table class="table">
                <tr><th>Bill</th><th>Your invoice</th><th>Due</th><th class="num">Still to pay</th></tr>
                @foreach ($statement['open'] as $o)
                    <tr><td>{{ $o->doc_number }}</td><td>{{ $o->their_ref ?? '—' }}</td><td>{{ $o->due_on ? \Illuminate\Support\Carbon::parse($o->due_on)->format('j M Y') : '—' }}</td>
                        <td class="num">{{ $m((int) $o->open_minor) }}</td></tr>
                @endforeach
            </table></div>
        @endif
        <h2 style="margin-top:1rem">Payments (last 12 months)</h2>
        @if ($statement['payments']->isEmpty())<p class="muted">None.</p>@else
            <div class="scroll"><table class="table">
                <tr><th>Date</th><th>Payment</th><th class="num">Paid to you</th><th class="num">Tax withheld</th><th></th></tr>
                @foreach ($statement['payments'] as $p)
                    <tr><td>{{ $p->paid_on->format('j M Y') }}</td><td>{{ $p->number }}@if ($p->reference) · {{ $p->reference }}@endif</td>
                        <td class="num">{{ $m($p->cashMinor()) }}</td><td class="num">{{ $p->wht_minor > 0 ? $m($p->wht_minor) : '—' }}</td>
                        <td>@if ($p->wht_minor > 0)<a href="{{ route('supplier.portal.wht', [$token, $p->id]) }}" target="_blank" rel="noopener">Certificate</a>@endif</td></tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="card">
        <h2>Send an invoice</h2>
        @if ($statement['invoices']->isNotEmpty())
            <div class="scroll"><table class="table" style="margin-bottom:.5rem">
                <tr><th>Your invoice</th><th>Date</th><th class="num">Amount</th><th>Status</th></tr>
                @foreach ($statement['invoices'] as $iv)
                    <tr><td>{{ $iv->invoice_no }}</td><td>{{ $iv->invoice_date->format('j M Y') }}</td><td class="num">{{ $m($iv->amount_minor) }}</td>
                        <td>{{ \App\Models\SupplierInvoice::STATUSES[$iv->status] === 'To enter' ? 'Received, being checked' : \App\Models\SupplierInvoice::STATUSES[$iv->status] }}@if ($iv->reject_reason): {{ $iv->reject_reason }}@endif</td></tr>
                @endforeach
            </table></div>
        @endif
        <form method="post" action="{{ route('supplier.portal.invoice', $token) }}" enctype="multipart/form-data">
            @csrf
            <div class="grid">
                <div><label for="invoice_no">Invoice number</label><input id="invoice_no" name="invoice_no" required maxlength="60" value="{{ old('invoice_no') }}"></div>
                <div><label for="invoice_date">Invoice date</label><input id="invoice_date" name="invoice_date" type="date" required max="{{ today()->toDateString() }}" value="{{ old('invoice_date') }}"></div>
                <div><label for="amount">Total with VAT ({{ $cur }})</label><input id="amount" name="amount" inputmode="decimal" required value="{{ old('amount') }}"></div>
                <div><label for="purchase_order_id">For order</label><select id="purchase_order_id" name="purchase_order_id"><option value="">—</option>
                    @foreach ($orders as $po)<option value="{{ $po->id }}" @selected(old('purchase_order_id') === $po->id)>{{ $po->number }}</option>@endforeach</select></div>
            </div>
            <label for="file">The invoice (PDF or photo, up to 10 MB)</label><input id="file" name="file" type="file" required accept="application/pdf,image/*">
            <label for="inv_note">Note (optional)</label><input id="inv_note" name="note" maxlength="500" value="{{ old('note') }}">
            <label for="inv_name">Your name</label><input id="inv_name" name="name" required maxlength="120" autocomplete="name" value="{{ old('name') }}">
            <button type="submit">Send the invoice</button>
        </form>
    </div>
@endsection

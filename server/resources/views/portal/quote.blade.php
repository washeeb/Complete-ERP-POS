@extends('portal.layout')
@php
    $m = fn (?int $minor) => $minor === null ? '—' : \App\Support\Money::display($minor, $currency);
    $q = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    $mine = $inv->quoteLines->keyBy('rfq_line_id');
    $open = $rfq->acceptsQuotes();
@endphp
@section('title', 'Request for quotation '.$rfq->number)
@section('heading')
    <h1>Request for quotation {{ $rfq->number }}</h1>
    <div><strong>{{ $rfq->title }}</strong></div>
    <div class="muted">To {{ $supplier->name }} · Quotes close {{ $rfq->closes_at->format('j M Y, H:i') }}@if ($rfq->needed_by) · Needed by {{ $rfq->needed_by->format('j M Y') }}@endif</div>
    @if ($rfq->terms)<p style="white-space:pre-line;margin-bottom:0">{{ $rfq->terms }}</p>@endif
@endsection
@section('content')
    @if (! $open)
        <div class="card">
            @if ($rfq->status === 'awarded' && $inv->purchase_order_id)
                <p class="ok">You have been awarded part or all of this request. The purchase order follows from the buyer.</p>
            @elseif ($rfq->status === 'awarded')
                <p>This request has been decided. It was not awarded to you this time. Thank you for quoting.</p>
            @else
                <p>Quotes have closed. The buyer is comparing them.</p>
            @endif
            @if ($inv->status === 'quoted')<p class="muted">Your quote: {{ $m($inv->total_minor) }} before VAT, sent {{ $inv->quoted_at?->format('j M Y H:i') }}.</p>@endif
        </div>
    @else
        <form class="card" method="post">
            @csrf
            <h2>Your prices</h2>
            <p class="muted">Price per unit asked for, in {{ $currency }}, <strong>before VAT</strong>. Leave the price empty for an item you do not supply.
                If you can supply less than asked, change the quantity. Quotes are sealed: the buyer sees them only after the closing time.
                @if ($inv->status === 'quoted') You quoted on {{ $inv->quoted_at->format('j M H:i') }}; sending again replaces it.@endif</p>
            <div class="scroll">
                <table class="table">
                    <tr><th>#</th><th>Item</th><th class="num">Asked</th><th style="min-width:7rem">Unit price</th><th style="min-width:6rem">You can supply</th><th style="min-width:8rem">Note (brand, pack, expiry…)</th></tr>
                    @foreach ($lines as $l)
                        @php($has = $mine[$l->id] ?? null)
                        <tr>
                            <td>{{ $l->line_no }}</td>
                            <td>{{ $l->name }}<div class="muted">{{ $l->sku }}@if ($l->note) · {{ $l->note }}@endif</div></td>
                            <td class="num">{{ $q($l->qty) }} {{ $l->uom_code }}</td>
                            <td><input name="lines[{{ $l->id }}][price]" inputmode="decimal" aria-label="Unit price for {{ $l->name }}"
                                       value="{{ old("lines.{$l->id}.price", $has ? \App\Support\Money::format($has->unit_cost_minor, $currency) : '') }}"></td>
                            <td><input name="lines[{{ $l->id }}][qty]" inputmode="decimal" aria-label="Quantity you can supply of {{ $l->name }}"
                                       value="{{ old("lines.{$l->id}.qty", $has ? $q($has->qty) : $q($l->qty)) }}"></td>
                            <td><input name="lines[{{ $l->id }}][note]" maxlength="255" aria-label="Note for {{ $l->name }}" value="{{ old("lines.{$l->id}.note", $has?->note) }}"></td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <div class="grid">
                <div><label for="delivery_days">Delivery time (days after the order)</label><input id="delivery_days" name="delivery_days" type="number" min="0" max="365" value="{{ old('delivery_days', $inv->delivery_days) }}"></div>
                <div><label for="payment_days">Credit (days to pay after delivery)</label><input id="payment_days" name="payment_days" type="number" min="0" max="365" value="{{ old('payment_days', $inv->payment_days) }}"></div>
                <div><label for="valid_until">Prices hold until</label><input id="valid_until" name="valid_until" type="date" value="{{ old('valid_until', $inv->valid_until?->toDateString()) }}"></div>
            </div>
            <label for="note">Anything else (optional)</label><textarea id="note" name="note" rows="2" maxlength="500">{{ old('note', $inv->status === 'quoted' ? $inv->note : '') }}</textarea>
            <label for="name">Your name</label><input id="name" name="name" required maxlength="120" autocomplete="name" value="{{ old('name') }}">
            <button type="submit" name="action" value="quote">{{ $inv->status === 'quoted' ? 'Send the new prices' : 'Send my prices' }}</button>
            <details style="margin-top:1rem">
                <summary>We cannot quote this time</summary>
                <label for="decline">Reason (optional)</label><input id="decline" name="decline" maxlength="500" value="{{ old('decline') }}">
                <button type="submit" name="action" value="decline" class="secondary" formnovalidate>Decline</button>
                <p class="muted">Give your name above first.</p>
            </details>
        </form>
    @endif
@endsection

@extends('licensing-vendor.layout')
@php($m = fn ($v) => \App\Support\Money::format((int) $v, $i->currency))
@section('title', 'Invoice '.$i->number)
@section('heading')
    <h1>{{ $i->status === 'paid' ? 'RECEIPT' : 'INVOICE' }}</h1>
    <div><strong>{{ $i->number }}</strong></div>
    <div class="small">Issued {{ $i->issued_on->format('j M Y') }} · due {{ $i->due_on->format('j M Y') }}</div>
    @if ($i->status === 'void')<div class="small"><strong>VOID</strong> — {{ $i->void_reason }}</div>@endif
@endsection
@section('content')
    <p><strong>To:</strong> {{ $i->customer->legal_name ?: $i->customer->name }}@if ($i->customer->address)<br>{{ $i->customer->address }}@endif
        @if ($i->customer->tin)<br>TIN {{ $i->customer->tin }}@endif</p>
    <table>
        <thead><tr><th style="width:auto">Description</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Amount</th></tr></thead>
        <tbody>
        @foreach ($i->lines as $line)
            <tr><td>{{ $line['description'] }}</td><td class="num">{{ rtrim(rtrim(number_format($line['qty'], 2, '.', ''), '0'), '.') }}</td>
                <td class="num">{{ $m($line['unit_minor']) }}</td><td class="num">{{ $m($line['total_minor']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><th colspan="3">Total</th><td class="num total">{{ $m($i->total_minor) }}</td></tr>
            @foreach ($i->payments as $p)
                <tr><td colspan="3" class="small">Paid {{ $p->received_on->format('j M Y') }} · {{ \App\Models\Vendor\VendorPayment::METHODS[$p->method] ?? $p->method }} {{ $p->reference }}</td>
                    <td class="num">− {{ $m($p->amount_minor) }}</td></tr>
            @endforeach
            <tr><th colspan="3">Balance due</th><td class="num total">{{ $m($i->balanceMinor()) }}</td></tr>
        </tfoot>
    </table>
    @if ($i->notes)<p class="small">{{ $i->notes }}</p>@endif
    @if ($i->balanceMinor() > 0 && config('license.server.payment_details'))
        <h2>How to pay</h2>
        <p class="small">{!! nl2br(e(str_replace('|', "\n", config('license.server.payment_details')))) !!}<br>Please quote <strong>{{ $i->number }}</strong> as the reference.</p>
    @endif
@endsection

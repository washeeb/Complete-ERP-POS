@extends('print.layout')
@php($m = fn (int $minor) => \App\Support\Money::display($minor, $currency))
@section('title', 'Statement '.$customer->name)
@section('heading')
    <h1>STATEMENT</h1>
    <div class="small">{{ $from->format('j M Y') }} – {{ $to->format('j M Y') }}</div>
    <div class="small">Customer {{ $customer->code }}</div>
@endsection
@section('content')
    <div class="parties">
        <div class="box"><strong>{{ $customer->name }}</strong><br>@if ($customer->address){!! nl2br(e($customer->address)) !!}<br>@endif
            @if ($customer->phone)<span class="small">{{ $customer->phone }}</span>@endif</div>
        <div class="box small">Credit limit: {{ $customer->credit_limit_minor === null ? 'cash customer' : $m($customer->credit_limit_minor) }}<br>
            Terms: {{ $customer->payment_terms_days ? $customer->payment_terms_days.' days' : 'cash' }}</div>
    </div>
    <table>
        <thead><tr><th>Date</th><th>Document</th><th>Due</th><th class="num">Charges</th><th class="num">Payments / credits</th><th class="num">Balance</th></tr></thead>
        <tbody>
            <tr><td>{{ $from->format('j M Y') }}</td><td colspan="4">Balance brought forward</td><td class="num">{{ $m($statement['opening']) }}</td></tr>
            @foreach ($statement['entries'] as $e)
                <tr><td>{{ \Illuminate\Support\Carbon::parse($e->occurred_at)->format('j M Y') }}</td>
                    <td>{{ ['invoice' => 'Invoice', 'pos_sale' => 'Till sale', 'payment' => 'Payment', 'credit_note' => 'Credit note', 'opening' => 'Opening balance'][$e->type] ?? $e->type }} {{ $e->doc_number }}</td>
                    <td>{{ $e->due_on ? \Illuminate\Support\Carbon::parse($e->due_on)->format('j M Y') : '' }}</td>
                    <td class="num">{{ $e->amount_minor > 0 ? $m((int) $e->amount_minor) : '' }}</td>
                    <td class="num">{{ $e->amount_minor < 0 ? $m(-(int) $e->amount_minor) : '' }}</td>
                    <td class="num">{{ $m((int) $e->running_minor) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><td colspan="5" class="right total">Balance at {{ $to->format('j M Y') }}</td><td class="num total">{{ $m($statement['closing']) }}</td></tr></tfoot>
    </table>
    @if ($aging)
        <h3>Ageing</h3>
        <table class="small"><thead><tr><th class="num">Not yet due</th><th class="num">1–30 days</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">Over 90</th></tr></thead>
            <tbody><tr><td class="num">{{ $m($aging['current']) }}</td><td class="num">{{ $m($aging['d1_30']) }}</td><td class="num">{{ $m($aging['d31_60']) }}</td>
                <td class="num">{{ $m($aging['d61_90']) }}</td><td class="num">{{ $m($aging['d90_plus']) }}</td></tr></tbody></table>
    @endif
@endsection

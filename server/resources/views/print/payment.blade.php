@extends('print.layout')
@php($m = fn (int $minor) => \App\Support\Money::display($minor, $doc->currency_code))
@section('title', 'Receipt '.$doc->number)
@section('heading')
    <h1>PAYMENT RECEIPT</h1>
    <div><strong>{{ $doc->number }}</strong></div>
    <div class="small">{{ $doc->received_on->format('j M Y') }}</div>
@endsection
@section('content')
    <p>Received from <strong>{{ $customer?->name }}</strong> ({{ $customer?->code }}) the sum of <strong>{{ $m($doc->amount_minor) }}</strong>
        by {{ \App\Domain\Sales\ReceivablesService::METHODS[$doc->method] ?? $doc->method }}@if ($doc->provider) ({{ \App\Models\SalePayment::MOMO_PROVIDERS[$doc->provider] ?? $doc->provider }})@endif
        @if ($doc->reference), reference {{ $doc->reference }}@endif.</p>
    @if ($allocations->isNotEmpty())
        <table><thead><tr><th>Settles</th><th class="num">Amount</th></tr></thead>
            <tbody>@foreach ($allocations as $a)<tr><td>{{ $a->doc_number }}</td><td class="num">{{ $m((int) $a->amount_minor) }}</td></tr>@endforeach</tbody></table>
    @endif
    <p class="small muted">Received by {{ $doc->received_by_name }}.</p>
@endsection

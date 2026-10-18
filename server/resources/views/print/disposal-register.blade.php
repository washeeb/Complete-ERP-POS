@extends('print.layout')
@php($q = fn ($l) => \App\Filament\Support\Fields::qty(\App\Support\Qty::format(\App\Support\Qty::toMilli((string) $l->qty)), $l->product->baseUom?->code))
@section('title', 'Disposal register '.$from.' to '.$to)
@section('heading')
    <h1>DISPOSAL REGISTER</h1>
    <div><strong>{{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}</strong></div>
    <div class="small">{{ $warehouse->code }} · {{ $warehouse->name }}</div>
@endsection
@section('content')
    <table>
        <thead><tr><th>Approved</th><th>Record</th><th>Product</th><th>Batch</th><th>Expires</th><th class="num">Quantity</th><th>Method · witness</th><th>By</th></tr></thead>
        <tbody>
        @forelse ($disposals as $a)
            @foreach ($a->lines as $l)
                <tr><td>{{ $a->posted_at->format('j M Y') }}</td><td>{{ $a->number }}</td>
                    <td>{{ $l->product->name }}@if ($l->product->drug_schedule === 'controlled') <span class="small">(controlled)</span>@endif</td>
                    <td>{{ $l->batch_no }}</td><td>{{ $l->expires_on?->format('j M Y') }}</td><td class="num">{{ $q($l) }}</td>
                    <td class="small">{{ \App\Domain\Inventory\ExpiryService::DISPOSAL_METHODS[$a->disposal_method] ?? '—' }} · {{ $a->witness_name ?? '—' }}</td>
                    <td class="small">{{ $a->created_by_name }} / {{ $a->posted_by_name }}</td></tr>
            @endforeach
        @empty
            <tr><td colspan="8">No disposals approved in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="small" style="margin-top: 24px">Checked by (pharmacist) ______________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
@endsection

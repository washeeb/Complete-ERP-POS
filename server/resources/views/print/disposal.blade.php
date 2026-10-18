@extends('print.layout')
@php($q = fn ($l) => \App\Filament\Support\Fields::qty(\App\Support\Qty::format(\App\Support\Qty::toMilli((string) $l->qty)), $l->product->baseUom?->code))
@section('title', 'Disposal record '.$a->number)
@section('heading')
    <h1>DISPOSAL RECORD</h1>
    <div><strong>{{ $a->number }}</strong></div>
    <div class="small">{{ $a->warehouse->code }} · {{ $a->warehouse->name }}</div>
    <div class="small">{{ \App\Models\StockAdjustment::STATUSES[$a->status] }}</div>
@endsection
@section('content')
    <div class="parties">
        <div class="box"><div class="small muted">Reason</div><strong>{{ \App\Domain\Inventory\InventoryService::ADJUSTMENT_REASONS[$a->reason_code] ?? $a->reason_code }}</strong>
            <div class="small">Method: {{ \App\Domain\Inventory\ExpiryService::DISPOSAL_METHODS[$a->disposal_method] ?? '—' }}</div>
            <div class="small">Disposed of on: {{ $a->disposed_on?->format('j M Y') ?? '—' }}</div></div>
        <div class="box"><div class="small muted">People</div>
            <div class="small">Prepared by: {{ $a->created_by_name }}</div>
            <div class="small">Witness: {{ $a->witness_name ?? '—' }}</div>
            <div class="small">Approved by: {{ $a->posted_by_name ?? 'not yet approved' }} @if ($a->posted_at) ({{ $a->posted_at->format('j M Y') }}) @endif</div></div>
    </div>
    <table>
        <thead><tr><th>#</th><th>Product</th><th>Batch</th><th>Expires</th><th class="num">Quantity</th></tr></thead>
        <tbody>
        @foreach ($a->lines as $l)
            <tr><td>{{ $l->line_no }}</td><td>{{ $l->product->name }} <span class="small muted">{{ $l->product->sku }}@if ($l->product->drug_schedule === 'controlled') · controlled @endif</span></td>
                <td>{{ $l->batch_no }}</td><td>{{ $l->expires_on?->format('j M Y') }}</td><td class="num">{{ $q($l) }}</td></tr>
        @endforeach
        </tbody>
        @if ($a->status === 'posted')<tfoot><tr><th colspan="4">Value at average cost</th><th class="num total">{{ \App\Filament\Support\FinanceUi::money(abs((int) $a->value_minor)) }}</th></tr></tfoot>@endif
    </table>
    @if ($a->note)<p class="small">{{ $a->note }}</p>@endif
    <p class="small" style="margin-top: 28px">Pharmacist / prepared by ______________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
    <p class="small" style="margin-top: 18px">Witness ______________________________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
    <p class="small" style="margin-top: 18px">Approved by __________________________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
@endsection

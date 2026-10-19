@extends('print.layout')
@section('title', 'Waybill '.$w->number)
@section('heading')
    <h1>WAYBILL</h1>
    <div><strong>{{ $w->number }}</strong></div>
    <div class="small">{{ \App\Models\Waybill::KINDS[$w->kind] }} · {{ $w->source_number }}</div>
    <div class="small">{{ $w->created_at->format('j M Y') }} · {{ \App\Models\Waybill::STATUSES[$w->status] }}</div>
    @if ($w->reference)<div class="small">Their waybill: {{ $w->reference }}</div>@endif
@endsection
@section('content')
    <div class="parties">
        <div class="box"><div class="small muted">From</div><strong>{{ $w->from_name }}</strong>@if ($w->from_address)<div class="small">{{ $w->from_address }}</div>@endif</div>
        <div class="box"><div class="small muted">To</div><strong>{{ $w->to_name }}</strong>@if ($w->to_address)<div class="small">{{ $w->to_address }}</div>@endif
            @if ($w->to_phone)<div class="small">Tel {{ $w->to_phone }}</div>@endif</div>
    </div>
    <table style="margin-bottom: 12px">
        <tr><th>Transporter</th><td>{{ $w->transporter ?? '—' }}</td><th>Vehicle</th><td>{{ $w->vehicle_no ?? '—' }}</td></tr>
        <tr><th>Driver</th><td>{{ $w->driver_name ?? '—' }} {{ $w->driver_phone }}</td><th>Packages / weight</th><td>{{ $w->packages ?? '—' }} / {{ $w->weight_kg ? $w->weight_kg.' kg' : '—' }}</td></tr>
    </table>
    <table>
        <thead><tr><th>#</th><th>SKU</th><th>Description</th><th class="num">Quantity</th><th class="num">Received</th></tr></thead>
        <tbody>
        @foreach ($w->lines as $i => $l)
            <tr><td>{{ $i + 1 }}</td><td class="small">{{ $l['sku'] }}</td><td>{{ $l['name'] }}</td>
                <td class="num">{{ rtrim(rtrim($l['qty'], '0'), '.') }} {{ $l['unit'] }}</td><td class="num">________</td></tr>
        @endforeach
        </tbody>
    </table>
    @if ($w->notes)<p class="small">{{ $w->notes }}</p>@endif
    <div style="display: flex; justify-content: space-between; gap: 24px; margin-top: 24px; align-items: flex-end">
        <div class="small" style="flex: 1">
            <p>Dispatched by ______________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
            <p style="margin-top: 18px">Driver ______________________________ &nbsp; Signature ____________</p>
            <p style="margin-top: 18px">Received in good order by ______________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
            @if ($w->delivered_at)<p><strong>Received by {{ $w->received_by_name }} on {{ $w->delivered_at->format('j M Y H:i') }}</strong></p>@endif
        </div>
        @if ($qr)
            <div style="text-align: center; width: 150px">{!! $qr !!}<div class="small">Receiver: scan to confirm delivery</div></div>
        @endif
    </div>
@endsection

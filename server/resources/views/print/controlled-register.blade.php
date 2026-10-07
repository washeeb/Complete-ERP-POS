@extends('print.layout')
@php($q = fn ($v) => \App\Filament\Support\Fields::qty($v))
@section('title', 'Controlled-drug register '.$from.' to '.$to)
@section('heading')
    <h1>CONTROLLED-DRUG REGISTER</h1>
    <div><strong>{{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}</strong></div>
    <div class="small">{{ $warehouse->code }} · {{ $warehouse->name }}</div>
    @if ($licence)<div class="small">Premises licence {{ $licence }}</div>@endif
    @if ($superintendent)<div class="small">Superintendent: {{ $superintendent }}</div>@endif
@endsection
@section('content')
    @forelse ($products as $p)
        <h3 style="margin: 14px 0 4px">{{ $p['product']->name }} @if ($p['product']->strength) {{ $p['product']->strength }} @endif
            <span class="small muted">{{ $p['product']->sku }} @if ($p['product']->fda_reg_no) · FDA {{ $p['product']->fda_reg_no }} @endif</span></h3>
        <table>
            <thead><tr><th>Date</th><th>Entry</th><th>Batch</th><th>Patient / prescriber</th><th>By</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr></thead>
            <tbody>
            <tr><td colspan="7" class="muted">Balance brought forward</td><td class="num">{{ $q($p['opening']) }}</td></tr>
            @foreach ($p['rows'] as $r)
                <tr><td>{{ \Illuminate\Support\Carbon::parse($r->occurred_at)->format('j M Y H:i') }}</td>
                    <td>{{ str_replace('_', ' ', $r->type) }}@if ($r->receipt_no)<br><span class="small">{{ $r->receipt_no }} @if ($r->rx_number) · {{ $r->rx_number }} @endif</span>@elseif ($r->reason)<br><span class="small">{{ $r->reason }}</span>@endif</td>
                    <td>{{ $r->batch_no }}</td>
                    <td>@if ($r->patient){{ $r->patient }}<br><span class="small">{{ $r->prescriber_name }} @if ($r->prescriber_reg_no) ({{ $r->prescriber_reg_no }}) @endif</span>@endif</td>
                    <td>{{ $r->pharmacist_name ?? $r->actor_name }}</td>
                    <td class="num">{{ (float) $r->qty_base > 0 ? $q($r->qty_base) : '' }}</td>
                    <td class="num">{{ (float) $r->qty_base < 0 ? $q(ltrim((string) $r->qty_base, '-')) : '' }}</td>
                    <td class="num">{{ $q($r->balance) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><th colspan="7">Balance carried forward</th><th class="num">{{ $q($p['closing']) }}</th></tr></tfoot>
        </table>
    @empty
        <p>No controlled medicines moved in this period.</p>
    @endforelse
    <p class="small" style="margin-top: 24px">Checked by (pharmacist) ______________________ &nbsp; Signature ____________ &nbsp; Date ________</p>
@endsection

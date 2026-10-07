@extends('print.layout')
@php($m = fn (int $minor) => \App\Support\Money::display($minor, $currency))
@section('title', 'Pay sheet '.$run->number)
@section('heading')
    <h1>PAY SHEET</h1>
    <div><strong>{{ $run->period_start->format('F Y') }}</strong></div>
    <div class="small">{{ $run->number }} · {{ \App\Models\PayrollRun::STATUSES[$run->status] ?? $run->status }}</div>
@endsection
@section('content')
    <table>
        <thead><tr><th>Employee</th><th>Paid by</th><th>Account / wallet</th><th class="num">Net pay</th><th>Signature (cash)</th></tr></thead>
        <tbody>
        @foreach ($slips as $p)
            @php($e = $p->employee)
            <tr><td>{{ $e['name'] }}<br><span class="small muted">{{ $e['number'] }}</span></td>
                <td>{{ \App\Models\Employee::PAY_METHODS[$e['pay_method']] ?? $e['pay_method'] }}</td>
                <td>{{ $e['pay_method'] === 'bank' ? ($e['bank_name'].' '.$e['bank_account_no']) : ($e['pay_method'] === 'momo' ? $e['momo_number'] : '') }}</td>
                <td class="num">{{ $m($p->net_minor) }}</td>
                <td style="min-width: 40mm">{{ $e['pay_method'] === 'cash' ? '' : '—' }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><th colspan="3">Total</th><th class="num">{{ $m((int) $slips->sum('net_minor')) }}</th><th></th></tr></tfoot>
    </table>
@endsection

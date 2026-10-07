@extends('print.layout')
@php($m = fn (int $minor) => \App\Support\Money::display($minor, $currency))
@section('title', 'Payslips '.$run->number)
@section('heading')
    <h1>PAYSLIP</h1>
    <div><strong>{{ $run->period_start->format('F Y') }}</strong></div>
    <div class="small">{{ $run->number }} · paid {{ $run->pay_date->format('j M Y') }}</div>
    @if ($run->status === 'draft')<div class="small" style="color:#b91c1c"><strong>DRAFT — not approved</strong></div>@endif
@endsection
@section('content')
    @foreach ($slips as $p)
        @php($e = $p->employee)
        <div style="{{ $loop->last ? '' : 'page-break-after: always; margin-bottom: 24px' }}">
            <div class="parties">
                <div><strong>{{ $e['name'] }}</strong><br><span class="small">{{ $e['number'] }} · {{ $e['job_title'] ?? '' }} {{ $e['department'] ? '· '.$e['department'] : '' }}</span></div>
                <div class="right small">SSNIT {{ $e['ssnit_no'] ?: '—' }}<br>Ghana Card {{ $e['ghana_card_no'] ?: '—' }}<br>
                    Paid by {{ \App\Models\Employee::PAY_METHODS[$e['pay_method']] ?? $e['pay_method'] }} {{ $e['pay_method'] === 'bank' ? ($e['bank_name'].' '.$e['bank_account_no']) : ($e['pay_method'] === 'momo' ? $e['momo_number'] : '') }}</div>
            </div>
            <table>
                <thead><tr><th>Earnings</th><th class="num">Amount</th><th>Deductions</th><th class="num">Amount</th></tr></thead>
                @php($earn = collect($p->lines)->where('kind', 'earning')->values())
                @php($ded = collect($p->lines)->where('kind', 'deduction')->values())
                <tbody>
                @for ($i = 0; $i < max($earn->count(), $ded->count()); $i++)
                    <tr><td>{{ $earn[$i]['label'] ?? '' }}</td><td class="num">{{ isset($earn[$i]) ? $m($earn[$i]['amount_minor']) : '' }}</td>
                        <td>{{ $ded[$i]['label'] ?? '' }}</td><td class="num">{{ isset($ded[$i]) ? $m($ded[$i]['amount_minor']) : '' }}</td></tr>
                @endfor
                </tbody>
                <tfoot>
                    <tr><th>Gross pay</th><th class="num">{{ $m($p->gross_minor) }}</th><th>Total deductions</th><th class="num">{{ $m($p->gross_minor - $p->net_minor) }}</th></tr>
                    <tr><td colspan="3" class="total">NET PAY</td><td class="num total">{{ $m($p->net_minor) }}</td></tr>
                </tfoot>
            </table>
            <p class="small muted">Taxable income {{ $m($p->taxable_minor) }} · Employer SSNIT {{ $m($p->ssnit_employer_minor) }} (Tier 1 {{ $m($p->tier1_minor) }}, Tier 2 {{ $m($p->tier2_minor) }})
                · Days worked {{ $p->days_worked }} · Overtime {{ number_format($p->overtime_hours, 2) }} h</p>
        </div>
    @endforeach
@endsection

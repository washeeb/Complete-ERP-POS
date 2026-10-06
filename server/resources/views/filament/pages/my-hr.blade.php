<x-filament-panels::page>
    <x-filament::section heading="Leave left this year">
        <div class="mh-cards">
            @foreach ($this->balances() as $b)
                <div class="mh-card"><div class="mh-num">{{ $b['left'] === null ? '—' : $b['left'] }}</div><div>{{ $b['name'] }}</div>
                    <div class="mh-small">{{ $b['allowance'] === null ? 'as needed' : 'of '.$b['allowance'].' days' }}</div></div>
            @endforeach
        </div>
    </x-filament::section>
    <x-filament::section heading="My leave requests">
        <table class="mh">
            <thead><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->requests() as $r)
                <tr><td>{{ $r->leaveType->name }}</td><td>{{ $r->starts_on->format('j M Y') }}</td><td>{{ $r->ends_on->format('j M Y') }}</td><td>{{ $r->days }}</td>
                    <td>{{ \App\Models\LeaveRequest::STATUSES[$r->status] ?? $r->status }} @if ($r->decision_note)<span class="mh-small">— {{ $r->decision_note }}</span>@endif</td>
                    <td>@if (in_array($r->status, ['requested', 'approved'], true) && ! $r->starts_on->isPast())<x-filament::link tag="button" color="danger" wire:click="cancel('{{ $r->id }}')">Cancel</x-filament::link>@endif</td></tr>
            @empty
                <tr><td colspan="6">No leave requested yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <x-filament::section heading="My payslips">
        <table class="mh">
            <tbody>
            @forelse ($this->payslips() as $p)
                <tr><td>{{ $p->run->period_start->format('F Y') }}</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($p->net_minor) }}</td>
                    <td><x-filament::link :href="route('print.payslip', $p)" target="_blank">Open payslip</x-filament::link></td></tr>
            @empty
                <tr><td>No payslips yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <style>
        .mh-cards { display: flex; flex-wrap: wrap; gap: 12px; } .mh-card { border: 1px solid rgb(0 0 0 / .1); border-radius: 8px; padding: 10px 14px; min-width: 140px; }
        .mh-num { font-size: 1.5rem; font-weight: 700; } .mh-small { font-size: .75rem; opacity: .7; }
        .mh { width: 100%; border-collapse: collapse; font-size: .875rem; } .mh td, .mh th { padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); text-align: left; } .mh .num { text-align: right; }
    </style>
</x-filament-panels::page>

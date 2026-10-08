<x-filament-panels::page>
    @php($money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v))
    <div class="cr-filters">
        <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
        <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
    </div>

    <x-filament::section heading="By staff member" description="Commission on services done (and items sold) in the period, less returns. Payroll pays each month's commission as the earning “Commission”.">
        <table class="cr">
            <thead><tr><th>Staff member</th><th class="num">Lines</th><th class="num">Value (before tax)</th><th class="num">Commission</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->summary() as $r)
                <tr><td>{{ $r->name }} <span class="muted">{{ $r->number }}</span></td><td class="num">{{ $r->lines }}</td><td class="num">{{ $money($r->base_minor) }}</td>
                    <td class="num">{{ $money($r->amount_minor) }}</td>
                    <td><x-filament::button size="sm" color="gray" wire:click="$set('employeeId', '{{ $r->employee_id }}')">Details</x-filament::button></td></tr>
            @empty
                <tr><td colspan="5">No commission in this period. Set rates under HR → Employees (Commission %) or on the product (Services section).</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    @if ($this->employeeId)
        <x-filament::section heading="Details">
            <table class="cr">
                <thead><tr><th>When</th><th>Receipt</th><th>Item</th><th class="num">Value</th><th class="num">Rate</th><th class="num">Commission</th></tr></thead>
                <tbody>
                @foreach ($this->lines() as $l)
                    <tr><td>{{ $l->occurred_at->setTimezone($this->tz())->format('j M H:i') }}</td><td>{{ $l->receipt_no }}</td><td>{{ $l->name }}</td>
                        <td class="num">{{ $money($l->base_minor) }}</td><td class="num">{{ number_format($l->rate_bp / 100, 2) }} %</td><td class="num">{{ $money($l->amount_minor) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
    <style>
        .cr-filters { display: flex; gap: 12px; flex-wrap: wrap; } .cr-filters label { display: grid; gap: 4px; font-size: .875rem; max-width: 280px; }
        .cr { width: 100%; border-collapse: collapse; font-size: .875rem; } .cr th, .cr td { padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); text-align: left; }
        .cr .num { text-align: right; } .muted { opacity: .6; font-size: .8rem; }
    </style>
</x-filament-panels::page>

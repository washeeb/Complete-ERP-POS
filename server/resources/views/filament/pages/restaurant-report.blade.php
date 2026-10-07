<x-filament-panels::page>
    @php($money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v))
    <div class="rr-filters">
        <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
        <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
    </div>

    <x-filament::section heading="Tips" description="Paid by guests on top of their bills; owed to the waiter on the bill (or the cashier) and paid out with that month’s payroll.">
        @php($tips = $this->tips())
        <table class="rr">
            <thead><tr><th>Person</th><th class="num">Bills</th><th class="num">Tips</th></tr></thead>
            <tbody>
            @forelse ($tips as $t)
                <tr><td>{{ $t->name ?? '—' }}</td><td class="num">{{ $t->bills }}</td><td class="num">{{ $money($t->tips) }}</td></tr>
            @empty
                <tr><td colspan="3">No tips in this period.</td></tr>
            @endforelse
            </tbody>
            @if ($tips->isNotEmpty())
                <tfoot><tr><th>Total</th><th class="num">{{ $tips->sum('bills') }}</th><th class="num">{{ $money($tips->sum('tips')) }}</th></tr></tfoot>
            @endif
        </table>
    </x-filament::section>

    <x-filament::section heading="Voided items" description="Items taken off bills. Items the kitchen already had need a supervisor; their food is usually wasted.">
        <table class="rr">
            <thead><tr><th>When</th><th>Bill</th><th>Item</th><th>Kitchen had it</th><th>Reason</th><th>Ordered by</th><th>Voided by</th></tr></thead>
            <tbody>
            @forelse ($this->voids() as $v)
                <tr><td>{{ \Illuminate\Support\Carbon::parse($v->added_at)->format('j M H:i') }}</td><td>{{ $v->number }}</td>
                    <td>{{ rtrim(rtrim((string) $v->qty, '0'), '.') }} × {{ $v->name }}</td><td>{{ $v->fired_at ? 'Yes' : 'No' }}</td>
                    <td>{{ $v->void_reason }}</td><td>{{ $v->added_by_name }}</td><td>{{ $v->voided_by_name }}</td></tr>
            @empty
                <tr><td colspan="7">No voids in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <style>
        .rr-filters { display: flex; gap: 12px; } .rr-filters label { display: grid; gap: 4px; font-size: .875rem; }
        .rr { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .rr th, .rr td { padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); text-align: left; } .rr .num { text-align: right; }
    </style>
</x-filament-panels::page>

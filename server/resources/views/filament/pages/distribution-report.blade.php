<x-filament-panels::page>
    @php($money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v))
    @php($pct = fn ($bp) => $bp === null ? '—' : number_format($bp / 100, 1).' %')
    <div class="dr-filters">
        <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
        <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
    </div>

    <x-filament::section heading="Reps against target" description="Sales before tax on van tills (returns netted), visits recorded on the phone, strike rate (visits that sold ÷ visits), orders taken and money collected on customer accounts.">
        <table class="dr">
            <thead><tr><th>Rep</th><th class="num">Sales</th><th class="num">Target</th><th class="num">Achieved</th><th class="num">Receipts</th><th class="num">Visits</th><th class="num">Strike rate</th><th class="num">Orders</th><th class="num">Collected</th></tr></thead>
            <tbody>
            @forelse ($this->reps() as $r)
                <tr><td>{{ $r['name'] }}</td><td class="num">{{ $money($r['sales_minor']) }}</td><td class="num">{{ $r['target_minor'] ? $money($r['target_minor']) : '—' }}</td>
                    <td class="num">{{ $pct($r['achieved_bp']) }}</td><td class="num">{{ $r['receipts'] }}</td><td class="num">{{ $r['visits'] }}{{ $r['visit_target'] ? ' / '.$r['visit_target'] : '' }}</td>
                    <td class="num">{{ $pct($r['strike_bp']) }}</td><td class="num">{{ $r['orders'] }}</td><td class="num">{{ $money($r['collections_minor']) }}</td></tr>
            @empty
                <tr><td colspan="9">No van sales, visits or targets in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Customers not visited" description="Customers on a route that runs on this day with no visit recorded.">
        <label class="dr-day">Day <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" /></x-filament::input.wrapper></label>
        <table class="dr">
            <thead><tr><th>Route</th><th class="num">Stop</th><th>Customer</th><th>Phone</th></tr></thead>
            <tbody>
            @forelse ($this->missed() as $m)
                <tr><td>{{ $m->route }}</td><td class="num">{{ $m->sequence }}</td><td>{{ $m->name }} <span class="muted">{{ $m->code }}</span></td><td>{{ $m->phone }}</td></tr>
            @empty
                <tr><td colspan="4">Every customer due that day was visited (or no route runs that day).</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Empties held by customers" description="Returnable containers sold to each customer minus those handed back, and the deposits they paid.">
        <table class="dr">
            <thead><tr><th>Customer</th><th>Container</th><th class="num">Held</th><th class="num">Deposits</th></tr></thead>
            <tbody>
            @forelse ($this->empties() as $e)
                <tr><td>{{ $e->customer }} <span class="muted">{{ $e->code }}</span></td><td>{{ $e->container }}</td><td class="num">{{ \App\Filament\Support\Fields::qty($e->held) }}</td><td class="num">{{ $money($e->deposit_minor) }}</td></tr>
            @empty
                <tr><td colspan="4">No customer holds returnable containers.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <style>
        .dr-filters { display: flex; gap: 12px; flex-wrap: wrap; } .dr-filters label, .dr-day { display: grid; gap: 4px; font-size: .875rem; max-width: 280px; margin-bottom: 8px; }
        .dr { width: 100%; border-collapse: collapse; font-size: .875rem; } .dr th, .dr td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; }
        .dr .num { text-align: right; } .muted { opacity: .6; font-size: .8rem; }
    </style>
</x-filament-panels::page>

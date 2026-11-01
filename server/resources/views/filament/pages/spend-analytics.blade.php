<x-filament-panels::page>
    @php
        $money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v);
        $a = $this->data();
        $t = $a->totals();
        $c = $a->compliance();
        $conc = $a->concentration();
        $pv = $a->priceVariance();
        $rfq = $a->rfqSavings();
        $months = $a->byMonth();
        $peak = max(1, max(array_column($months, 'spend') ?: [0]));
    @endphp
    <div class="sa-filters">
        <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
        <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
        <label>Branch <x-filament::input.wrapper><x-filament::input.select wire:model.live="branch"><option value="">All branches</option>
            @foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
    </div>

    <div class="sa-tiles">
        <div class="sa-tile"><div class="sa-k">Spend (before VAT)</div><div class="sa-v">{{ $money($t['spend']) }}</div><div class="sa-s">{{ $t['bills'] }} bill(s) from {{ $t['suppliers'] }} supplier(s)</div></div>
        <div class="sa-tile"><div class="sa-k">Bought on an approved order</div><div class="sa-v">{{ $c['on_order_pct'] }} %</div><div class="sa-s">{{ $money($t['spend'] - $c['on_order']) }} without one</div></div>
        <div class="sa-tile"><div class="sa-k">Under contracts</div><div class="sa-v">{{ $c['on_contract_pct'] }} %</div><div class="sa-s">{{ $money($c['on_contract']) }} at agreed prices</div></div>
        <div class="sa-tile"><div class="sa-k">Biggest supplier / biggest five</div><div class="sa-v">{{ $conc['top1'] }} % / {{ $conc['top5'] }} %</div><div class="sa-s">of all spend</div></div>
        <div class="sa-tile"><div class="sa-k">Billed above order prices</div><div class="sa-v">{{ $money($pv['above']) }}</div><div class="sa-s">on {{ $pv['bills_above'] }} bill(s); {{ $pv['held'] }} needed approval</div></div>
        <div class="sa-tile"><div class="sa-k">Saved by asking for quotes</div><div class="sa-v">{{ $money($rfq['saved']) }}</div>
            <div class="sa-s">{{ $rfq['rfqs'] }} request(s) awarded@if ($rfq['quotes_per_rfq'] !== null), {{ $rfq['quotes_per_rfq'] }} quotes each, {{ $rfq['response_pct'] }} % of suppliers answered@endif</div></div>
    </div>

    <x-filament::section heading="Spend by month" description="The twelve months up to the end of the period.">
        <table class="sa">
            <thead><tr><th>Month</th><th class="num">Spend</th><th class="sa-barcol"><span class="sr-only">Bar</span></th></tr></thead>
            <tbody>
            @foreach ($months as $m)
                <tr><td>{{ $m['month'] }}</td><td class="num">{{ $money($m['spend']) }}</td>
                    <td class="sa-barcol" aria-hidden="true"><div class="sa-bar" style="width: {{ round(100 * $m['spend'] / $peak) }}%"></div></td></tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <div class="sa-two">
        <x-filament::section heading="By supplier" description="The fifteen biggest.">
            <table class="sa">
                <thead><tr><th>Supplier</th><th class="num">Bills</th><th class="num">Spend</th><th class="num">Share</th></tr></thead>
                <tbody>
                @forelse ($a->bySupplier() as $r)
                    <tr><td>{{ $r['name'] }}</td><td class="num">{{ $r['bills'] }}</td><td class="num">{{ $money($r['spend']) }}</td><td class="num">{{ $r['share'] }} %</td></tr>
                @empty
                    <tr><td colspan="4">No supplier bills in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
        <x-filament::section heading="By category" description="The product's category for goods; the account for services and charges.">
            <table class="sa">
                <thead><tr><th>Category</th><th class="num">Spend</th><th class="num">Share</th></tr></thead>
                <tbody>
                @forelse ($a->byCategory() as $r)
                    <tr><td>{{ $r['name'] }}</td><td class="num">{{ $money($r['spend']) }}</td><td class="num">{{ $r['share'] }} %</td></tr>
                @empty
                    <tr><td colspan="3">No supplier bills in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>

    <div class="sa-two">
        <x-filament::section heading="How it was bought" description="Spend without an approved order skipped the approval step; rent and utilities usually have none.">
            <table class="sa">
                <thead><tr><th>Bought</th><th class="num">Spend</th></tr></thead>
                <tbody>
                    <tr><td>On an approved purchase order</td><td class="num">{{ $money($c['on_order']) }}</td></tr>
                    <tr><td>&nbsp;&nbsp;of which under a contract or blanket order</td><td class="num">{{ $money($c['on_contract']) }}</td></tr>
                    <tr><td>Received without an order</td><td class="num">{{ $money($c['received_no_order']) }}</td></tr>
                    <tr><td>Billed with no order (charges, services)</td><td class="num">{{ $money($c['no_order']) }}</td></tr>
                </tbody>
            </table>
        </x-filament::section>
        <x-filament::section heading="Services and charges by department" description="Goods for resale have no department.">
            <table class="sa">
                <thead><tr><th>Department</th><th class="num">Spend</th></tr></thead>
                <tbody>
                @forelse ($a->byDepartment() as $r)
                    <tr><td>{{ $r['name'] }}</td><td class="num">{{ $money($r['spend']) }}</td></tr>
                @empty
                    <tr><td colspan="2">No services or charges in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>
    <style>
        .sa-filters { display: flex; gap: 12px; flex-wrap: wrap; } .sa-filters label { display: grid; gap: 4px; font-size: .875rem; min-width: 160px; }
        .sa-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .sa-tile { border: 1px solid color-mix(in srgb, currentColor 10%, transparent); border-radius: 12px; padding: 12px 14px; }
        .sa-k { font-size: .8rem; opacity: .7; } .sa-v { font-size: 1.35rem; font-weight: 600; font-variant-numeric: tabular-nums; } .sa-s { font-size: .78rem; opacity: .65; }
        .sa-two { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
        .sa { width: 100%; border-collapse: collapse; font-size: .875rem; } .sa th, .sa td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; }
        .sa .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; } .sa-barcol { width: 45%; }
        .sa-bar { height: 10px; border-radius: 5px; background: var(--primary-500); min-width: 1px; }
        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    </style>
</x-filament-panels::page>

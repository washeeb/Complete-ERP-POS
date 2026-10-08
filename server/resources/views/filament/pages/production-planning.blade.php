<x-filament-panels::page>
    @php($plan = $this->plan())
    <label class="pp-filter">Warehouse
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="warehouse">
                @foreach ($this->warehouses() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>

    <x-filament::section heading="Make" description="Products with a bill of materials whose stock will not cover open customer orders, reorder levels and the needs of other work orders, after the output still to come from open work orders. Level 0 is finished goods; higher levels are sub-assemblies.">
        <table class="pp">
            <thead><tr><th>SKU</th><th>Product</th><th class="num">Level</th><th class="num">Needed</th><th class="num">On hand</th><th class="num">Coming from work orders</th><th class="num">Suggested</th><th>BOM</th><th></th></tr></thead>
            <tbody>
            @forelse ($plan['make'] as $r)
                <tr><td>{{ $r['sku'] }}</td><td>{{ $r['name'] }}</td><td class="num">{{ $r['level'] }}</td><td class="num">{{ $this->qty($r['demand']) }}</td>
                    <td class="num">{{ $this->qty($r['on_hand']) }}</td><td class="num">{{ $this->qty($r['coming']) }}</td><td class="num"><strong>{{ $this->qty($r['suggested']) }}</strong></td><td>{{ $r['bom'] }}</td>
                    <td>@if ($this->canMake())<x-filament::button size="xs" wire:click="makeOne('{{ $r['product_id'] }}')">Raise work order</x-filament::button>@endif</td></tr>
            @empty
                <tr><td colspan="9">Nothing needs making.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Buy" description="Materials the suggested and open work orders need beyond stock on hand and approved purchase orders not yet received (and to keep reorder levels). Raise purchase orders for these under Purchasing.">
        <table class="pp">
            <thead><tr><th>SKU</th><th>Material</th><th class="num">Needed</th><th class="num">On hand</th><th class="num">On order</th><th class="num">Suggested</th></tr></thead>
            <tbody>
            @forelse ($plan['buy'] as $r)
                <tr><td>{{ $r['sku'] }}</td><td>{{ $r['name'] }}</td><td class="num">{{ $this->qty($r['demand']) }}</td><td class="num">{{ $this->qty($r['on_hand']) }}</td>
                    <td class="num">{{ $this->qty($r['on_order']) }}</td><td class="num"><strong>{{ $this->qty($r['suggested']) }}</strong></td></tr>
            @empty
                <tr><td colspan="6">No materials to buy.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Work centre load" description="Hours of work still planned on open work orders at this warehouse, and how many working days that is at each work centre.">
        <table class="pp">
            <thead><tr><th>Work centre</th><th class="num">Hours planned</th><th class="num">Hours a day</th><th class="num">Days of work</th></tr></thead>
            <tbody>
            @forelse ($plan['load'] as $r)
                <tr><td>{{ $r['code'] }} · {{ $r['name'] }}</td><td class="num">{{ $r['hours'] }}</td><td class="num">{{ $r['per_day'] }}</td><td class="num">{{ $r['days'] ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="4">No open work orders with operations.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <style>
        .pp-filter { display: grid; gap: 4px; font-size: .875rem; max-width: 320px; }
        .pp { width: 100%; border-collapse: collapse; font-size: .875rem; } .pp th, .pp td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; }
        .pp .num { text-align: right; }
    </style>
</x-filament-panels::page>

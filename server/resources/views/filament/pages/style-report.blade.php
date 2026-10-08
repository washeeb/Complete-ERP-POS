<x-filament-panels::page>
    @php($money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v))
    @php($qty = fn ($v) => \App\Filament\Support\Fields::qty($v))
    <div class="sr-filters">
        <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
        <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
        <label>Season <x-filament::input.wrapper><x-filament::input.select wire:model.live="season">
            <option value="">All</option>
            @foreach ($this->seasons() as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
    </div>

    <x-filament::section heading="Sell-through by style" description="Units sold (net of returns) in the period, on hand now, and sold ÷ (sold + on hand).">
        <table class="sr">
            <thead><tr><th>Style</th><th>Season</th><th class="num">Sold</th><th class="num">On hand</th><th class="num">Sell-through</th><th class="num">Sales (before tax)</th></tr></thead>
            <tbody>
            @forelse ($this->styles() as $r)
                <tr><td>{{ $r->name }} <span class="muted">{{ $r->code }}</span></td><td>{{ $r->season }} {{ $r->collection ? '· '.$r->collection : '' }}</td>
                    <td class="num">{{ $qty($r->sold) }}</td><td class="num">{{ $qty($r->on_hand) }}</td><td class="num">{{ number_format($r->sell_through_bp / 100, 1) }} %</td>
                    <td class="num">{{ $money($r->revenue_minor) }}</td></tr>
            @empty
                <tr><td colspan="6">No styles yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    @if (\App\Filament\Support\Erp::canAnywhere('fashion.consignment'))
        <x-filament::section heading="Consignment — owed to consignors" description="Their share of consignment items sold (less returns), not yet settled, up to the end date above.">
            @php($owed = $this->owed())
            @if ($this->canSettle() && $owed->isNotEmpty())
                <label class="sr-stmt">Statement no. (optional) <x-filament::input.wrapper><x-filament::input wire:model="statementNo" /></x-filament::input.wrapper></label>
            @endif
            <table class="sr">
                <thead><tr><th>Consignor</th><th>Branch</th><th class="num">Items</th><th class="num">Sold (before tax)</th><th class="num">Owed</th><th></th></tr></thead>
                <tbody>
                @forelse ($owed as $o)
                    <tr><td>{{ $o->name }}</td><td>{{ $this->branchCode($o->branch_id) }}</td><td class="num">{{ $qty($o->qty) }}</td><td class="num">{{ $money($o->sold_minor) }}</td>
                        <td class="num">{{ $money($o->owed_minor) }}</td>
                        <td>@if ($this->canSettle())<x-filament::button size="sm" wire:click="settle('{{ $o->consignor_id }}', '{{ $o->branch_id }}')">Settle (raise bill)</x-filament::button>@endif</td></tr>
                @empty
                    <tr><td colspan="6">Nothing owed.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
    <style>
        .sr-filters { display: flex; gap: 12px; flex-wrap: wrap; } .sr-filters label, .sr-stmt { display: grid; gap: 4px; font-size: .875rem; max-width: 280px; }
        .sr { width: 100%; border-collapse: collapse; font-size: .875rem; } .sr th, .sr td { padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); text-align: left; }
        .sr .num { text-align: right; } .muted { opacity: .6; font-size: .8rem; }
    </style>
</x-filament-panels::page>

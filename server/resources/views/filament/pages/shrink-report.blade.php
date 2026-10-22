<x-filament-panels::page>
    @php($money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v))
    <div class="sr-f">
        <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
        <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
        <label>Warehouse <x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse"><option value="">All</option>
            @foreach ($this->warehouses() as $id => $l)<option value="{{ $id }}">{{ $l }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
    </div>
    <x-filament::section heading="By reason (at cost)">
        @php($r = $this->byReason())
        <table class="sr">@forelse ($r as $x)<tr><td>{{ $x->reason }}</td><td class="num">{{ $x->lines }} entries</td><td class="num">{{ $money($x->value) }}</td></tr>@empty<tr><td>No losses recorded.</td></tr>@endforelse
            @if ($r->isNotEmpty())<tr class="t"><td>Total</td><td></td><td class="num">{{ $money($r->sum('value')) }}</td></tr>@endif</table>
    </x-filament::section>
    <x-filament::section heading="Top products">
        <table class="sr">@foreach ($this->byProduct() as $x)<tr><td>{{ $x->name }} <span style="opacity:.6">{{ $x->sku }}</span></td><td class="num">{{ \App\Filament\Support\Fields::qty($x->qty) }}</td><td class="num">{{ $money($x->value) }}</td></tr>@endforeach</table>
    </x-filament::section>
    <div class="sr-grid">
        <x-filament::section heading="Last six months">
            @php($months = $this->byMonth())
            @php($top = max(1, (int) $months->max('value')))
            <table class="sr">@forelse ($months as $x)<tr><td>{{ $x->month }}</td><td style="width:50%"><div class="sr-bar" style="width: {{ round(100 * $x->value / $top) }}%"></div></td><td class="num">{{ $money($x->value) }}</td></tr>@empty<tr><td>No losses recorded.</td></tr>@endforelse</table>
        </x-filament::section>
        <x-filament::section heading="By category">
            <table class="sr">@forelse ($this->byCategory() as $x)<tr><td>{{ $x->category }}</td><td class="num">{{ $money($x->value) }}</td></tr>@empty<tr><td>No losses recorded.</td></tr>@endforelse</table>
        </x-filament::section>
        <x-filament::section heading="Recorded by">
            <table class="sr">@forelse ($this->byPerson() as $x)<tr><td>{{ $x->person }}</td><td class="num">{{ $x->lines }} entries</td><td class="num">{{ $money($x->value) }}</td></tr>@empty<tr><td>No losses recorded.</td></tr>@endforelse</table>
        </x-filament::section>
        <x-filament::section heading="Lost again and again (3 times or more)" description="Look at where these are kept and who handles them.">
            <table class="sr">@forelse ($this->repeated() as $x)<tr><td>{{ $x->name }} <span style="opacity:.6">{{ $x->sku }}</span></td><td class="num">{{ $x->times }} times</td><td class="num">{{ $money($x->value) }}</td></tr>@empty<tr><td>None in this period.</td></tr>@endforelse</table>
        </x-filament::section>
    </div>
    <style>.sr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}.sr-bar{height:10px;border-radius:4px;background:var(--danger-500);min-width:2px}.sr-f{display:flex;flex-wrap:wrap;gap:12px}.sr-f label{display:grid;gap:4px;font-size:.875rem}.sr{width:100%;border-collapse:collapse;font-size:.875rem}.sr td{padding:6px 8px;border-bottom:1px solid color-mix(in srgb, currentColor 6%, transparent)}.sr .num{text-align:right}.sr .t td{font-weight:700}</style>
</x-filament-panels::page>

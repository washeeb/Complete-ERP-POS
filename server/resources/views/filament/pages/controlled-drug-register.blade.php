<x-filament-panels::page>
    @php($qty = fn ($v) => \App\Filament\Support\Fields::qty($v))
    <div class="cd-filters">
        <label>Warehouse <x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse">
            @foreach ($this->warehouses() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
        <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
        <div class="cd-links">
            <x-filament::link :href="route('print.controlled-register', ['warehouse' => $warehouse, 'from' => $from, 'to' => $to])" target="_blank" icon="heroicon-o-printer">Print register</x-filament::link>
            @if ($this->canExportClaims())
                <x-filament::link :href="route('print.nhis-claims', ['from' => $from, 'to' => $to])" icon="heroicon-o-arrow-down-tray">NHIS claims (CSV)</x-filament::link>
            @endif
        </div>
    </div>

    @forelse ($this->register() as $p)
        <x-filament::section :heading="$p['product']->name.($p['product']->strength ? ' '.$p['product']->strength : '')" :description="'Opening '.$qty($p['opening']).' · closing '.$qty($p['closing'])">
            <div class="cd-scroll"><table class="cd">
                <thead><tr><th>Date</th><th>Entry</th><th>Batch</th><th>Patient / prescriber</th><th>By</th><th class="num">In / out</th><th class="num">Balance</th></tr></thead>
                <tbody>
                @foreach ($p['rows'] as $r)
                    <tr><td>{{ \Illuminate\Support\Carbon::parse($r->occurred_at)->format('j M H:i') }}</td>
                        <td>{{ str_replace('_', ' ', $r->type) }} <span class="muted">{{ $r->receipt_no }} {{ $r->rx_number }}</span></td>
                        <td>{{ $r->batch_no }}</td>
                        <td>{{ $r->patient }} @if ($r->prescriber_name)<span class="muted">· {{ $r->prescriber_name }}</span>@endif</td>
                        <td>{{ $r->pharmacist_name ?? $r->actor_name }}</td>
                        <td class="num">{{ $qty($r->qty_base) }}</td><td class="num">{{ $qty($r->balance) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </x-filament::section>
    @empty
        <x-filament::section>No controlled medicines moved here in this period. Mark a product's schedule as "Controlled drug" to keep it in this register.</x-filament::section>
    @endforelse
    <style>
        .cd-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; } .cd-filters label { display: grid; gap: 4px; font-size: .875rem; }
        .cd-links { display: flex; gap: 16px; padding-bottom: 8px; }
        .cd-scroll { overflow-x: auto; } .cd { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .cd th, .cd td { padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); text-align: left; } .cd .num { text-align: right; }
        .muted { opacity: .6; font-size: .8rem; }
    </style>
</x-filament-panels::page>

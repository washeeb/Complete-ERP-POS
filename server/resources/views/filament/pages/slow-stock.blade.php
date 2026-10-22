<x-filament-panels::page>
    @php($money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v))
    @php($rows = $this->rows())
    <div class="ss-f">
        <label>Store <x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse">
            @foreach ($this->warehouses() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label>Not sold for <x-filament::input.wrapper><x-filament::input.select wire:model.live="days">
            @foreach (\App\Filament\Pages\SlowStock::PERIODS as $d => $label)<option value="{{ $d }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
    </div>

    <x-filament::section :heading="$rows->count().' product(s), '.$money($rows->sum('value')).' at cost'"
        description="Not sold here in the period (or, if never sold, since it came in). Move it where it sells, ask the supplier to take it back, or mark it down.">
        @if ($rows->isEmpty())
            <p>Nothing slow in this store. Well done.</p>
        @else
            <div class="ss-scroll"><table class="ss">
                <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Value</th><th>Last sold</th><th>Suggestion</th></tr></thead>
                <tbody>@foreach ($rows as $r)
                    <tr>
                        <td>{{ $r->name }} <span class="muted">{{ $r->sku }}</span></td>
                        <td class="num">{{ \App\Filament\Support\Fields::qty($r->on_hand) }}</td>
                        <td class="num">{{ $money($r->value) }}</td>
                        <td>{{ $r->last_sold ? \Illuminate\Support\Carbon::parse($r->last_sold)->format('j M Y') : 'never' }} <span class="muted">({{ $r->idle_days }} days)</span></td>
                        <td>
                            @if ($r->move_to)
                                Sells at {{ $r->move_to->code }} ({{ \App\Filament\Support\Fields::qty($r->move_to->sold) }} in 90 days) — {{ ($this->moveAction)(['product' => $r->product_id, 'to' => $r->move_to->id]) }}
                            @elseif ($r->supplier)
                                Ask {{ $r->supplier }} to take it back (Stock → Expiry & returns).
                            @else
                                Mark it down or put it in a bundle.
                            @endif
                        </td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </x-filament::section>

    <style>
        .ss-f { display: flex; flex-wrap: wrap; gap: 12px; } .ss-f label { display: grid; gap: 4px; font-size: .875rem; }
        .ss-scroll { overflow-x: auto; } .ss { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .ss th, .ss td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; vertical-align: top; }
        .ss .num { text-align: right; white-space: nowrap; } .muted { opacity: .6; }
    </style>
</x-filament-panels::page>

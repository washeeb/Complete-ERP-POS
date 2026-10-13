<x-filament-panels::page>
    <p class="si-note">Load these into the scales. Their labels start with {{ $this->prefixes() }}, then the scale code (PLU), then the weight in grams; the till reads them and charges by weight. Give a product its scale code under Catalog → Products.</p>
    <x-filament::section>
        <div class="si-scroll"><table class="si">
            <thead><tr><th>Scale code (PLU)</th><th>SKU</th><th>Product</th><th>Unit</th><th class="num">Retail price per unit</th></tr></thead>
            <tbody>
            @forelse ($this->items() as $i)
                <tr><td class="mono">{{ str_pad($i->plu, 5, '0', STR_PAD_LEFT) }}</td><td>{{ $i->sku }}</td><td>{{ $i->name }}</td><td>{{ $i->unit }}</td>
                    <td class="num">{{ $i->amount_minor !== null ? \App\Filament\Support\FinanceUi::money((int) $i->amount_minor) : 'No retail price' }}</td></tr>
            @empty
                <tr><td colspan="5">No product has a scale code yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </x-filament::section>
    <style>
        .si-note { font-size: .875rem; opacity: .8; }
        .si-scroll { overflow-x: auto; } .si { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .si th, .si td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; } .si .num { text-align: right; }
        .mono { font-family: ui-monospace, monospace; }
    </style>
</x-filament-panels::page>

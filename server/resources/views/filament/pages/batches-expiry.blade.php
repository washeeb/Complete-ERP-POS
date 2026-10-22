<x-filament-panels::page>
    @php($money = fn ($v) => \App\Filament\Support\FinanceUi::money((int) $v))
    @php($qty = fn ($v) => \App\Filament\Support\Fields::qty($v))
    <div class="be-filters">
        <label>Warehouse <x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse">
            @foreach ($this->warehouses() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
    </div>

    @if ($this->canMarkDown())
        <x-filament::section heading="Expiring soon — suggested markdowns" description="Apply, then print the stickers and put them on the packs. The till charges the sticker price and takes the stock from that batch.">
            <div class="be-scroll"><table class="be">
                <thead><tr><th>Product</th><th>Batch</th><th>Expires</th><th class="num">On hand</th><th>% off</th><th></th></tr></thead>
                <tbody>
                @forelse ($this->suggestions() as $s)
                    <tr><td>{{ $s->name }} <span class="muted">{{ $s->sku }}</span></td><td>{{ $s->batch_no }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($s->expires_on)->format('j M') }} <span class="muted">({{ $s->days_left }} d)</span></td>
                        <td class="num">{{ $qty($s->on_hand) }}</td>
                        <td><input class="be-pct" inputmode="decimal" wire:model="percent.{{ $s->batch_id }}" aria-label="Percent off {{ $s->name }}"></td>
                        <td><x-filament::button size="sm" wire:click="apply('{{ $s->batch_id }}')">Apply</x-filament::button></td></tr>
                @empty
                    <tr><td colspan="6">Nothing expiring soon.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>

        <x-filament::section heading="Marked down now">
            @php($mds = $this->markdowns())
            @if ($mds->isNotEmpty())
                <p><x-filament::link :href="route('print.markdown-labels', ['ids' => $mds->pluck('id')->implode(',')])" target="_blank">Print all stickers</x-filament::link></p>
            @endif
            <table class="be">
                @forelse ($mds as $m)
                    <tr><td>{{ $m->product->name }}</td><td>{{ $m->batch->batch_no }}</td><td>{{ $m->percent_bp / 100 }} % off · {{ $money($m->price_minor) }}</td>
                        <td>until {{ $m->valid_until->format('j M') }}</td><td class="mono">{{ $m->code }}</td>
                        <td><x-filament::link tag="button" color="danger" wire:click="withdraw('{{ $m->id }}')">Withdraw</x-filament::link></td></tr>
                @empty
                    <tr><td>No markdowns.</td></tr>
                @endforelse
            </table>
        </x-filament::section>
    @endif

    <x-filament::section heading="Expired — take off the shelf" description="Dispose of them or return them to the supplier from Stock → Expiry & returns (approved by a second person).">
        @if (\App\Filament\Pages\ExpiryReturns::canAccess())<p><x-filament::link :href="\App\Filament\Pages\ExpiryReturns::getUrl()">Open Expiry & returns</x-filament::link></p>@endif
        <table class="be">
            @forelse ($this->expired() as $e)
                <tr><td>{{ $e->name }}</td><td>{{ $e->batch_no }}</td><td>expired {{ \Illuminate\Support\Carbon::parse($e->expires_on)->format('j M Y') }}</td>
                    <td class="num">{{ $qty($e->on_hand) }}</td>
                    <td>@if ($this->canCorrect()){{ ($this->correctAction)(['batch' => $e->batch_id]) }}@endif</td></tr>
            @empty
                <tr><td>Nothing expired in stock.</td></tr>
            @endforelse
        </table>
    </x-filament::section>

    @unless ($markdownsOnly)
    <x-filament::section heading="All batches on hand" description="Correct an expiry date typed wrongly, or add a missing one. For a wrong batch number, use Add or move a batch." collapsible collapsed>
        <table class="be">
            <thead><tr><th>Product</th><th>Batch</th><th>Expires</th><th class="num">On hand</th><th></th></tr></thead>
            @foreach ($this->batches() as $b)
                <tr><td>{{ $b->name }} <span class="muted">{{ $b->sku }}</span></td><td>{{ $b->batch_no }}</td><td>{{ $b->expires_on ? \Illuminate\Support\Carbon::parse($b->expires_on)->format('j M Y') : 'no date' }}</td><td class="num">{{ $qty($b->on_hand) }}</td>
                    <td>@if ($this->canCorrect()){{ ($this->correctAction)(['batch' => $b->batch_id]) }}@endif</td></tr>
            @endforeach
        </table>
    </x-filament::section>
    @endunless
    <style>
        .be-filters { display: flex; gap: 12px; } .be-filters label { display: grid; gap: 4px; font-size: .875rem; }
        .be-scroll { overflow-x: auto; } .be { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .be th, .be td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; } .be .num { text-align: right; }
        .be-pct { width: 64px; padding: 2px 6px; border: 1px solid color-mix(in srgb, currentColor 20%, transparent); border-radius: 4px; background: transparent; color: inherit; text-align: right; }
        .muted { opacity: .6; font-size: .8rem; } .mono { font-family: ui-monospace, monospace; }
    </style>
</x-filament-panels::page>

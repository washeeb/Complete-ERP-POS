<x-filament-panels::page>
    <div class="md-filters">
        <label>Store <x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse">
            @foreach ($this->warehouses() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
    </div>

    @php($cycle = $this->cycle())
    @if ($cycle['on'])
        <x-filament::section heading="Today's cycle count" :description="'A few products a day instead of a big stock-take: fast-moving and valuable ones more often. Up to '.$cycle['per_day'].' a day (System → Settings → Stock).'">
            @if ($cycle['started'])
                <p>Started: <x-filament::link :href="$cycle['url']">{{ $cycle['started']->number }}</x-filament::link> — {{ \App\Models\StockCount::STATUSES[$cycle['started']->status] ?? $cycle['started']->status }}.</p>
            @elseif ($cycle['due']->isEmpty())
                <p>Nothing is due today. Well done.</p>
            @else
                <div class="md-scroll"><table class="md">
                    <thead><tr><th>Product</th><th>Class</th><th>Last counted</th><th></th></tr></thead>
                    <tbody>@foreach ($cycle['due'] as $d)
                        <tr><td>{{ $d->name }} <span class="muted">{{ $d->sku }}</span></td><td>{{ $d->class }}</td>
                            <td>{{ $d->last_counted ? \Illuminate\Support\Carbon::parse($d->last_counted)->format('j M Y') : 'never' }}</td>
                            <td>@if ($d->often_wrong)<span class="md-flag">often wrong</span>@endif</td></tr>
                    @endforeach</tbody>
                </table></div>
                <p class="md-actions">{{ $this->startCycleAction }}</p>
            @endif
            @if ($cycle['wrong']->isNotEmpty())
                <p class="muted small">Often wrong when counted (counted twice as often): {{ $cycle['wrong']->implode(', ') }}.</p>
            @endif
        </x-filament::section>
    @endif

    <div class="md-cards">
        @foreach ($this->cards() as $c)
            @php($tag = $c['url'] ? 'a' : 'div')
            <{{ $tag }} @if ($c['url']) href="{{ $c['url'] }}" @endif class="md-card md-{{ $c['value'] ? $c['tone'] : 'ok' }}">
                <span class="md-value">{{ number_format($c['value']) }}</span>
                <span class="md-label">{{ $c['label'] }}</span>
                <span class="md-hint">{{ $c['hint'] }}</span>
            </{{ $tag }}>
        @endforeach
    </div>

    <style>
        .md-filters { display: flex; gap: 12px; } .md-filters label { display: grid; gap: 4px; font-size: .875rem; }
        .md-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; }
        .md-card { display: grid; gap: 2px; padding: 14px 16px; border-radius: 12px; border: 1px solid color-mix(in srgb, currentColor 10%, transparent); text-decoration: none; color: inherit; }
        a.md-card:hover { border-color: var(--primary-500); }
        .md-value { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
        .md-label { font-weight: 600; } .md-hint { font-size: .8rem; opacity: .7; }
        .md-danger .md-value { color: var(--danger-600); } .md-warning .md-value { color: var(--warning-600); } .md-ok .md-value { opacity: .55; }
        .md-scroll { overflow-x: auto; } .md { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .md th, .md td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; }
        .md-flag { font-size: .75rem; padding: 2px 6px; border-radius: 6px; background: color-mix(in srgb, var(--warning-500) 20%, transparent); }
        .md-actions { margin-top: 12px; } .muted { opacity: .6; } .small { font-size: .8rem; }
    </style>
</x-filament-panels::page>

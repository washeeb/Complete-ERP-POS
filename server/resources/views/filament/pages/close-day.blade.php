<x-filament-panels::page>
    @php($state = $this->state())
    @php($closed = $this->closed())
    @php($money = fn ($v) => \App\Support\Money::display((int) $v, $state['currency']))
    @php($problems = collect($state['checks'])->where('status', 'fail')->count())

    <div class="cd-filters">
        <label>Branch <x-filament::input.wrapper><x-filament::input.select wire:model.live="branch">
            @foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label>Day <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" /></x-filament::input.wrapper></label>
    </div>

    @if ($closed)
        <p class="cd-banner cd-ok">Closed by {{ $closed->closed_by_name }} at {{ $closed->closed_at->setTimezone($this->tz())->format('j M Y H:i') }}{{ $closed->problems ? ' — with '.$closed->problems.' problem(s): '.$closed->note : '' }}. You can close it again after fixing something.</p>
    @endif

    <div class="cd-list">
        @foreach ($state['checks'] as $c)
            <div class="cd-item cd-{{ $c['status'] }}">
                <span class="cd-mark" aria-hidden="true">{{ ['ok' => '✓', 'warn' => '!', 'fail' => '✗'][$c['status']] }}</span>
                <span><strong>{{ $c['label'] }}</strong><br><span class="cd-detail">{{ $c['detail'] }}</span></span>
            </div>
        @endforeach
    </div>

    <x-filament::section heading="Close the day" :description="'Cash counted at the till closes: '.$money($state['cash_counted']).'. Say where it went.'">
        <form wire:submit="closeDay" class="cd-form">
            <label>Taken to the bank <x-filament::input.wrapper><x-filament::input type="text" inputmode="decimal" wire:model="banked" placeholder="0.00" /></x-filament::input.wrapper></label>
            <label>Deposit slip no. <x-filament::input.wrapper><x-filament::input type="text" wire:model="bankRef" maxlength="60" /></x-filament::input.wrapper></label>
            <label>Kept in the safe <x-filament::input.wrapper><x-filament::input type="text" inputmode="decimal" wire:model="safe" placeholder="0.00" /></x-filament::input.wrapper></label>
            <label class="cd-wide">{{ $problems ? 'Why the day is closed with '.$problems.' problem(s) (required)' : 'Note (optional)' }}
                <x-filament::input.wrapper><x-filament::input type="text" wire:model="note" maxlength="500" /></x-filament::input.wrapper></label>
            <div class="cd-wide"><x-filament::button type="submit" :color="$problems ? 'warning' : 'primary'" icon="heroicon-o-check-badge">{{ $closed ? 'Close the day again' : 'Close the day' }}</x-filament::button></div>
        </form>
    </x-filament::section>

    @php($recent = $this->recent())
    @if ($recent->isNotEmpty())
        <x-filament::section heading="Recent days">
            <div class="cd-scroll"><table class="cd-table">
                <thead><tr><th>Day</th><th>Closed by</th><th class="num">Cash counted</th><th class="num">Over / short</th><th class="num">Banked</th><th>Slip</th><th class="num">Safe</th><th>Problems</th></tr></thead>
                <tbody>@foreach ($recent as $r)
                    <tr><td>{{ $r->business_date->format('D j M') }}</td><td>{{ $r->closed_by_name }}</td><td class="num">{{ $money($r->cash_counted_minor) }}</td>
                        <td class="num {{ $r->over_short_minor < 0 ? 'cd-neg' : '' }}">{{ $money($r->over_short_minor) }}</td>
                        <td class="num">{{ $r->banked_minor !== null ? $money($r->banked_minor) : '—' }}</td><td>{{ $r->bank_ref ?? '—' }}</td>
                        <td class="num">{{ $r->safe_minor !== null ? $money($r->safe_minor) : '—' }}</td><td>{{ $r->problems ? $r->problems.': '.$r->note : '—' }}</td></tr>
                @endforeach</tbody>
            </table></div>
        </x-filament::section>
    @endif

    <style>
        .cd-filters { display: flex; flex-wrap: wrap; gap: 12px; } .cd-filters label, .cd-form label { display: grid; gap: 4px; font-size: .875rem; }
        .cd-banner { padding: 10px 14px; border-radius: 10px; } .cd-banner.cd-ok { background: color-mix(in srgb, var(--success-500) 15%, transparent); }
        .cd-list { display: grid; gap: 8px; }
        .cd-item { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; border: 1px solid color-mix(in srgb, currentColor 10%, transparent); }
        .cd-mark { width: 26px; height: 26px; flex: none; border-radius: 50%; display: grid; place-items: center; font-weight: 700; color: #fff; }
        .cd-ok .cd-mark { background: var(--success-600); } .cd-warn .cd-mark { background: var(--warning-500); } .cd-fail .cd-mark { background: var(--danger-600); }
        .cd-fail { border-color: var(--danger-500); } .cd-detail { font-size: .85rem; opacity: .75; }
        .cd-form { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; } .cd-wide { grid-column: 1 / -1; }
        .cd-scroll { overflow-x: auto; } .cd-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .cd-table th, .cd-table td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; white-space: nowrap; }
        .cd-table .num { text-align: right; } .cd-neg { color: var(--danger-600); }
    </style>
</x-filament-panels::page>

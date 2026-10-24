<x-filament-panels::page>
    @php($d = $this->data())
    <div class="ot" wire:poll.60s>
        <div class="ot-tabs" role="tablist">
            <button type="button" role="tab" aria-selected="{{ $yesterday ? 'false' : 'true' }}" class="{{ $yesterday ? '' : 'on' }}" wire:click="$set('yesterday', false)">Today</button>
            <button type="button" role="tab" aria-selected="{{ $yesterday ? 'true' : 'false' }}" class="{{ $yesterday ? 'on' : '' }}" wire:click="$set('yesterday', true)">Yesterday</button>
            <span class="ot-date">{{ $d['date'] }}</span>
        </div>

        <section class="ot-hero">
            <span class="ot-label">Sales</span>
            <span class="ot-big">{{ $d['sales'] }}</span>
            <span class="ot-sub">{{ $d['count'] }} sale(s)
                @if ($d['vs_last_week'] !== null) · <b class="{{ $d['vs_last_week'] >= 0 ? 'ot-up' : 'ot-down' }}">{{ $d['vs_last_week'] >= 0 ? '+' : '' }}{{ $d['vs_last_week'] }} %</b> on last week ({{ $d['last_week'] }})@endif
            </span>
        </section>

        <section class="ot-row">
            @foreach ($d['payments'] as $label => $value)
                <div class="ot-mini"><span class="ot-label">{{ $label }}</span><span class="ot-mid">{{ $value }}</span></div>
            @endforeach
        </section>

        <section class="ot-row">
            <div class="ot-mini"><span class="ot-label">Till closes</span><span class="ot-mid">{{ $d['closes'] }}</span></div>
            <div class="ot-mini {{ $d['over_short'] < 0 ? 'ot-bad' : '' }}"><span class="ot-label">Cash</span><span class="ot-mid">{{ $d['over_short_text'] }}</span></div>
        </section>

        @if ($d['attention'])
            <section class="ot-card">
                <h3>Needs attention</h3>
                @foreach ($d['attention'] as $a)
                    @php($url = $this->link($a['link']))
                    <{{ $url ? 'a' : 'div' }} @if ($url) href="{{ $url }}" @endif class="ot-alert ot-{{ $a['tone'] }}">{{ $a['text'] }}@if ($url) <span aria-hidden="true">›</span>@endif</{{ $url ? 'a' : 'div' }}>
                @endforeach
            </section>
        @endif

        <section class="ot-card">
            <h3>Branches</h3>
            @foreach ($d['branches'] as $b)
                <div class="ot-branch">
                    <div class="ot-branch-head"><strong>{{ $b['name'] }}</strong><span>{{ $b['sales'] }}</span></div>
                    <div class="ot-sub">{{ $b['count'] }} sale(s)
                        @if ($b['open']) · <span class="ot-warn-text">open: {{ implode(', ', $b['open']) }}</span>@endif</div>
                    <div class="ot-sub">{!! $b['closed'] ? '✓ '.e($b['closed']) : '<span class="ot-warn-text">Day not closed yet</span>' !!}</div>
                    @if ($b['deposit'])<div class="ot-sub">{{ $b['deposit'] }}</div>@endif
                </div>
            @endforeach
        </section>

        <p class="ot-foot">Updates every minute. On your phone: browser menu → <b>Add to Home screen</b> to open it like an app.</p>
    </div>

    <style>
        .ot { display: grid; gap: 12px; max-width: 640px; margin: 0 auto; width: 100%; }
        .ot-tabs { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .ot-tabs button { padding: 8px 14px; border-radius: 999px; border: 1px solid color-mix(in srgb, currentColor 20%, transparent); font-weight: 600; }
        .ot-tabs button.on { background: var(--primary-600); color: #fff; border-color: transparent; }
        .ot-date { margin-left: auto; opacity: .65; font-size: .9rem; }
        .ot-hero, .ot-mini, .ot-card { border-radius: 16px; padding: 16px; border: 1px solid color-mix(in srgb, currentColor 10%, transparent); background: color-mix(in srgb, currentColor 3%, transparent); }
        .ot-hero { display: grid; gap: 4px; }
        .ot-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; opacity: .65; }
        .ot-big { font-size: 2.4rem; font-weight: 800; line-height: 1.1; }
        .ot-mid { font-size: 1.15rem; font-weight: 700; }
        .ot-sub { font-size: .9rem; opacity: .85; }
        .ot-up { color: var(--success-600); } .ot-down { color: var(--danger-600); }
        .ot-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
        .ot-mini { display: grid; gap: 4px; } .ot-bad .ot-mid { color: var(--danger-600); }
        .ot-card h3 { font-weight: 700; margin-bottom: 8px; }
        .ot-alert { display: flex; justify-content: space-between; gap: 8px; padding: 10px 12px; border-radius: 10px; margin-top: 6px; text-decoration: none; color: inherit; }
        .ot-danger { background: color-mix(in srgb, var(--danger-500) 18%, transparent); } .ot-warning { background: color-mix(in srgb, var(--warning-500) 18%, transparent); }
        .ot-branch { padding: 10px 0; border-top: 1px solid color-mix(in srgb, currentColor 8%, transparent); } .ot-branch:first-of-type { border-top: 0; }
        .ot-branch-head { display: flex; justify-content: space-between; gap: 8px; font-size: 1.05rem; }
        .ot-warn-text { color: var(--warning-600); }
        .ot-foot { font-size: .8rem; opacity: .6; text-align: center; }
    </style>
</x-filament-panels::page>

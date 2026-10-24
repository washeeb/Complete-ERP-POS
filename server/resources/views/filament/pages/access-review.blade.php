<x-filament-panels::page>
    @php($r = $this->review())
    <div class="ar-sum">
        <div><span class="ar-big">{{ $r['counts']['people'] }}</span> people with access</div>
        <div class="{{ $r['counts']['high'] ? 'ar-high' : '' }}"><span class="ar-big">{{ $r['counts']['high'] }}</span> high-risk combination(s)</div>
        <div class="{{ $r['counts']['medium'] ? 'ar-med' : '' }}"><span class="ar-big">{{ $r['counts']['medium'] }}</span> to keep an eye on</div>
    </div>

    <x-filament::section heading="Risky combinations" description="One person holding both halves can take something and hide it. In a small team some are unavoidable: then check that person's work in the audit log now and then.">
        @forelse ($r['risks'] as $risk)
            <div class="ar-risk ar-{{ $risk['severity'] }}">
                <div class="ar-risk-head"><span class="ar-tag">{{ $risk['severity'] === 'high' ? 'High' : 'Watch' }}</span> <strong>{{ $risk['title'] }}</strong></div>
                <p>{{ $risk['why'] }} <b>What to do:</b> {{ $risk['fix'] }}</p>
                <ul>@foreach ($risk['who'] as $w)
                    @php($url = $this->userUrl($w['id']))
                    <li>@if ($url)<a href="{{ $url }}">{{ $w['name'] }}</a>@else{{ $w['name'] }}@endif <span class="ar-muted">— {{ $w['via'] }}; {{ $w['where'] }}</span></li>
                @endforeach</ul>
            </div>
        @empty
            <p>No risky combinations: each of these jobs is split between different people.</p>
        @endforelse
    </x-filament::section>

    @if ($r['notes'])
        <x-filament::section heading="Worth a look">
            <ul class="ar-notes">@foreach ($r['notes'] as $n)
                @php($url = $this->userUrl($n['id']))
                <li class="ar-note-{{ $n['tone'] }}">{{ $n['text'] }}@if ($url) <a href="{{ $url }}">Open</a>@endif</li>
            @endforeach</ul>
        </x-filament::section>
    @endif

    <x-filament::section heading="Everyone's access" description="Each role and where it applies (a branch, a store, a till or the whole company).">
        <div class="ar-scroll"><table class="ar">
            <thead><tr><th>Person</th><th>Roles and where</th><th>Risky combinations</th></tr></thead>
            <tbody>@foreach ($r['people'] as $p)
                @php($url = $this->userUrl($p['user']->id))
                <tr>
                    <td>@if ($url)<a href="{{ $url }}">{{ $p['user']->name }}</a>@else{{ $p['user']->name }}@endif
                        <div class="ar-muted">{{ $p['user']->username }}@unless ($p['user']->is_active) · switched off @endunless</div></td>
                    <td>@foreach ($p['roles'] as $role)<div>{{ $role['role'] }} <span class="ar-muted">({{ $role['where'] }}@if ($role['until']), until {{ $role['until'] }}@endif)</span></div>@endforeach</td>
                    <td>@if ($p['owner'])<span class="ar-muted">Owner: everything, by design</span>
                        @elseif ($p['risks'])@foreach ($p['risks'] as $k)<div class="ar-{{ \App\Domain\Access\AccessReview::RULES[$k][0] }}-text">{{ \App\Domain\Access\AccessReview::RULES[$k][2] }}</div>@endforeach
                        @else<span class="ar-muted">None</span>@endif</td>
                </tr>
            @endforeach</tbody>
        </table></div>
    </x-filament::section>

    <style>
        .ar-sum { display: flex; gap: 12px; flex-wrap: wrap; }
        .ar-sum > div { flex: 1 1 160px; padding: 12px 16px; border-radius: 12px; border: 1px solid color-mix(in srgb, currentColor 12%, transparent); }
        .ar-big { font-size: 1.6rem; font-weight: 700; margin-right: 4px; }
        .ar-high { background: color-mix(in srgb, var(--danger-500) 12%, transparent); }
        .ar-med { background: color-mix(in srgb, var(--warning-500) 12%, transparent); }
        .ar-risk { border-left: 4px solid var(--warning-500); padding: 8px 12px; margin-bottom: 12px; }
        .ar-risk.ar-high { border-left-color: var(--danger-600); background: none; }
        .ar-risk p { margin: 4px 0; }
        .ar-risk ul { margin: 4px 0 0 18px; list-style: disc; }
        .ar-tag { font-size: .75rem; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; background: color-mix(in srgb, var(--warning-500) 25%, transparent); }
        .ar-high .ar-tag { background: var(--danger-600); color: #fff; }
        .ar-muted { opacity: .65; font-size: .875rem; }
        .ar-high-text { color: var(--danger-600); }
        .ar-medium-text { color: var(--warning-700); }
        .dark .ar-high-text { color: var(--danger-400); } .dark .ar-medium-text { color: var(--warning-400); }
        .ar-notes { margin-left: 18px; list-style: disc; } .ar-notes li { margin: 4px 0; }
        .ar-notes a, .ar a, .ar-risk a { color: var(--primary-600); text-decoration: underline; }
        .ar-scroll { overflow-x: auto; }
        table.ar { width: 100%; border-collapse: collapse; font-size: .9rem; }
        table.ar th, table.ar td { text-align: left; padding: 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 10%, transparent); vertical-align: top; }
    </style>
</x-filament-panels::page>

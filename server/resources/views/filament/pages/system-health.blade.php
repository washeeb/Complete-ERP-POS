<x-filament-panels::page>
    <x-filament::section description="Refresh the page to check again. The same checks run on the server with: php artisan erp:health">
        <table class="sh">
            <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
            @foreach ($this->checks() as $c)
                <tr><td>{{ $c['check'] }}</td><td class="sh-{{ $c['status'] }}">{{ ['ok' => 'OK', 'warning' => 'Look at this', 'fail' => 'Fix this'][$c['status']] }}</td><td>{{ $c['detail'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Slow pages (last 7 days)" description="Pages and till calls that took over {{ number_format(config('erp.slow_request_ms') / 1000, 1) }} s, worst first. Many database reads on one line points to a page to speed up; send this list to SyncLytics support.">
        <table class="sh">
            <thead><tr><th>Page or call</th><th class="num">Times</th><th class="num">Average</th><th class="num">Slowest</th><th class="num">Database reads</th><th>Last</th></tr></thead>
            <tbody>
            @forelse ($this->slowPages() as $p)
                <tr><td>{{ $p->method }} {{ $p->path }}</td><td class="num">{{ $p->times }}</td><td class="num">{{ number_format($p->avg_ms / 1000, 1) }} s</td>
                    <td class="num">{{ number_format($p->max_ms / 1000, 1) }} s</td><td class="num">{{ $p->avg_queries }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($p->last_at)->diffForHumans() }}</td></tr>
            @empty
                <tr><td colspan="6">None: every page answered quickly.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <style>
        .sh { width: 100%; border-collapse: collapse; font-size: .875rem; } .sh th, .sh td { padding: 6px 8px; text-align: left; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); }
        .sh .num { text-align: right; } .sh-ok { color: rgb(22 163 74); font-weight: 600; } .sh-warning { color: rgb(202 138 4); font-weight: 600; } .sh-fail { color: rgb(220 38 38); font-weight: 600; }
    </style>
</x-filament-panels::page>

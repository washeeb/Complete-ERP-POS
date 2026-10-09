<x-filament-panels::page>
    @php($s = $this->status())
    @php($labels = ['unlicensed' => 'Not activated', 'active' => 'Active', 'warning' => 'Active — attention needed', 'grace' => 'Expired — grace period', 'read_only' => 'Read-only'])
    <x-filament::section>
        <div class="lic-head">
            <x-filament::badge :color="match ($s['state']) { 'active' => 'success', 'warning', 'grace' => 'warning', default => 'danger' }">{{ $labels[$s['state']] ?? $s['state'] }}</x-filament::badge>
            @if ($s['customer'])<strong>{{ $s['customer'] }}</strong>@endif
            @if ($s['short'])<span class="lic-muted">Licence {{ $s['short'] }}</span>@endif
        </div>
        @if ($s['message'])<p class="lic-msg">{{ $s['message'] }}</p>@endif
        @if ($s['expires_at'])
            <p>Valid until <strong>{{ \Illuminate\Support\Carbon::parse($s['expires_at'])->format('j M Y') }}</strong>
                @if ($s['days_left'] !== null && $s['days_left'] >= 0) ({{ $s['days_left'] }} days left) @endif</p>
        @endif
        @if ($s['state'] !== 'unlicensed')
            @php($u = $this->usage())
            <table class="lic-t">
                <tr><th>In use</th><th>Licensed</th></tr>
                @foreach (['branches' => 'Branches', 'devices' => 'Tills and devices', 'users' => 'Active users'] as $k => $label)
                    <tr><td>{{ $label }}: {{ $u[$k] }}</td><td>{{ $s['limits'][$k] ?? 'unlimited' }}</td></tr>
                @endforeach
                <tr><td>Finance and HR</td><td>{{ $s['features'] === null ? 'all' : (implode(', ', $s['features']) ?: 'none') }}</td></tr>
                <tr><td>Industry modules</td><td>{{ $s['modules'] === null ? 'all' : (implode(', ', $s['modules']) ?: 'none') }}</td></tr>
            </table>
            @if ($lc = $this->lastCheckin())
                <p class="lic-muted">Last confirmed with the licence server: {{ $lc->last_checkin_at?->format('j M Y H:i') ?? 'never' }}</p>
            @endif
        @endif
    </x-filament::section>
    @if ($this->canManage())
        <x-filament::section heading="Installation code">
            <p>To activate or renew, send this code to {{ config('brand.vendor') }}. You will receive a licence key; enter it with “Enter licence key”.</p>
            <textarea readonly rows="3" class="lic-code" onclick="this.select()">{{ $this->installationCode() }}</textarea>
        </x-filament::section>
    @elseif ($s['read_only'])
        <x-filament::section><p>Ask the owner or general manager to renew the licence.</p></x-filament::section>
    @endif
    <style>
        .lic-head { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; } .lic-muted { opacity: .7; font-size: .85rem; }
        .lic-msg { margin-top: 10px; font-weight: 500; } .lic-t { margin-top: 10px; font-size: .875rem; border-collapse: collapse; }
        .lic-t td, .lic-t th { padding: 4px 12px 4px 0; text-align: left; }
        .lic-code { width: 100%; font-family: ui-monospace, monospace; font-size: .8rem; padding: 8px; border: 1px solid color-mix(in srgb, currentColor 15%, transparent); border-radius: 6px; background: transparent; color: inherit; }
    </style>
</x-filament-panels::page>

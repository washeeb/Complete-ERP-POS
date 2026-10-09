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
    <style>
        .sh { width: 100%; border-collapse: collapse; font-size: .875rem; } .sh th, .sh td { padding: 6px 8px; text-align: left; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); }
        .sh-ok { color: rgb(22 163 74); font-weight: 600; } .sh-warning { color: rgb(202 138 4); font-weight: 600; } .sh-fail { color: rgb(220 38 38); font-weight: 600; }
    </style>
</x-filament-panels::page>

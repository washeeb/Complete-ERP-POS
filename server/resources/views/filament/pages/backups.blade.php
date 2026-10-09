<x-filament-panels::page>
    @unless ($this->passphraseSet())
        <div class="bk-warn">There is no backup passphrase yet, so backups cannot run. On the server, in the <code>server</code> folder, run <code>php artisan erp:backup:key</code>, write the passphrase down and keep it away from the server.</div>
    @endunless

    <x-filament::section heading="Destinations" description="Every backup is copied to each destination in use. Use at least one that is not inside the server (a USB drive you take home, cloud storage, another server).">
        <table class="bk">
            <thead><tr><th>Name</th><th>Type</th><th>Keeps</th><th>Last copy</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->destinations() as $d)
                <tr><td>{{ $d->name }} @unless ($d->is_active)<span class="bk-muted">(off)</span>@endunless</td><td>{{ \App\Domain\Backup\Destinations::TYPES[$d->type] ?? $d->type }}</td><td>{{ $d->keep }}</td>
                    <td>@if ($d->last_error)<span class="bk-bad">{{ $d->last_error }}</span>@else{{ $d->last_ok_at?->diffForHumans() ?? 'Not yet' }}@endif</td>
                    <td>{{ ($this->editDestinationAction)(['id' => $d->id]) }}</td></tr>
            @empty
                <tr><td colspan="5">No destination yet: backups stay only on this server. Add one.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Recent backups" description="Made every night by the scheduler; checked (decrypted, read and restored into a scratch database) every Sunday.">
        <table class="bk">
            <thead><tr><th>When</th><th>Status</th><th>Size</th><th>Copies</th><th>Check</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->runs() as $r)
                <tr><td>{{ $r->started_at->diffForHumans() }}<br><span class="bk-muted">{{ $r->file_name }}</span></td>
                    <td class="{{ $r->status === 'ok' ? 'bk-ok' : 'bk-bad' }}">{{ $r->status }}@if ($r->error)<br><span class="bk-muted">{{ $r->error }}</span>@endif</td>
                    <td>{{ number_format($r->size_bytes / 1048576, 1) }} MB</td>
                    <td>@foreach ($r->destinations ?? [] as $x)<div>{{ $x['ok'] ? '✓' : '✗' }} {{ $x['name'] }}</div>@endforeach</td>
                    <td>{{ $r->verify_result ?? '—' }}</td>
                    <td>@if (in_array($r->status, ['ok', 'partial'], true))<x-filament::link tag="button" wire:click="download('{{ $r->id }}')">Download</x-filament::link>@endif</td></tr>
            @empty
                <tr><td colspan="6">No backup yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <x-filament-actions::modals />
    <style>
        .bk { width: 100%; border-collapse: collapse; font-size: .85rem; } .bk th, .bk td { padding: 6px 8px; text-align: left; vertical-align: top; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); }
        .bk-muted { opacity: .6; font-size: .78rem; } .bk-bad { color: rgb(220 38 38); } .bk-ok { color: rgb(22 163 74); }
        .bk-warn { padding: 10px 12px; border-radius: 8px; background: color-mix(in srgb, rgb(234 179 8) 18%, transparent); }
    </style>
</x-filament-panels::page>

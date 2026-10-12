<x-filament-panels::page>
    @unless ($this->enabled())
        <x-filament::section>
            <p>Cash control with vaults is <strong>off</strong>: whoever closes a till counts the drawer. To use vaults, the Cashier and the Income Controller, switch on
                <em>Cash control with vaults</em> under <strong>System → Settings → Tills and cash</strong>. Shifts and vaults can be set up before that.</p>
        </x-filament::section>
    @endunless

    <x-filament::section heading="Shifts" description="Each till gets one vault per shift in use. A teller is assigned to the vault of the shift they work.">
        <table class="cs">
            <thead><tr><th>Shift</th><th>Hours</th><th>In use</th><th></th></tr></thead>
            <tbody>
            @foreach ($this->templates() as $t)
                <tr>
                    <td><strong>{{ $t->name }}</strong></td>
                    <td>{{ substr($t->starts_at, 0, 5) }}–{{ substr($t->ends_at, 0, 5) }}</td>
                    <td>{{ $t->is_active ? 'Yes' : 'No' }}</td>
                    <td class="cs-act">{{ ($this->templateAction)(['template' => $t->id]) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Vaults" description="One cash tray per till and shift, with the float the cashier usually issues to it.">
        <table class="cs">
            <thead><tr><th>Vault</th><th>Branch</th><th class="cs-num">Standard float</th><th>In use</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->vaults() as $v)
                <tr>
                    <td><strong>{{ $v->name }}</strong></td>
                    <td>{{ $v->branch->code }}</td>
                    <td class="cs-num">{{ $this->money($v->standard_float_minor) }}</td>
                    <td>{{ $v->is_active ? 'Yes' : 'No' }}</td>
                    <td class="cs-act">{{ ($this->vaultAction)(['vault' => $v->id]) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="cs-muted">No tills yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <style>
        .cs { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .cs th { text-align: left; font-weight: 600; padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .1); }
        .cs td { padding: 7px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); }
        .dark .cs th { border-color: rgb(255 255 255 / .12); } .dark .cs td { border-color: rgb(255 255 255 / .06); }
        .cs-num { text-align: right; font-variant-numeric: tabular-nums; } .cs-act { text-align: right; } .cs-muted { opacity: .7; }
    </style>
</x-filament-panels::page>

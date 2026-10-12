<x-filament-panels::page>
    @php($branches = $this->branches())
    @if ($branches->count() > 1)
        <div class="cd-branch">
            <label for="cd-branch">Branch</label>
            <select id="cd-branch" wire:model.live="branchId">
                @foreach ($branches as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
        </div>
    @endif

    <x-filament::section heading="Vaults" description="One tray per till and shift. Issue the float in the morning; receive each tray when its teller has closed the till and counted it.">
        <table class="cd">
            <thead><tr><th>Vault</th><th>Teller</th><th>Float</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->rows() as $r)
                @php($s = $r['session'])
                <tr>
                    <td><strong>{{ $r['vault']->name }}</strong><div class="cd-muted">{{ $r['vault']->shiftTemplate->label() }}</div></td>
                    <td>{{ $s?->teller_name ?? '—' }}</td>
                    <td>{{ $s ? $this->money($s->float_minor) : '—' }}</td>
                    <td>
                        @if (! $s)<span class="cd-muted">No float yet</span>
                        @else
                            {{ \App\Models\VaultSession::STATUSES[$s->status] }}
                            @if ($s->business_date->toDateString() !== app(\App\Domain\Pos\CashControl::class)->today($s->tenant_id))<span class="cd-warn"> · from {{ $s->business_date->format('j M') }}</span>@endif
                            @if ($s->status === 'counted' && $s->cashier_recounts > 0)<span class="cd-warn"> · recount asked</span>@endif
                            @if ($s->status === 'received')<div class="cd-muted">by {{ $s->cashier_name }}</div>@endif
                        @endif
                    </td>
                    <td class="cd-act">
                        @if (! $s){{ ($this->issueAction)(['vault' => $r['vault']->id]) }}
                        @elseif ($s->status === 'issued'){{ ($this->cancelAction)(['session' => $s->id]) }}
                        @elseif ($s->status === 'counted'){{ ($this->receiveAction)(['session' => $s->id]) }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="cd-muted">No vaults: the branch has no active tills, or no active shifts (Cash → Shifts and vaults).</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <style>
        .cd { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .cd th { text-align: left; font-weight: 600; padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .1); }
        .cd td { padding: 8px; border-bottom: 1px solid rgb(0 0 0 / .06); vertical-align: top; }
        .dark .cd th { border-color: rgb(255 255 255 / .12); } .dark .cd td { border-color: rgb(255 255 255 / .06); }
        .cd-muted { opacity: .7; font-size: .8rem; } .cd-warn { color: #b45309; } .dark .cd-warn { color: #fbbf24; }
        .cd-act { text-align: right; white-space: nowrap; }
        .cd-branch { display: flex; gap: 8px; align-items: center; } .cd-branch select { border-radius: 8px; padding: 4px 28px 4px 8px; background: transparent; }
    </style>
</x-filament-panels::page>

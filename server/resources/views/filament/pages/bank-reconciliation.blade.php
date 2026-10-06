<x-filament-panels::page>
    @php($d = $this->data())
    @php($s = $d['summary'])
    <x-filament::section>
        <div class="rec-sum">
            <label>As at <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="asAt" /></x-filament::input.wrapper></label>
            <div><span>Ledger balance</span><strong>{{ $this->money($s['ledger']) }}</strong></div>
            <div><span>− In the books, not yet on the statement</span><strong>{{ $this->money($s['outstanding']) }}</strong></div>
            <div><span>+ On the statement, not yet in the books</span><strong>{{ $this->money($s['unrecorded']) }}</strong></div>
            <div><span>= Statement should show</span><strong>{{ $this->money($s['expected_statement']) }}</strong></div>
            <div><span>Statement shows</span><strong>{{ $s['statement'] === null ? '— (no balance column)' : $this->money($s['statement']) }}</strong></div>
            @if ($s['difference'] !== null)
                <div><span>Difference</span><strong style="{{ $s['difference'] ? 'color:#dc2626' : 'color:#16a34a' }}">{{ $this->money($s['difference']) }}</strong></div>
            @endif
        </div>
    </x-filament::section>

    <div class="rec-actions">
        <x-filament::button wire:click="match" icon="heroicon-o-link">Match ticked lines</x-filament::button>
        <x-filament::button wire:click="clearEarlier" color="gray" outlined>Ticked ledger lines were on an earlier statement</x-filament::button>
        <x-filament::button wire:click="autoMatch" color="gray" icon="heroicon-o-sparkles">Match suggestions ({{ count($d['suggested']) }})</x-filament::button>
        <x-filament::input.wrapper><x-filament::input.select wire:model="postAccount" aria-label="Post to account">
            <option value="">Post the ticked statement line to…</option>
            @foreach ($d['accounts'] as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper>
        <x-filament::input.wrapper><x-filament::input type="text" wire:model="postMemo" placeholder="Description (optional)" /></x-filament::input.wrapper>
        <x-filament::button wire:click="post" color="gray">Post</x-filament::button>
        <x-filament::input.wrapper><x-filament::input type="text" wire:model="ignoreReason" placeholder="Reason to ignore" /></x-filament::input.wrapper>
        <x-filament::button wire:click="ignore" color="danger" outlined>Ignore</x-filament::button>
    </div>

    <div class="rec-grid">
        <x-filament::section heading="Statement lines to match">
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th></th><th>Date</th><th>Description</th><th class="num">Amount</th></tr></thead>
                <tbody>
                @forelse ($d['statement'] as $l)
                    <tr @class(['rec-sug' => isset($d['suggested'][$l->id])])>
                        <td><input type="checkbox" value="{{ $l->id }}" wire:model.live="pickStatement" aria-label="Statement {{ $l->line_date->format('j M') }} {{ $l->amount_minor }}"></td>
                        <td>{{ $l->line_date->format('j M Y') }}</td>
                        <td>{{ $l->description }} @if ($l->reference)<span class="muted">· {{ $l->reference }}</span>@endif
                            @if (isset($d['suggested'][$l->id]))<span class="rec-badge">suggested</span>@endif</td>
                        <td class="num">{{ $this->money($l->amount_minor) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Nothing left to match. Import a statement to continue.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>
        <x-filament::section heading="Ledger lines not yet on a statement">
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th></th><th>Date</th><th>Journal</th><th class="num">Amount</th></tr></thead>
                <tbody>
                @forelse ($d['ledger'] as $l)
                    <tr @class(['rec-sug' => in_array($l->id, $d['suggested'], true)])>
                        <td><input type="checkbox" value="{{ $l->id }}" wire:model.live="pickLedger" aria-label="Ledger {{ $l->number }}"></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($l->entry_date)->format('j M Y') }}</td>
                        <td>{{ $l->number }} <span class="muted">{{ $l->line_memo ?: $l->memo }}</span></td>
                        <td class="num">{{ $this->money((int) $l->amount_minor) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Every ledger line is matched.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>
    </div>

    @if ($d['matches']->isNotEmpty())
        <x-filament::section heading="Recent matches" collapsible collapsed>
            <table class="fin-table">
                @foreach ($d['matches'] as $m)
                    <tr><td>{{ \Illuminate\Support\Carbon::parse($m->created_at)->format('j M Y H:i') }} · {{ $m->matched_by_name }}</td>
                        <td class="num">{{ $this->money((int) $m->amount_minor) }}</td>
                        <td class="num"><x-filament::link tag="button" wire:click="unmatch('{{ $m->id }}')" color="danger">Undo</x-filament::link></td></tr>
                @endforeach
            </table>
        </x-filament::section>
    @endif

    <style>
        .rec-sum { display: flex; flex-wrap: wrap; gap: 16px; align-items: end; }
        .rec-sum > div { display: grid; font-size: .8rem; } .rec-sum > div strong { font-size: 1rem; }
        .rec-sum label { display: grid; gap: 4px; font-size: .875rem; }
        .rec-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .rec-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
        .rec-sug td { background: rgb(250 204 21 / .12); }
        .rec-badge { font-size: .7rem; padding: 1px 6px; border-radius: 9999px; background: rgb(250 204 21 / .35); margin-left: 4px; }
        .muted { opacity: .65; }
        .fin-scroll { overflow-x: auto; }
        .fin-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .fin-table th, .fin-table td { padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); text-align: left; vertical-align: top; }
        .fin-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    </style>
</x-filament-panels::page>

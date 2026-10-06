<x-filament-panels::page>
    @php($d = $this->data())
    <div class="fin-filters">
        <label>Report
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="report">
                @foreach (\App\Filament\Pages\FinancialReports::REPORTS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
        @if ($report === 'ledger')
            <label>Account
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="account">
                    <option value="">Choose…</option>
                    @foreach ($this->accounts() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
        @endif
        @if ($report !== 'check')
            @if ($report !== 'bs')
                <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
            @endif
            <label>{{ $report === 'bs' ? 'As at' : 'To' }} <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
            <label>Branch
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="branch">
                    <option value="">All branches</option>
                    @foreach ($this->branches() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
        @endif
    </div>

    <x-filament::section>
    <div class="fin-scroll">
    @if ($report === 'tb')
        <table class="fin-table wide">
            <thead><tr><th>Account</th><th class="num">Opening</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Closing</th></tr></thead>
            <tbody>
            @forelse ($d['rows'] as $r)
                <tr>
                    <td><a href="{{ request()->url() }}?report=ledger&account={{ $r['account']->id }}&from={{ $from }}&to={{ $to }}" class="fin-link">{{ $r['account']->label() }}</a></td>
                    <td class="num">{{ $this->money($r['opening']) }}</td><td class="num">{{ $this->money($r['debit']) }}</td>
                    <td class="num">{{ $this->money($r['credit']) }}</td><td class="num">{{ $this->money($r['closing']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Nothing posted in this range.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr><th>Total (debits + / credits −)</th><th class="num">{{ $this->money($d['totals']['opening']) ?: '0.00' }}</th>
                <th class="num">{{ $this->money($d['totals']['debit']) }}</th><th class="num">{{ $this->money($d['totals']['credit']) }}</th>
                <th class="num">{{ $this->money($d['totals']['closing']) ?: '0.00' }}</th></tr></tfoot>
        </table>
        <p class="fin-note">Balances: debit +, credit −. Income and expense accounts start each financial year at zero; earlier years’ profit is in retained earnings.</p>
    @elseif ($report === 'pl')
        <table class="fin-table">
            @foreach ($d['sections'] as $s)
                <tr class="fin-head"><th colspan="2">{{ $s['name'] }}</th></tr>
                @foreach ($s['rows'] as $r)<tr><td>{{ $r['account']->label() }}</td><td class="num">{{ $this->money($r['amount']) }}</td></tr>@endforeach
                <tr class="fin-sub"><td>Total {{ mb_strtolower($s['name']) }}</td><td class="num">{{ $this->money($s['total']) }}</td></tr>
                @if ($s['kind'] === 'cost_of_sales')<tr class="fin-total"><td>Gross profit</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['gross_profit']) }}</td></tr>@endif
            @endforeach
            <tr class="fin-total"><td>Net profit</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['net_profit']) }}</td></tr>
        </table>
    @elseif ($report === 'bs')
        <table class="fin-table">
            @foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $k => $label)
                <tr class="fin-head"><th colspan="2">{{ $label }}</th></tr>
                @foreach ($d[$k] as $r)<tr><td>{{ $r['account']->label() }}</td><td class="num">{{ $this->money($r['amount']) }}</td></tr>@endforeach
                @if ($k === 'equity')<tr><td>Profit this financial year to date</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['profit_this_year']) }}</td></tr>@endif
                <tr class="fin-sub"><td>Total {{ mb_strtolower($label) }}</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['total_'.$k]) }}</td></tr>
            @endforeach
            <tr class="fin-total"><td>Liabilities + equity</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['total_liabilities'] + $d['total_equity']) }}</td></tr>
        </table>
        <p class="fin-note">{{ $d['balanced'] ? 'Assets equal liabilities plus equity.' : 'Out of balance — run the ledger check.' }}</p>
    @elseif ($report === 'ledger')
        @if ($d)
            <table class="fin-table wide">
                <thead><tr><th>Date</th><th>Journal</th><th>Description</th><th>Branch</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
                <tbody>
                <tr class="fin-sub"><td colspan="6">Opening balance</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['opening']) }}</td></tr>
                @foreach ($d['lines'] as $l)
                    <tr><td>{{ \Illuminate\Support\Carbon::parse($l->entry_date)->format('j M Y') }}</td>
                        <td><a class="fin-link" href="{{ \App\Filament\Resources\JournalEntries\JournalEntryResource::getUrl('view', ['record' => $l->entry_id]) }}">{{ $l->number }}</a></td>
                        <td>{{ $l->line_memo ?: $l->memo }}</td><td>{{ $l->branch }}</td>
                        <td class="num">{{ $l->amount_minor > 0 ? $this->money($l->amount_minor) : '' }}</td><td class="num">{{ $l->amount_minor < 0 ? $this->money(-$l->amount_minor) : '' }}</td>
                        <td class="num">{{ \App\Filament\Support\FinanceUi::money($l->running_minor) }}</td></tr>
                @endforeach
                <tr class="fin-total"><td colspan="6">Closing balance</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['closing']) }}</td></tr>
                </tbody>
            </table>
        @else
            <p>Choose an account.</p>
        @endif
    @else
        <table class="fin-table wide">
            <thead><tr><th>Control account</th><th class="num">Ledger</th><th class="num">Sub-ledger</th><th class="num">Difference</th></tr></thead>
            <tbody>
            @foreach ($d['ties'] as $t)
                <tr><td>{{ $t['label'] }}</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($t['ledger']) }}</td>
                    <td class="num">{{ \App\Filament\Support\FinanceUi::money($t['sub_ledger']) }}</td>
                    <td class="num" style="{{ $t['difference'] ? 'color:#dc2626;font-weight:600' : '' }}">{{ \App\Filament\Support\FinanceUi::money($t['difference']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <p class="fin-note">{{ $d['ok'] ? 'Every journal balances and the ledger ties to its sub-ledgers.' : implode(' ', $d['problems']) }}
            @if ($d['opening_left']) “Opening balances” still holds {{ \App\Filament\Support\FinanceUi::money($d['opening_left']) }} — it should reach zero once all opening balances are in. @endif
            Records not yet posted are picked up every few minutes (Journals → Post now).</p>
    @endif
    </div>
    </x-filament::section>

    <style>
        .fin-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
        .fin-filters label { display: grid; gap: 4px; font-size: .875rem; font-weight: 500; }
        .fin-scroll { overflow-x: auto; } .fin-table.wide { min-width: 560px; }
        .fin-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .fin-table th, .fin-table td { padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); text-align: left; }
        .fin-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .fin-head th { padding-top: 14px; font-weight: 700; }
        .fin-sub td { font-weight: 600; }
        .fin-total td, .fin-table tfoot th { font-weight: 700; border-top: 2px solid currentColor; }
        .fin-link { color: rgb(var(--primary-600, 37 99 235)); }
        .fin-note { margin-top: 10px; font-size: .8rem; opacity: .75; }
    </style>
</x-filament-panels::page>

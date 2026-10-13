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
        @if (! in_array($report, ['check'], true))
            @if ($report !== 'bs')
                <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
            @endif
            <label>{{ $report === 'bs' ? 'As at' : 'To' }} <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
            @if ($report !== 'vat')<label>Branch
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="branch">
                    <option value="">All branches</option>
                    @foreach ($this->branches() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>@endif
            @if (! in_array($report, ['vat', 'industries'], true) && $this->industries())<label>Industry
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="industry">
                    <option value="">All industries</option>
                    @foreach ($this->industries() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>@endif
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
        @if ($industry)
            <p class="fin-note">One industry's assets and liabilities: cash banked, money owed and stock in transit between industries sit partly at company level, so a single industry's sheet does not balance on its own. Choose All industries for the company's balance sheet.</p>
        @else
            <p class="fin-note">{{ $d['balanced'] ? 'Assets equal liabilities plus equity.' : 'Out of balance — run the ledger check.' }}</p>
        @endif
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
    @elseif ($report === 'industries')
        @if (count($d['columns']) < 2)
            <p class="fin-note">There are no industries yet. They appear when an industry module is switched on for a branch.</p>
        @else
        <table class="fin-table wide">
            <thead><tr><th>Account</th>@foreach ($d['columns'] as $name)<th class="num">{{ $name }}</th>@endforeach<th class="num">Total</th></tr></thead>
            <tbody>
            @foreach (['income' => 'Income', 'cost_of_sales' => 'Cost of sales', 'expenses' => 'Expenses'] as $kind => $label)
                <tr class="fin-head"><th colspan="{{ count($d['columns']) + 2 }}">{{ $label }}</th></tr>
                @foreach ($d['rows'] as $r)
                    @continue($r['kind'] !== $kind)
                    <tr><td>{{ $r['account']->label() }}</td>
                        @foreach ($d['columns'] as $id => $name)<td class="num">{{ $this->money($r['amounts'][$id]) }}</td>@endforeach
                        <td class="num">{{ $this->money($r['total']) }}</td></tr>
                @endforeach
                <tr class="fin-sub"><td>Total {{ mb_strtolower($label) }}</td>
                    @foreach ($d['columns'] + ['total' => ''] as $id => $name)<td class="num">{{ \App\Filament\Support\FinanceUi::money($d['totals'][$id][$kind]) }}</td>@endforeach</tr>
            @endforeach
            <tr class="fin-total"><td>Net profit</td>
                @foreach ($d['columns'] + ['total' => ''] as $id => $name)<td class="num">{{ \App\Filament\Support\FinanceUi::money($d['totals'][$id]['net_profit']) }}</td>@endforeach</tr>
            </tbody>
        </table>
        <p class="fin-note">Each industry is a profit centre: its column holds what its branches posted. The Company column holds head office and shared costs
            @if (app(\App\Domain\Finance\HeadOfficeShare::class)->enabled(\App\Filament\Support\Erp::user()->tenant_id)) until they are shared out by sales (Share head office costs, above, for the month of the To date)@else (sharing them by sales can be switched on under System → Settings → Finance)@endif.
            Stock moved between industries is at cost, so it makes no profit for either.</p>
        @endif
    @elseif ($report === 'budget')
        <p class="fin-note">Months: {{ $d['label'] }}. Income and costs both shown as positive amounts.</p>
        <table class="fin-table wide">
            <thead><tr><th>Account</th><th class="num">Budget</th><th class="num">Actual</th><th class="num">Difference</th></tr></thead>
            <tbody>
            @forelse ($d['rows'] as $r)
                <tr><td>{{ $r['account']->label() }}</td><td class="num">{{ $this->money($r['budget']) }}</td><td class="num">{{ $this->money($r['actual']) }}</td>
                    <td class="num" style="color:{{ $r['better'] ? '#16a34a' : '#dc2626' }}">{{ \App\Filament\Support\FinanceUi::money($r['variance']) }}</td></tr>
            @empty
                <tr><td colspan="4">No budget or postings for these months. Enter one under Finance → Budgets.</td></tr>
            @endforelse
            </tbody>
        </table>
    @elseif ($report === 'vat')
        <table class="fin-table">
            <tr><td>Sales (before tax)</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['sales_total']) }}</td></tr>
            <tr><td>— of which taxed</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['sales_taxed']) }}</td></tr>
            <tr><td>— of which exempt or zero-rated</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($d['sales_untaxed']) }}</td></tr>
        </table>
        <table class="fin-table wide" style="margin-top:12px">
            <thead><tr><th>Tax</th><th class="num">On sales (output)</th><th class="num">On purchases (input)</th><th class="num">Due</th></tr></thead>
            <tbody>
            @foreach ($d['taxes'] as $t)
                <tr><td>{{ $t['name'] }}</td><td class="num">{{ \App\Filament\Support\FinanceUi::money($t['output']) }}</td>
                    <td class="num">{{ \App\Filament\Support\FinanceUi::money($t['input']) }}</td><td class="num"><strong>{{ \App\Filament\Support\FinanceUi::money($t['due']) }}</strong></td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><th>Total</th><th class="num">{{ \App\Filament\Support\FinanceUi::money(array_sum(array_column($d['taxes'], 'output'))) }}</th>
                <th class="num">{{ \App\Filament\Support\FinanceUi::money(array_sum(array_column($d['taxes'], 'input'))) }}</th>
                <th class="num">{{ \App\Filament\Support\FinanceUi::money(array_sum(array_column($d['taxes'], 'due'))) }}</th></tr></tfoot>
        </table>
        <p class="fin-note">From the ledger for the dates chosen. Your accountant must check these against the GRA return (which taxes are claimable on purchases, rates and boxes) before filing.</p>
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
        .fin-table th, .fin-table td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; }
        .fin-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .fin-head th { padding-top: 14px; font-weight: 700; }
        .fin-sub td { font-weight: 600; }
        .fin-total td, .fin-table tfoot th { font-weight: 700; border-top: 2px solid currentColor; }
        .fin-link { color: rgb(var(--primary-600, 37 99 235)); }
        .fin-note { margin-top: 10px; font-size: .8rem; opacity: .75; }
    </style>
</x-filament-panels::page>

<?php

namespace App\Domain\Office;

use App\Domain\Finance\FinancialReports as Reports;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Finance\GlCheck;
use App\Domain\Finance\HeadOfficeShare;
use App\Filament\Pages\FinancialReports as Page;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\User;
use App\Support\IndustryContext;
use Illuminate\Support\Carbon;

/**
 * Financial reports in the new back office (release 7 of the rewrite): trial balance, profit and loss, profit by
 * industry, balance sheet, account ledger, budget vs actual, VAT return and the ledger check — the same figures as the
 * current page (App\Domain\Finance\FinancialReports, GlCheck), the same filters (dates, branch, books, industry), and
 * Share head office costs. Each report comes back as tables of ready-written cells, so the screen and the export
 * (Excel, CSV, PDF) show exactly the same thing.
 */
final class FinancialReportsScreen
{
    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return Page::canAccess();
    }

    /** @param  array<string, mixed>  $p  report, account, from, to, branch, industry, books */
    public function params(array $p): array
    {
        $report = isset(Page::REPORTS[$p['report'] ?? '']) ? $p['report'] : 'tb';
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $industry = array_key_exists('industry', $p) ? (in_array($p['industry'], ['', 'all'], true) ? null : (string) $p['industry']) : IndustryContext::selected(); // "all" = every industry

        return [
            'report' => $report, 'account' => filled($p['account'] ?? null) ? (string) $p['account'] : null,
            'from' => $date($p['from'] ?? null) ?? now()->startOfMonth()->toDateString(), 'to' => $date($p['to'] ?? null) ?? now()->toDateString(),
            'branch' => filled($p['branch'] ?? null) ? (string) $p['branch'] : null, 'industry' => $industry,
            'books' => filled($p['books'] ?? null) ? (string) $p['books'] : null,
        ];
    }

    public function options(): array
    {
        $separate = $this->separateBooks();

        return [
            'reports' => PurchaseOrdersScreen::choices(Page::REPORTS), 'accounts' => PurchaseOrdersScreen::choices(FinanceUi::accounts()),
            'branches' => PurchaseOrdersScreen::choices(FinanceUi::branches()),
            'books' => $separate ? PurchaseOrdersScreen::choices(LegalEntity::where('is_active', true)->orderByDesc('is_primary')->orderBy('name')->get()
                ->mapWithKeys(fn ($e) => [$e->id => $e->name.($e->tin ? " (TIN {$e->tin})" : '')])->all()) : [],
            'industries' => PurchaseOrdersScreen::choices($this->industries()),
        ];
    }

    /** @return array<string, string> */
    private function industries(): array
    {
        $list = Industry::licensed()->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all();

        return $list ? $list + [Reports::COMPANY => 'Company (head office and shared)'] : [];
    }

    private function separateBooks(): bool
    {
        return LegalEntity::where('is_active', true)->count() > 1;
    }

    private function m(?int $v): string
    {
        return $v ? FinanceUi::money($v) : '';
    }

    private function mm(int $v): string
    {
        return FinanceUi::money($v);
    }

    /** A cell that opens another report or page. */
    private static function link(string $text, string $to): array
    {
        return ['t' => $text, 'to' => $to];
    }

    /** One report: its tables, notes under it, and what the page may offer (Share head office costs). */
    public function report(array $p): array
    {
        $p = $this->params($p);
        $tenant = $this->user->tenant_id;
        $r = app(Reports::class);
        $title = Page::REPORTS[$p['report']];
        $out = match ($p['report']) {
            'pl' => $this->pl($r->profitAndLoss($tenant, $p['from'], $p['to'], $p['branch'], $p['industry'], $p['books'])),
            'industries' => $this->industriesReport($r->profitByIndustry($tenant, $p['from'], $p['to'], $p['books'])),
            'bs' => $this->bs($r->balanceSheet($tenant, $p['to'], $p['branch'], $p['industry'], $p['books']), (bool) $p['industry']),
            'ledger' => $this->ledger($r, $p),
            'budget' => $this->budget($r, $p),
            'vat' => $this->vat($r, $p),
            'check' => $this->check(app(GlCheck::class)->run($tenant)),
            default => $this->tb($r->trialBalance($tenant, $p['from'], $p['to'], $p['branch'], $p['industry'], $p['books']), $p),
        };

        return $p + ['title' => $title, 'share' => $p['report'] === 'industries' ? $this->share($p['to']) : null] + $out;
    }

    private function tb(array $d, array $p): array
    {
        $rows = array_map(fn ($x) => ['cells' => [self::link($x['account']->label(), '?'.http_build_query(['report' => 'ledger', 'account' => $x['account']->id, 'from' => $p['from'], 'to' => $p['to']])),
            $this->m($x['opening']), $this->m($x['debit']), $this->m($x['credit']), $this->m($x['closing'])]], $d['rows']);
        $t = $d['totals'];

        return ['tables' => [ReportTables::table('Trial balance', ['Account', '#Opening', '#Debit', '#Credit', '#Closing'], $rows,
            ['Total (debits + / credits −)', $this->m($t['opening']) ?: '0.00', $this->m($t['debit']), $this->m($t['credit']), $this->m($t['closing']) ?: '0.00'])],
            'empty' => 'Nothing posted in this range.',
            'notes' => ['Balances: debit +, credit −. Income and expense accounts start each financial year at zero; earlier years’ profit is in retained earnings.']];
    }

    private function pl(array $d): array
    {
        $rows = [];
        foreach ($d['sections'] as $s) {
            $rows[] = ['kind' => 'head', 'cells' => [$s['name'], '']];
            foreach ($s['rows'] as $x) {
                $rows[] = ['cells' => [$x['account']->label(), $this->m($x['amount'])]];
            }
            $rows[] = ['kind' => 'sub', 'cells' => ['Total '.mb_strtolower($s['name']), $this->m($s['total'])]];
            if ($s['kind'] === 'cost_of_sales') {
                $rows[] = ['kind' => 'total', 'cells' => ['Gross profit', $this->mm($d['gross_profit'])]];
            }
        }
        $rows[] = ['kind' => 'total', 'cells' => ['Net profit', $this->mm($d['net_profit'])]];

        return ['tables' => [ReportTables::table('Profit and loss', ['Account', '#Amount'], $rows)],
            'cards' => [['label' => 'Income', 'value' => $this->mm($d['income'])], ['label' => 'Gross profit', 'value' => $this->mm($d['gross_profit'])],
                ['label' => 'Net profit', 'value' => $this->mm($d['net_profit']), 'tone' => $d['net_profit'] < 0 ? 'bad' : 'good']], 'notes' => []];
    }

    private function bs(array $d, bool $oneIndustry): array
    {
        $rows = [];
        foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $k => $label) {
            $rows[] = ['kind' => 'head', 'cells' => [$label, '']];
            foreach ($d[$k] as $x) {
                $rows[] = ['cells' => [$x['account']->label(), $this->m($x['amount'])]];
            }
            if ($k === 'equity') {
                $rows[] = ['cells' => ['Profit this financial year to date', $this->mm($d['profit_this_year'])]];
            }
            $rows[] = ['kind' => 'sub', 'cells' => ['Total '.mb_strtolower($label), $this->mm($d['total_'.$k])]];
        }
        $rows[] = ['kind' => 'total', 'cells' => ['Liabilities + equity', $this->mm($d['total_liabilities'] + $d['total_equity'])]];
        $note = $oneIndustry
            ? 'One industry\'s assets and liabilities: cash banked, money owed and stock in transit between industries sit partly at company level, so a single industry\'s sheet does not balance on its own. Choose All industries for the company\'s balance sheet.'
            : ($d['balanced'] ? 'Assets equal liabilities plus equity.' : 'Out of balance — run the ledger check.');

        return ['tables' => [ReportTables::table('Balance sheet', ['Account', '#Amount'], $rows)], 'notes' => [$note],
            'warning' => ! $oneIndustry && ! $d['balanced'] ? 'Out of balance — run the ledger check.' : null];
    }

    private function ledger(Reports $r, array $p): array
    {
        $a = $p['account'] ? Account::find($p['account']) : null;
        if (! $a) {
            return ['tables' => [], 'notes' => [], 'prompt' => 'Choose an account.'];
        }
        $d = $r->ledger($this->user->tenant_id, $a, $p['from'], $p['to'], $p['branch'], $p['industry'], $p['books']);
        $rows = [['kind' => 'sub', 'cells' => ['Opening balance', '', '', '', '', '', $this->mm($d['opening'])]]];
        foreach ($d['lines'] as $l) {
            $rows[] = ['cells' => [Carbon::parse($l->entry_date)->format('j M Y'), self::link((string) $l->number, "/admin/journal-entries/{$l->entry_id}"),
                $l->line_memo ?: $l->memo, $l->branch, $l->amount_minor > 0 ? $this->m((int) $l->amount_minor) : '', $l->amount_minor < 0 ? $this->m(-(int) $l->amount_minor) : '',
                $this->mm((int) $l->running_minor)]];
        }
        $rows[] = ['kind' => 'total', 'cells' => ['Closing balance', '', '', '', '', '', $this->mm($d['closing'])]];

        return ['tables' => [ReportTables::table($a->label(), ['Date', 'Journal', 'Description', 'Branch', '#Debit', '#Credit', '#Balance'], $rows)], 'notes' => []];
    }

    private function budget(Reports $r, array $p): array
    {
        $tenant = $this->user->tenant_id;
        $gl = app(GeneralLedger::class);
        $a = $gl->period($tenant, $p['from']);
        $b = $gl->period($tenant, $p['to']);
        $to = $b->year === $a->year ? $b->period : 12;
        $label = $a->starts_on->format('M Y').' – '.$gl->period($tenant, $a->starts_on->copy()->addMonths($to - $a->period)->toDateString())->starts_on->format('M Y');
        $rows = array_map(fn ($x) => ['cells' => [$x['account']->label(), $this->m($x['budget']), $this->m($x['actual']),
            ['t' => $this->mm($x['variance']), 'tone' => $x['better'] ? 'good' : 'bad']]], $r->budgetVsActual($tenant, $a->year, $a->period, $to, $p['branch'], $p['industry']));

        return ['tables' => [ReportTables::table('Budget vs actual', ['Account', '#Budget', '#Actual', '#Difference'], $rows)],
            'empty' => 'No budget or postings for these months. Enter one under Finance → Budgets.',
            'notes' => ["Months: {$label}. Income and costs both shown as positive amounts."]];
    }

    private function vat(Reports $r, array $p): array
    {
        $separate = $this->separateBooks();
        $books = $p['books'] ?? ($separate ? LegalEntity::primary()?->id : null);
        $d = $r->vatReturn($this->user->tenant_id, $p['from'], $p['to'], $books);
        $taxes = array_map(fn ($t) => ['cells' => [$t['name'], $this->mm($t['output']), $this->mm($t['input']), ['t' => $this->mm($t['due']), 'strong' => true]]], $d['taxes']);
        $sum = fn ($k) => $this->mm(array_sum(array_column($d['taxes'], $k)));
        $notes = [];
        if ($separate) {
            $notes[] = 'Each company files its own VAT return under its own TIN. Showing: '.(LegalEntity::find($books)?->name ?? '').'. Choose another under Books.';
        }
        $notes[] = 'From the ledger for the dates chosen. Your accountant must check these against the GRA return (which taxes are claimable on purchases, rates and boxes) before filing.';

        return ['tables' => [
            ReportTables::table('Sales', ['Sales', '#Amount'], [['cells' => ['Sales (before tax)', $this->mm($d['sales_total'])]],
                ['cells' => ['— of which taxed', $this->mm($d['sales_taxed'])]], ['cells' => ['— of which exempt or zero-rated', $this->mm($d['sales_untaxed'])]]]),
            ReportTables::table('Tax', ['Tax', '#On sales (output)', '#On purchases (input)', '#Due'], $taxes, ['Total', $sum('output'), $sum('input'), $sum('due')]),
        ], 'notes' => $notes];
    }

    private function industriesReport(array $d): array
    {
        if (count($d['columns']) < 2) {
            return ['tables' => [], 'notes' => [], 'prompt' => 'There are no industries yet. They appear when an industry module is switched on for a branch.'];
        }
        $cols = array_merge(['Account'], array_map(fn ($n) => "#{$n}", array_values($d['columns'])), ['#Total']);
        $all = $d['columns'] + ['total' => ''];
        $rows = [];
        foreach (['income' => 'Income', 'cost_of_sales' => 'Cost of sales', 'expenses' => 'Expenses'] as $kind => $label) {
            $rows[] = ['kind' => 'head', 'cells' => array_merge([$label], array_fill(0, count($all), ''))];
            foreach ($d['rows'] as $x) {
                if ($x['kind'] === $kind) {
                    $rows[] = ['cells' => array_merge([$x['account']->label()], array_map(fn ($id) => $this->m($x['amounts'][$id]), array_keys($d['columns'])), [$this->m($x['total'])])];
                }
            }
            $rows[] = ['kind' => 'sub', 'cells' => array_merge(['Total '.mb_strtolower($label)], array_map(fn ($id) => $this->mm($d['totals'][$id][$kind]), array_keys($all)))];
        }
        $rows[] = ['kind' => 'total', 'cells' => array_merge(['Net profit'], array_map(fn ($id) => $this->mm($d['totals'][$id]['net_profit']), array_keys($all)))];
        $sharing = app(HeadOfficeShare::class)->enabled($this->user->tenant_id);

        return ['tables' => [ReportTables::table('Profit by industry', $cols, $rows)], 'notes' => ['Each industry is a profit centre: its column holds what its branches posted. The Company column holds head office and shared costs '
            .($sharing ? 'until they are shared out by sales (Share head office costs, for the month of the To date)' : '(sharing them by sales can be switched on under System → Settings → Finance)')
            .'. Stock moved between industries is at cost, so it makes no profit for either.']];
    }

    private function check(array $d): array
    {
        $rows = array_map(fn ($t) => ['cells' => [$t['label'], $this->mm($t['ledger']), $this->mm($t['sub_ledger']), ['t' => $this->mm($t['difference']), 'tone' => $t['difference'] ? 'bad' : null]]], $d['ties']);
        $note = ($d['ok'] ? 'Every journal balances and the ledger ties to its sub-ledgers.' : implode(' ', $d['problems']))
            .($d['opening_left'] ? ' “Opening balances” still holds '.$this->mm($d['opening_left']).' — it should reach zero once all opening balances are in.' : '')
            .' Records not yet posted are picked up every few minutes (Journals → Post now).';

        return ['tables' => [ReportTables::table('Ledger check', ['Control account', '#Ledger', '#Sub-ledger', '#Difference'], $rows)], 'notes' => [$note],
            'warning' => $d['ok'] ? null : implode(' ', $d['problems'])];
    }

    /** Share head office costs: what the journal for the month of the To date would post, or why it cannot. */
    private function share(string $to): ?array
    {
        $share = app(HeadOfficeShare::class);
        if (! $share->enabled($this->user->tenant_id) || ! Erp::canAnywhere('finance.journal')) {
            return null;
        }
        $p = $share->proposal($this->user->tenant_id, $to);
        if ($p['done']) {
            $text = "Already shared in {$p['done']->number}. Reverse that journal (Finance → Journals) to share the month again.";
        } elseif (! $p['basis'] || ! $p['lines']) {
            $text = $p['basis'] ? 'There are no head office costs this month.' : 'No industry has sales this month, so there is nothing to share by.';
        } else {
            $sales = array_sum($p['basis']);
            $parts = [];
            foreach ($p['basis'] as $id => $b) {
                $parts[] = ($p['names'][$id] ?? '?').' '.round($b * 100 / $sales, 1).'%';
            }
            $text = 'Head office costs of '.FinanceUi::money($p['total'])." in {$p['month']} are shared by this month's sales: ".implode(', ', $parts)
                .'. One journal moves each cost account\'s amount from Company to the industries. It can be reversed.';
        }

        return ['month' => $p['month'], 'text' => $text, 'can' => ! $p['done'] && $p['basis'] && $p['lines']];
    }

    /** The report as Exporter sheets (one per table), and the filters for the PDF header and the audit log. */
    public function sheets(array $p): array
    {
        $d = $this->report($p);
        $filters = match ($d['report']) {
            'check' => ["Report: {$d['title']}"],
            'bs' => ["Report: {$d['title']}", "As at: {$d['to']}"],
            default => ["Report: {$d['title']}", "From: {$d['from']}", "To: {$d['to']}"],
        };

        return ['title' => $d['title'], 'sheets' => ReportTables::sheets($d['tables'], $d['prompt'] ?? $d['empty'] ?? $d['title']), 'filters' => $filters];
    }
}

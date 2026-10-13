<?php

use App\Domain\Finance\FinancialReports;
use App\Domain\Finance\HeadOfficeShare;
use App\Domain\Finance\JournalService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\FinancialReports as ReportsPage;
use App\Models\Account;
use App\Models\Industry;
use App\Models\JournalEntry;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 0, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 0, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Grocery Spintex', 'is_hq' => false, 'tills' => 0, 'modules' => ['grocery']],
    ]);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->value('id');
    $this->grocery = Industry::where('module_key', 'grocery')->value('id');
    $this->accountant = staff($this->owner, 'abena', 4, 'accountant');
    $acc = fn (string $code) => Account::where('code', $code)->value('id');
    $this->sales = $acc('4100');
    $this->bank = Account::where('system_key', 'bank')->value('id');
    $this->rent = $acc('6200');
    $this->power = $acc('6300');
    $this->day = now()->startOfMonth()->addDays(9)->toDateString();
    $j = app(JournalService::class);
    $post = fn (string $memo, string $dr, string $cr, int $amount, ?string $branch) => $j->manual(actorFor($this->accountant), $this->day, $memo,
        [['account_id' => $dr, 'debit_minor' => $amount, 'branch_id' => $branch], ['account_id' => $cr, 'credit_minor' => $amount, 'branch_id' => $branch]]);
    // Sales: pharmacy 3,000.00, grocery 1,000.00. Costs: pharmacy power 200.00; head office rent 1,000.01.
    $post('Pharmacy sales', $this->bank, $this->sales, 300000, $this->b['P1']->id);
    $post('Grocery sales', $this->bank, $this->sales, 100000, $this->b['G1']->id);
    $post('Pharmacy power', $this->power, $this->bank, 20000, $this->b['P1']->id);
    $post('Head office rent', $this->rent, $this->bank, 100001, $this->b['HQ']->id);
});

it('tags every journal line with its industry and reports each industry as a profit centre', function () {
    expect(DB::table('journal_lines')->where('branch_id', $this->b['P1']->id)->pluck('industry_id')->unique()->all())->toBe([$this->pharmacy])
        ->and(DB::table('journal_lines')->where('branch_id', $this->b['HQ']->id)->whereNotNull('industry_id')->exists())->toBeFalse();

    $r = app(FinancialReports::class);
    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();
    expect($r->profitAndLoss($this->tenant->id, $from, $to, industry: $this->pharmacy)['net_profit'])->toBe(280000)
        ->and($r->profitAndLoss($this->tenant->id, $from, $to, industry: $this->grocery)['net_profit'])->toBe(100000)
        ->and($r->profitAndLoss($this->tenant->id, $from, $to, industry: FinancialReports::COMPANY)['net_profit'])->toBe(-100001)
        ->and($r->profitAndLoss($this->tenant->id, $from, $to)['net_profit'])->toBe(279999);

    $by = $r->profitByIndustry($this->tenant->id, $from, $to);
    expect($by['totals'][$this->pharmacy]['net_profit'])->toBe(280000)
        ->and($by['totals'][FinancialReports::COMPANY]['expenses'])->toBe(100001)
        ->and($by['totals']['total']['net_profit'])->toBe(279999);

    // A branch moved to another industry later keeps its history where it was posted.
    DB::table('branches')->where('id', $this->b['HQ']->id)->update(['industry_id' => $this->grocery]);
    expect($r->profitAndLoss($this->tenant->id, $from, $to, industry: FinancialReports::COMPANY)['net_profit'])->toBe(-100001);
});

it('shares head office costs by sales only when switched on, once a month, to the pesewa, and can be undone', function () {
    $share = app(HeadOfficeShare::class);
    expect(fn () => $share->post(actorFor($this->accountant), $this->day))->toThrow(DomainException::class, 'switched off');

    app(Settings::class)->set(actorFor($this->owner), 'finance.share_head_office_costs', true);
    $p = $share->proposal($this->tenant->id, $this->day);
    expect($p['basis'])->toBe([$this->pharmacy => 300000, $this->grocery => 100000])
        ->and($p['lines'])->toHaveCount(1)
        ->and($p['lines'][0]['shares'])->toBe([$this->pharmacy => 75001, $this->grocery => 25000]); // 1,000.01 split 75/25

    $entry = $share->post(actorFor($this->accountant), $this->day);
    expect($entry->kind)->toBe('allocation')
        ->and(fn () => $share->post(actorFor($this->accountant), $this->day))->toThrow(DomainException::class, 'already shared');

    $r = app(FinancialReports::class);
    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();
    expect($r->profitAndLoss($this->tenant->id, $from, $to, industry: $this->pharmacy)['net_profit'])->toBe(280000 - 75001)
        ->and($r->profitAndLoss($this->tenant->id, $from, $to, industry: FinancialReports::COMPANY)['net_profit'])->toBe(0)
        ->and($r->profitAndLoss($this->tenant->id, $from, $to)['net_profit'])->toBe(279999); // the company's profit does not change

    // Reversing it puts the costs back at company level; the month can then be shared again.
    app(JournalService::class)->reverse(actorFor($this->accountant), $entry, $this->day, 'Wrong month');
    expect($r->profitAndLoss($this->tenant->id, $from, $to, industry: FinancialReports::COMPANY)['net_profit'])->toBe(-100001)
        ->and($share->proposal($this->tenant->id, $this->day)['done'])->toBeNull();
    $share->post(actorFor($this->accountant), $this->day);
    expect(JournalEntry::where('kind', 'allocation')->count())->toBe(2);
});

it('shows profit by industry and the share button on the reports screen', function () {
    app(Settings::class)->set(actorFor($this->owner), 'finance.share_head_office_costs', true);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->accountant), 'web');
    $page = Livewire::test(ReportsPage::class, ['report' => 'industries', 'from' => now()->startOfMonth()->toDateString(), 'to' => $this->day])
        ->assertSee('Pharmacy')->assertSee('Company (head office and shared)')->assertSee('2,800.00');
    $page->callAction('shareHeadOffice')->assertNotified('Head office costs shared');
    expect(JournalEntry::where('kind', 'allocation')->count())->toBe(1);

    Livewire::test(ReportsPage::class, ['report' => 'pl', 'industry' => $this->grocery, 'from' => now()->startOfMonth()->toDateString(), 'to' => $this->day])
        ->assertSee('1,000.00');
});

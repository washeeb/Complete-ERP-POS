<?php

use App\Domain\Assets\AssetService;
use App\Domain\Banking\BankingService;
use App\Domain\Banking\StatementCsv;
use App\Domain\Budgets\BudgetService;
use App\Domain\Expenses\ExpenseService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\FinancialReports;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Finance\GlCheck;
use App\Domain\Finance\JournalService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\AssetClass;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\FixedAsset;
use App\Security\ScopeType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->fm = staff($this->owner, 'kwame', 5, 'finance_manager');
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $this->poster = app(AutoPoster::class);
    $this->acc = fn (string $key) => Account::where('system_key', $key)->firstOrFail();
});

function bal(string $accountId): int
{
    return (int) DB::table('journal_lines')->where('account_id', $accountId)->sum('amount_minor');
}

it('imports a bank statement, matches it to the books, posts the charges and reconciles to the statement balance', function () {
    $bank = app(BankingService::class)->saveAccount(actorFor($this->accountant), new BankAccount,
        ['kind' => 'bank', 'name' => 'GCB current', 'provider' => 'GCB', 'number' => '1234', 'account_id' => ($this->acc)('bank')->id]);
    // Opening: 1,000.00 in the bank; till cash 2,500.00 banked on the 3rd.
    app(JournalService::class)->opening(actorFor($this->accountant), '2026-10-01', [['account_id' => ($this->acc)('bank')->id, 'debit_minor' => 100000],
        ['account_id' => ($this->acc)('cash_till')->id, 'debit_minor' => 250000]]);
    app(BankingService::class)->transfer(actorFor($this->accountant), ($this->acc)('cash_till'), ($this->acc)('bank'), $this->hq, $this->hq, 250000, '2026-10-03', 'DEP-77');
    $this->poster->run($this->tenant->id);

    $csv = "Txn Date,Narration,Ref,Debit,Credit,Balance\n"
        ."03/10/2026,CASH DEPOSIT HQ,DEP-77,,\"2,500.00\",\"3,500.00\"\n"
        ."05/10/2026,SMS ALERT CHARGES,,12.50,,\"3,487.50\"\n"
        ."05/10/2026,MAINTENANCE FEE,,(20.00),,\"3,467.50\"\n";
    $map = ['header_rows' => 1, 'date' => 'Txn Date', 'date_format' => 'auto', 'description' => 'Narration', 'reference' => 'Ref', 'money_in' => 'Credit',
        'money_out' => 'Debit', 'balance' => 'Balance'];
    $svc = app(BankingService::class);
    expect($svc->import(actorFor($this->accountant), $bank, 'oct.csv', $csv, $map))->toBe(['added' => 3, 'skipped' => 0])
        ->and($svc->import(actorFor($this->accountant), $bank, 'oct-again.csv', $csv, $map))->toBe(['added' => 0, 'skipped' => 3])
        ->and($bank->fresh()->csv_mapping['money_in'])->toBe('Credit');

    // The deposit is suggested and matched; the opening balance has no statement line (it is the statement's opening).
    expect($svc->autoMatch(actorFor($this->accountant), $bank))->toBe(1);
    $charges = BankStatementLine::where('status', 'open')->orderBy('id')->get();
    expect($charges->pluck('amount_minor')->all())->toBe([-1250, -2000]);
    expect(fn () => $svc->match(actorFor($this->accountant), $bank, [$charges[0]->id], [DB::table('journal_lines')->where('account_id', $bank->account_id)->value('id')]))
        ->toThrow(DomainException::class);
    foreach ($charges as $l) {
        $svc->postFromStatement(actorFor($this->accountant), $bank, $l, ($this->acc)('bank_charges'), $this->hq);
    }
    $opening = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_entries.kind', 'opening')->where('account_id', $bank->account_id)->value('journal_lines.id');
    expect($svc->reconciliation($bank, '2026-10-31')['difference'])->toBe(100000); // the opening balance is in the books but on no statement line
    expect(fn () => $svc->clearAsEarlier(actorFor($this->accountant), $bank, [DB::table('journal_lines')->where('account_id', $bank->account_id)->orderByDesc('entry_date')->value('id')]))
        ->toThrow(DomainException::class, 'before the first');
    $svc->clearAsEarlier(actorFor($this->accountant), $bank, [$opening]);
    $r = $svc->reconciliation($bank, '2026-10-31');
    expect(bal(($this->acc)('bank_charges')->id))->toBe(3250)
        ->and($r['ledger'])->toBe(346750)->and($r['statement'])->toBe(346750)->and($r['outstanding'])->toBe(0)->and($r['difference'])->toBe(0);
    expect(fn () => $svc->import(actorFor(staff($this->owner, 'cash', 2, 'teller')), $bank, 'x.csv', $csv, $map))->toThrow(AccessDenied::class);
    expect(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue()->and($opening)->not->toBeNull();
});

it('reads statements the way banks and MoMo portals export them', function () {
    expect(StatementCsv::money('GHS 1,234.50'))->toBe(123450)->and(StatementCsv::money('(12.00)'))->toBe(-1200)->and(StatementCsv::money('12.00 DR'))->toBe(-1200)
        ->and(StatementCsv::money(''))->toBeNull();
    $rows = StatementCsv::parse("Date;Details;Amount\n2026-10-05 14:22:01;MoMo from 0241112222;150\n2026-10-06;Withdrawal;-40.5\nTOTAL;;109.5\n",
        ['header_rows' => 1, 'date' => '1', 'description' => 'Details', 'amount' => 'Amount', 'date_format' => 'auto']);
    expect($rows)->toHaveCount(2)->and($rows[0])->toMatchArray(['line_date' => '2026-10-05', 'amount_minor' => 15000])->and($rows[1]['amount_minor'])->toBe(-4050);
});

it('takes an expense claim from receipt to petty cash: approved within the limit, finance above it, never your own', function () {
    $sup = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->hq->id);
    $bm = staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $this->hq->id, ['expense_approve_minor' => 50000]);
    $fuel = Account::where('code', '6500')->value('id');
    $exp = app(ExpenseService::class);
    // Petty cash float from the bank.
    app(BankingService::class)->transfer(actorFor($this->accountant), ($this->acc)('bank'), ($this->acc)('petty_cash'), $this->hq, $this->hq, 100000, now()->toDateString());

    $small = $exp->submit(actorFor($sup), $this->hq, ['spent_on' => now()->toDateString(), 'account_id' => $fuel, 'description' => 'Fuel for delivery van', 'amount_minor' => 30000]);
    $big = $exp->submit(actorFor($sup), $this->hq, ['spent_on' => now()->toDateString(), 'account_id' => $fuel, 'description' => 'Generator repair', 'amount_minor' => 80000]);
    expect($small->number)->toStartWith('EXP-')
        ->and(fn () => $exp->submit(actorFor(staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->hq->id)), $this->hq,
            ['spent_on' => now()->toDateString(), 'account_id' => $fuel, 'description' => 'x', 'amount_minor' => 100]))->toThrow(AccessDenied::class)
        ->and(fn () => $exp->submit(actorFor($sup), $this->hq, ['spent_on' => now()->toDateString(), 'account_id' => ($this->acc)('bank')->id, 'description' => 'x', 'amount_minor' => 100]))
        ->toThrow(DomainException::class, 'not an expense account');

    $exp->approve(actorFor($bm), $small);
    expect(fn () => $exp->approve(actorFor($bm), $big))->toThrow(DomainException::class, 'approval limit');
    $exp->approve(actorFor($this->fm), $big);
    $own = $exp->submit(actorFor($bm), $this->hq, ['spent_on' => now()->toDateString(), 'account_id' => $fuel, 'description' => 'Taxi', 'amount_minor' => 2000]);
    expect(fn () => $exp->approve(actorFor($bm), $own))->toThrow(DomainException::class, 'your own');

    $exp->pay(actorFor($bm), $small, ($this->acc)('petty_cash'));
    $exp->pay(actorFor($this->fm), $big, ($this->acc)('bank'));
    $this->poster->run($this->tenant->id);
    expect(bal($fuel))->toBe(110000)->and(bal(($this->acc)('petty_cash')->id))->toBe(70000)->and(bal(($this->acc)('bank')->id))->toBe(-180000)
        ->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('depreciates assets straight-line each month, exactly to the residual, and takes them off the books on disposal', function () {
    Carbon::setTestNow(Carbon::create(now()->year + 1, 4, 10, 12)); // after the setup above
    $comp = AssetClass::where('code', 'COMP')->sole();
    expect(AssetClass::count())->toBe(3)->and($comp->life_months)->toBe(36);
    $assets = app(AssetService::class);
    $y = now()->year;
    $laptop = $assets->register(actorFor($this->accountant), ['name' => 'Dell laptop (finance)', 'asset_class_id' => $comp->id, 'branch_id' => $this->hq->id,
        'acquired_on' => "{$y}-01-20", 'cost_minor' => 1000000, 'residual_minor' => 100]);

    // Life-long schedule adds up exactly: 999,900 over 36 months.
    $sum = 0;
    for ($i = 0; $i < 40; $i++) {
        $sum += AssetService::monthly($laptop, $sum, $i);
    }
    expect($sum)->toBe(999900)->and(AssetService::monthly($laptop, 0, 0))->toBe(27775);

    $this->poster->run($this->tenant->id); // January, February and March have ended
    expect(DB::table('asset_depreciation')->count())->toBe(3)
        ->and(bal(Account::where('system_key', 'depreciation')->value('id')))->toBe(3 * 27775)
        ->and(AssetService::bookValue($laptop))->toBe(1000000 - 3 * 27775);

    // Sold in April for 9,000.00 into the bank: April is depreciated too, cost and depreciation leave, the loss is recorded.
    $assets->dispose(actorFor($this->accountant), $laptop, "{$y}-04-10", 900000, ($this->acc)('bank'), 'Sold to staff');
    $this->poster->run($this->tenant->id);
    $acc = Account::where('system_key', 'accumulated_depreciation')->value('id');
    expect(DB::table('asset_depreciation')->count())->toBe(4)
        ->and(bal($comp->cost_account_id))->toBe(-1000000) // the purchase itself was never posted in this test; only the disposal's credit
        ->and(bal($acc))->toBe(0)
        ->and(bal(($this->acc)('disposal')->id))->toBe(1000000 - 4 * 27775 - 900000)
        ->and(FixedAsset::sole()->status)->toBe('disposed')
        ->and(fn () => $assets->register(actorFor(staff($this->owner, 'cash', 2, 'teller')), []))->toThrow(AccessDenied::class);
    Carbon::setTestNow();
});

it('compares the budget with the ledger and prepares the VAT return figures', function () {
    $y = app(GeneralLedger::class)->period($this->tenant->id, now()->toDateString())->year;
    $rent = Account::where('code', '6200')->value('id');
    $sales = ($this->acc)('sales_pos')->id;
    app(BudgetService::class)->save(actorFor($this->fm), $y, $this->hq, [$rent => BudgetService::spread(1200000), $sales => array_fill(0, 12, 500000)]);
    expect(BudgetService::spread(1000))->toBe([83, 83, 83, 83, 83, 83, 83, 83, 83, 83, 83, 87])
        ->and(fn () => app(BudgetService::class)->save(actorFor($this->accountant), $y, $this->hq, [$rent => [1]]))->toThrow(AccessDenied::class);

    app(JournalService::class)->manual(actorFor($this->accountant), now()->toDateString(), 'Rent', [['account_id' => $rent, 'debit_minor' => 120000, 'branch_id' => $this->hq->id],
        ['account_id' => ($this->acc)('bank')->id, 'credit_minor' => 120000, 'branch_id' => $this->hq->id]]);
    $month = app(GeneralLedger::class)->period($this->tenant->id, now()->toDateString())->period;
    $rows = collect(app(FinancialReports::class)->budgetVsActual($this->tenant->id, $y, $month, $month, $this->hq->id))->keyBy(fn ($r) => $r['account']->code);
    expect($rows['6200'])->toMatchArray(['budget' => 100000, 'actual' => 120000, 'variance' => 20000, 'better' => false])
        ->and($rows['4100'])->toMatchArray(['budget' => 500000, 'actual' => 0, 'better' => false]);

    $vat = app(FinancialReports::class)->vatReturn($this->tenant->id, now()->startOfMonth()->toDateString(), now()->toDateString());
    expect(collect($vat['taxes'])->pluck('code')->all())->toBe(['VAT', 'NHIL', 'GETFUND'])->and($vat['sales_total'])->toBe(0);
});

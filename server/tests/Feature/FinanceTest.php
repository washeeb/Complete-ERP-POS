<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Customers\StoredValues;
use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\FinancialReports;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Finance\GlCheck;
use App\Domain\Finance\JournalService;
use App\Domain\Finance\PeriodService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payables\BillService;
use App\Domain\Payables\SupplierLedger;
use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Pos\ReturnService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\PriceList;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    $this->bread = makeProduct($this->owner, 'BREAD', 'Bread', retail: '25.00', tax: 'EXEMPT');
    // Opening stock at cost: 50 × 40.00 and 50 × 18.00.
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '50', 'opening', '', null, '40.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->bread, $this->bread->units->first(), '50', 'opening', '', null, '18.00');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Till, $this->till->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 10000);
    $this->ama = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $this->fm = staff($this->owner, 'kwame', 5, 'finance_manager');
    $this->gm = staff($this->owner, 'gm', 5, 'general_manager');
    $this->seq = 0;
    $this->gl = app(GeneralLedger::class);
    $this->poster = app(AutoPoster::class);
});

/** A till sale (no promotions) as the till would send it. */
function finSale($t, array $items, ?array $payWith = null, array $extra = []): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => [$p, $qty]) {
        $unit = $p->units->first();
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => $p->category_id, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
            'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price];
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }
    $payload = ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $t->retail->id,
        'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => $payWith ?? [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]] + $extra;

    return app(SaleService::class)->post($t->device, $t->cashier, $payload)['sale'];
}

function glBalance(string $key): int
{
    return (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', $key)->sum('journal_lines.amount_minor');
}

function receiveMilo($t, string $qty, string $cost): array
{
    $supplier = Supplier::where('code', 'NESTLE')->first() ?? app(SupplierService::class)->save(actorFor($t->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'Nestlé Ghana', 'payment_terms_days' => 30]);
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($t->owner), new PurchaseOrder,
        ['supplier_id' => $supplier->id, 'warehouse_id' => $t->wh->id, 'lines' => [['product_uom_id' => $t->milo->units->first()->id, 'qty' => $qty, 'unit_cost' => $cost]]]);
    app(PurchaseOrderService::class)->submit(actorFor($t->owner), $po);
    app(PurchaseOrderService::class)->approve(actorFor($t->gm), $po->fresh());
    $po = $po->fresh('lines');
    $grn = app(GoodsReceiptService::class)->receive(actorFor($t->owner), $po, ['lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => $qty]]]);

    return [$supplier, $grn];
}

it('posts every module into a balanced ledger that ties to customers, suppliers, stock, gift cards and points (done-when)', function () {
    expect(Account::where('system_key', 'cash_till')->exists())->toBeTrue()->and(Account::count())->toBeGreaterThan(60);

    // Till: a cash sale, a loyalty sale on account, and a return against the first receipt.
    $s1 = finSale($this, [[$this->milo, '2'], [$this->bread, '1']]); // 116.00 + 25.00
    app(CustomerService::class)->setCredit(actorFor($this->owner), $this->ama, 100_000, 30, false);
    finSale($this, [[$this->milo, '1']], [['method' => 'account', 'amount_minor' => 5800, 'tendered_minor' => 5800]], ['customer_id' => $this->ama->id]);
    $ret = app(ReturnService::class);
    $line = collect($ret->returnable($s1->fresh('lines', 'payments'))['lines'])->firstWhere('product_id', $this->milo->id);
    $ret->post($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(),
        'shift_id' => $this->shift->id, 'original_sale_id' => $s1->id, 'reason' => 'Damaged tin', 'lines' => [['original_line_id' => $line['id'], 'qty' => '1']],
        'refunds' => [['method' => 'cash', 'amount_minor' => 5800]]]);

    // Purchasing: 10 Milo received at 40.00, billed at 41.00 (2.5 % over → held), approved by finance; rent billed straight to expense; part paid.
    [$supplier, $grn] = receiveMilo($this, '10', '40.00');
    $bills = app(BillService::class);
    $bill = $bills->create(actorFor($this->accountant), $supplier, $this->hq, ['supplier_invoice_no' => 'NG-5501', 'bill_date' => now()->toDateString(),
        'lines' => [['goods_receipt_line_id' => $grn->lines[0]->id, 'qty' => '10', 'unit_cost_minor' => 4100]]]);
    expect($bill->status)->toBe('pending_approval')->and($bill->variance_minor)->toBe(1000)->and($bill->total_minor)->toBe(49200); // 410.00 + 20 % taxes
    expect(fn () => $bills->approve(actorFor($this->accountant), $bill))->toThrow(AccessDenied::class); // entering bills is not approving them
    $bills->approve(actorFor($this->fm), $bill, 'Price rise notified by phone');
    $landlord = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'LAND', 'name' => 'Osu Properties']);
    $bills->create(actorFor($this->accountant), $landlord, $this->hq, ['supplier_invoice_no' => 'R-OCT', 'bill_date' => now()->toDateString(),
        'lines' => [['account_id' => Account::where('code', '6200')->value('id'), 'description' => 'October rent', 'amount_minor' => 300000]]]);
    app(SupplierPaymentService::class)->pay(actorFor($this->fm), $supplier, $this->hq, Account::where('system_key', 'bank')->first(), 'bank', 20000, 'TRF-1');

    // Go-live balances, a gift card, a damaged-stock write-off, and a cash shortage at shift close.
    $kofi = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Kofi Shop', 'phone' => '0201234567']);
    app(CustomerService::class)->openingBalance(actorFor($this->owner), $kofi, 75000, now()->toDateString());
    $old = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'KASA', 'name' => 'Kasapreko']);
    app(SupplierPaymentService::class)->opening(actorFor($this->accountant), $old, 120000, now()->toDateString());
    app(StoredValues::class)->sellCard(actorFor($this->owner), 10000, 'cash', null);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->bread, $this->bread->units->first(), '-2', 'adjustment', 'damaged');
    $expected = app(ShiftService::class)->expectedCash($this->shift->fresh());
    app(ShiftService::class)->close($this->device, $this->cashier, $expected - 500, 'Short 5.00');

    $r = $this->poster->run($this->tenant->id);
    expect($r['errors'])->toBe([])->and(array_sum($r['posted']))->toBeGreaterThan(10);

    $check = app(GlCheck::class)->run($this->tenant->id);
    expect($check['problems'])->toBe([])->and($check['ok'])->toBeTrue();
    expect(collect($check['ties'])->pluck('ledger', 'control')->all())->toMatchArray([
        'receivables' => 5800 + 75000,
        'payables' => -(49200 + 300000 - 20000 + 120000),
        'inventory' => 290000 - 3 * 4000 - 1800 + 4000 + 40000 - 2 * 1800, // opening; 3 Milo and 1 bread sold; 1 Milo back; 10 received; 2 bread written off
        'stored_value' => -10000,
    ]);
    expect(glBalance('grni'))->toBe(0) // received 400.00, billed 400.00 at receipt cost
        ->and(glBalance('price_variance'))->toBe(1000)
        ->and(glBalance('cash_over_short'))->toBe(500)
        ->and(glBalance('loyalty'))->toBe(-(int) DB::table('loyalty_balances')->sum('points'));

    // Reports: the trial balance sums to zero, the balance sheet balances, and posting again changes nothing.
    $tb = app(FinancialReports::class)->trialBalance($this->tenant->id, now()->startOfYear()->toDateString(), now()->toDateString());
    expect($tb['totals']['closing'])->toBe(0)->and($tb['totals']['debit'])->toBe($tb['totals']['credit']);
    expect(app(FinancialReports::class)->balanceSheet($this->tenant->id, now()->toDateString())['balanced'])->toBeTrue();
    $pl = app(FinancialReports::class)->profitAndLoss($this->tenant->id, now()->startOfMonth()->toDateString(), now()->toDateString());
    expect($pl['income'])->toBeGreaterThan(0)->and($pl['net_profit'])->toBe($pl['income'] - $pl['cost_of_sales'] - $pl['expenses']);
    expect(array_sum($this->poster->run($this->tenant->id)['posted']))->toBe(0);
    expect($this->artisan('erp:gl-check')->run())->toBe(0);
});

it('summarises till sales per branch and day: revenue net of tax, each tax to its own account, each tender to its own account', function () {
    finSale($this, [[$this->milo, '1']]);
    finSale($this, [[$this->milo, '1'], [$this->bread, '2']], [['method' => 'momo', 'amount_minor' => 10800, 'tendered_minor' => 10800, 'provider' => 'mtn', 'reference' => 'MP123456']]);
    $this->poster->run($this->tenant->id);

    $entry = JournalEntry::where('source_type', 'sales')->sole();
    $taxes = Sale::all()->flatMap(fn ($s) => $s->tax_breakdown)->groupBy('code')->map->sum('amount_minor');
    expect($entry->memo)->toContain('2 receipts')->and(DB::table('gl_postings')->where('journal_entry_id', $entry->id)->count())->toBe(2)
        ->and(glBalance('cash_till'))->toBe(5800)->and(glBalance('momo'))->toBe(10800)
        ->and(glBalance('sales_pos'))->toBe(-(int) Sale::sum('net_minor'))
        ->and(glBalance('output_tax:VAT'))->toBe(-$taxes['VAT'])->and(glBalance('output_tax:NHIL'))->toBe(-$taxes['NHIL']);
});

it('keeps journals to the rules: balanced, no control accounts, open months only, reversed not edited', function () {
    $j = app(JournalService::class);
    $rent = Account::where('code', '6200')->value('id');
    $bank = Account::where('system_key', 'bank')->value('id');
    $ar = Account::where('system_key', 'receivables')->value('id');

    expect(fn () => $j->manual(actorFor($this->accountant), now()->toDateString(), 'Rent', [['account_id' => $rent, 'debit_minor' => 1000], ['account_id' => $bank, 'credit_minor' => 900]]))
        ->toThrow(DomainException::class, 'differ')
        ->and(fn () => $j->manual(actorFor($this->accountant), now()->toDateString(), 'Fix', [['account_id' => $ar, 'debit_minor' => 1000], ['account_id' => $bank, 'credit_minor' => 1000]]))
        ->toThrow(DomainException::class, 'kept by its own ledger')
        ->and(fn () => $j->manual(actorFor(staff($this->owner, 'it', 4, 'it_admin')), now()->toDateString(), 'Rent', [['account_id' => $rent, 'debit_minor' => 1000], ['account_id' => $bank, 'credit_minor' => 1000]]))
        ->toThrow(AccessDenied::class);

    $e = $j->manual(actorFor($this->accountant), now()->toDateString(), 'Rent paid in cash by the director', [['account_id' => $rent, 'debit_minor' => 250000],
        ['account_id' => Account::where('code', '3100')->value('id'), 'credit_minor' => 250000]]);
    expect($e->number)->toStartWith('JE-')->and($e->total_minor)->toBe(250000);
    expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->where('journal_entry_id', $e->id)->update(['amount_minor' => 1])))->toThrow(Exception::class, 'append-only');

    $rev = $j->reverse(actorFor($this->accountant), $e, now()->toDateString(), 'Was a loan, not capital');
    expect($rev->reverses_id)->toBe($e->id)->and((int) DB::table('journal_lines')->where('account_id', $rent)->sum('amount_minor'))->toBe(0)
        ->and(fn () => $j->reverse(actorFor($this->accountant), $e, now()->toDateString(), 'again'))->toThrow(DomainException::class, 'already reversed');

    // The database itself refuses an unbalanced entry.
    DB::statement('SET CONSTRAINTS journal_lines_balanced IMMEDIATE');
    $period = $this->gl->period($this->tenant->id, now()->toDateString());
    $id = (string) Str::uuid7();
    DB::table('journal_entries')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'number' => 'X-1', 'entry_date' => now()->toDateString(), 'period_id' => $period->id,
        'kind' => 'manual', 'memo' => 'bad', 'currency_code' => 'GHS', 'total_minor' => 5, 'posted_by_name' => 'x', 'created_at' => now()]);
    expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->insert(['id' => (string) Str::uuid7(), 'journal_entry_id' => $id, 'tenant_id' => $this->tenant->id, 'line_no' => 1,
        'account_id' => $rent, 'amount_minor' => 5, 'entry_date' => now()->toDateString()])))->toThrow(Exception::class, 'does not balance');
});

it('closes months in order, posts late documents into the next open month, and only the GM reopens', function () {
    $y = now()->year + 1; // a clock after everything set up above, independent of today's date
    Carbon::setTestNow(Carbon::create($y, 10, 6, 12));
    $periods = app(PeriodService::class);
    $sep = $this->gl->period($this->tenant->id, "{$y}-09-10");
    expect(fn () => $periods->close(actorFor($this->fm), $sep))->toThrow(DomainException::class, 'first.');
    foreach (FiscalPeriod::where('starts_on', '<=', "{$y}-09-01")->orderBy('starts_on')->get() as $p) {
        $periods->close(actorFor($this->fm), $p);
    }
    expect($sep->fresh()->status)->toBe('closed');

    // A journal cannot go into September; a document dated in September posts on 1 October.
    $rent = Account::where('code', '6200')->value('id');
    $cash = Account::where('system_key', 'cash_office')->value('id');
    expect(fn () => app(JournalService::class)->manual(actorFor($this->accountant), "{$y}-09-20", 'Late rent', [['account_id' => $rent, 'debit_minor' => 100], ['account_id' => $cash, 'credit_minor' => 100]]))
        ->toThrow(DomainException::class, "Sep {$y} is closed");
    app(CustomerService::class)->openingBalance(actorFor($this->owner), $this->ama, 5000, "{$y}-09-15");
    $this->poster->run($this->tenant->id);
    $late = JournalEntry::where('source_type', 'customer_openings')->sole();
    expect($late->entry_date->toDateString())->toBe("{$y}-10-01")->and($late->memo)->toContain("dated {$y}-09-15");

    // Reopening: not the finance manager; the GM, latest month first, with a reason.
    $aug = FiscalPeriod::where('starts_on', "{$y}-08-01")->first();
    expect(fn () => $periods->reopen(actorFor($this->fm), $sep, 'Missed invoice'))->toThrow(AccessDenied::class)
        ->and(fn () => $periods->reopen(actorFor($this->gm), $aug, 'Missed invoice'))->toThrow(DomainException::class, "Reopen Sep {$y} first")
        ->and(fn () => $periods->reopen(actorFor($this->gm), $sep, ' '))->toThrow(DomainException::class, 'reason');
    $periods->reopen(actorFor($this->gm), $sep, 'Supplier invoice found late');
    expect($sep->fresh()->status)->toBe('open')
        ->and(DB::table('audit_events')->where('action', 'fiscal_period.reopened')->value('reason'))->toBe('Supplier invoice found late');
    Carbon::setTestNow();
});
it('matches bills to what was received: never more than received, within tolerance posts at once, a rejected bill frees the quantity', function () {
    [$supplier, $grn] = receiveMilo($this, '10', '40.00');
    $bills = app(BillService::class);
    $line = $grn->lines[0]->id;
    $make = fn (string $qty, int $cost, string $no) => $bills->create(actorFor($this->accountant), $supplier, $this->hq,
        ['supplier_invoice_no' => $no, 'bill_date' => now()->toDateString(), 'lines' => [['goods_receipt_line_id' => $line, 'qty' => $qty, 'unit_cost_minor' => $cost]]]);

    expect(fn () => $make('11', 4000, 'A1'))->toThrow(DomainException::class, 'only 10.000');
    $own = app(BillService::class)->create(actorFor($this->fm), $supplier, $this->hq, ['supplier_invoice_no' => 'OWN-1', 'bill_date' => now()->toDateString(),
        'lines' => [['goods_receipt_line_id' => $line, 'qty' => '1', 'unit_cost_minor' => 9000]]]);
    expect(fn () => $bills->approve(actorFor($this->fm), $own))->toThrow(DomainException::class, 'Someone else');
    $bills->reject(actorFor($this->gm), $own, 'Typed the wrong price');
    $ok = $make('6', 4010, 'A1'); // 0.25 % over the order price: within 2 %
    expect($ok->status)->toBe('posted')->and($ok->due_on->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and(app(SupplierLedger::class)->openItems($supplier->id))->toHaveCount(1);
    expect(fn () => $make('1', 4000, 'A1'))->toThrow(DomainException::class, 'already entered');

    $held = $make('4', 5000, 'A2');
    expect($held->status)->toBe('pending_approval')->and(fn () => $make('1', 4000, 'A3'))->toThrow(DomainException::class, 'only 0.000');
    $bills->reject(actorFor($this->fm), $held, 'Wrong price — supplier to send a credit');
    expect($make('4', 4000, 'A3')->status)->toBe('posted');

    // Pay part: oldest bill first; the rest stays open.
    app(SupplierPaymentService::class)->pay(actorFor($this->fm), $supplier, $this->hq, Account::where('system_key', 'bank')->first(), 'bank', 10000, 'TRF-9');
    expect((int) DB::table('supplier_balances')->where('supplier_id', $supplier->id)->value('balance_minor'))->toBe($ok->total_minor + 19200 - 10000)
        ->and(fn () => app(SupplierPaymentService::class)->pay(actorFor($this->accountant), $supplier, $this->hq, Account::where('system_key', 'bank')->first(), 'bank', 100, 'x'))
        ->toThrow(AccessDenied::class);
});

<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Inventory\ExpiryService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Inventory\StockBatches;
use App\Domain\Inventory\StockLedger;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ExpiryReturns;
use App\Models\Account;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $catalog = app(CatalogService::class);
    $this->amox = makeProduct($this->owner, 'AMOX', 'Amoxicillin 500mg', retail: '2.00');
    $catalog->saveProduct(actorFor($this->owner), $this->amox, ['track_batches' => true]);
    $this->amox = $this->amox->fresh();
    $this->pethidine = makeProduct($this->owner, 'PETH', 'Pethidine 50mg', retail: '20.00');
    $catalog->saveProduct(actorFor($this->owner), $this->pethidine, ['track_batches' => true, 'drug_schedule' => 'controlled']);
    $this->pethidine = $this->pethidine->fresh();
    $inv = app(InventoryService::class);
    // Amoxicillin: 10 expiring in 3 days, 20 in 200 days (cost 1.00); pethidine: 5 expiring in 3 days (cost 10.00).
    $inv->adjust(actorFor($this->owner), $this->wh, $this->amox, $this->amox->units->first(), '10', 'opening', '', null, '1.00', 'A-OLD', now()->addDays(3)->toDateString());
    $inv->adjust(actorFor($this->owner), $this->wh, $this->amox, $this->amox->units->first(), '20', 'opening', '', null, '1.00', 'A-NEW', now()->addDays(200)->toDateString());
    $inv->adjust(actorFor($this->owner), $this->wh, $this->pethidine, $this->pethidine->units->first(), '5', 'opening', '', null, '10.00', 'P-1', now()->addDays(3)->toDateString());
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'ERNEST', 'name' => 'Ernest Chemists', 'return_days_before_expiry' => 0]);
    $this->pharmacist = withMfa(staff($this->owner, 'abena', 4, 'pharmacist', ScopeType::Branch, $this->branches['HQ']->id));
    $this->svc = app(ExpiryService::class);
    Carbon::setTestNow(now()->addDays(5)); // A-OLD and P-1 have now expired
});

afterEach(fn () => Carbon::setTestNow());

function expKey($t, string $batchNo): string
{
    return $t->wh->id.'|'.DB::table('stock_batches')->where('batch_no', $batchNo)->value('id');
}

function expOnHand($t, string $batchNo): string
{
    return (string) DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
        ->where('l.warehouse_id', $t->wh->id)->where('b.batch_no', $batchNo)->value('l.on_hand');
}

function expGl(string $systemKey): int
{
    return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.system_key', $systemKey)->sum('l.amount_minor');
}

it('lists expired and expiring batches with days left, value and the controlled flag', function () {
    $rows = collect($this->svc->batches($this->tenant->id, ['expired' => true]));
    expect($rows->pluck('batch_no')->sort()->values()->all())->toBe(['A-OLD', 'P-1'])
        ->and($rows->firstWhere('batch_no', 'A-OLD'))->toMatchArray(['days_left' => -2, 'qty' => '10.000', 'value_minor' => 1000, 'controlled' => false])
        ->and($rows->firstWhere('batch_no', 'P-1')['controlled'])->toBeTrue();
    expect(collect($this->svc->batches($this->tenant->id, ['within_days' => 365]))->pluck('batch_no'))->toContain('A-NEW');
});

it('disposes of ticked batches only after someone else approves, with the evidence the company asks for', function () {
    $keys = [expKey($this, 'A-OLD')];
    expect(fn () => $this->svc->dispose(actorFor($this->pharmacist), $keys, ['witness' => 'Kofi Mensah']))->toThrow(DomainException::class, 'how the stock is disposed');
    expect(fn () => $this->svc->dispose(actorFor($this->pharmacist), $keys, ['method' => 'incineration']))->toThrow(DomainException::class, 'witness');

    $a = $this->svc->dispose(actorFor($this->pharmacist), $keys, ['method' => 'incineration', 'witness' => 'Kofi Mensah (MCA)']);
    expect($a->kind)->toBe('disposal')->and($a->status)->toBe('submitted')->and($a->lines)->toHaveCount(1)->and(expOnHand($this, 'A-OLD'))->toBe('10.000');

    // Not by the person who prepared it, and not without "approve stock adjustments" — whatever the value.
    expect(fn () => app(StockAdjustmentService::class)->post(actorFor($this->pharmacist), $a))->toThrow(AccessDenied::class);
    expect(fn () => app(StockAdjustmentService::class)->post(actorFor($this->owner), $a))->not->toThrow(Throwable::class);
    expect(expOnHand($this, 'A-OLD'))->toBe('0.000')->and(expOnHand($this, 'A-NEW'))->toBe('20.000')
        ->and($a->fresh()->value_minor)->toBe(-1000)->and(app(StockLedger::class)->check())->toBe([]);

    app(AutoPoster::class)->run($this->tenant->id);
    expect(expGl('stock_adjustment'))->toBe(1000);
});

it('refuses your own disposal even with approval rights, and always asks a witness for controlled drugs', function () {
    app(Settings::class)->set(actorFor($this->owner), 'stock.disposal_evidence', 'reason');
    expect(fn () => $this->svc->dispose(actorFor($this->owner), [expKey($this, 'P-1')], []))->toThrow(DomainException::class, 'Controlled drugs');
    $a = $this->svc->dispose(actorFor($this->owner), [expKey($this, 'A-OLD')], []);
    expect(fn () => app(StockAdjustmentService::class)->post(actorFor($this->owner), $a))->toThrow(DomainException::class, 'other than the person');

    app(Settings::class)->set(actorFor($this->owner), 'stock.disposal_evidence', 'method_witness_certificate');
    expect(fn () => $this->svc->dispose(actorFor($this->owner), [expKey($this, 'P-1')], ['method' => 'fda_supervised', 'witness' => 'FDA officer']))
        ->toThrow(DomainException::class, 'certificate');
    expect(fn () => $this->svc->dispose(actorFor($this->owner), [expKey($this, 'A-OLD'), expKey($this, 'A-OLD').'x'], []))->toThrow(DomainException::class);
});

it('returns stock to the supplier for a credit note: stock out, supplier owed less, books balanced', function () {
    $r = $this->svc->prepareReturn(actorFor($this->pharmacist), [expKey($this, 'A-OLD'), expKey($this, 'P-1')], $this->supplier->id, 'credit_note', 'expired', 'Collected by rep');
    expect($r->number)->toStartWith('RTS-')->and($r->status)->toBe('submitted')->and($r->total_minor)->toBe(1000 + 5000);
    expect(fn () => $this->svc->postReturn(actorFor($this->pharmacist), $r))->toThrow(AccessDenied::class);

    $r = $this->svc->postReturn(actorFor($this->owner), $r);
    expect($r->status)->toBe('posted')->and($r->total_minor)->toBe(6000)->and(expOnHand($this, 'A-OLD'))->toBe('0.000')->and(expOnHand($this, 'P-1'))->toBe('0.000')
        ->and(DB::table('stock_movements')->where('source_id', $r->id)->where('type', 'supplier_return')->count())->toBe(2)
        ->and((int) DB::table('supplier_ledger')->where('doc_id', $r->id)->value('amount_minor'))->toBe(-6000);

    app(AutoPoster::class)->run($this->tenant->id);
    expect(expGl('supplier_returns'))->toBe(0)->and(expGl('inventory'))->toBe(2000); // 20 A-NEW at 1.00 left

    $r = $this->svc->settle(actorFor($this->pharmacist), $r, ['reference' => 'CN-4471']);
    expect($r->settlement_ref)->toBe('CN-4471')->and($r->settled_at)->not->toBeNull();
});

it('books a refund when the money comes in, the shortfall as a stock loss', function () {
    $r = $this->svc->prepareReturn(actorFor($this->pharmacist), [expKey($this, 'P-1')], $this->supplier->id, 'refund', 'expired');
    $r = $this->svc->postReturn(actorFor($this->owner), $r);
    app(AutoPoster::class)->run($this->tenant->id);
    expect(expGl('supplier_returns'))->toBe(5000); // waiting for the money

    $momo = Account::where('system_key', 'momo')->firstOrFail();
    expect(fn () => $this->svc->settle(actorFor($this->pharmacist), $r, ['reference' => 'MOMO-1', 'refund' => 4000, 'account_id' => $momo->id]))->toThrow(AccessDenied::class);
    $this->svc->settle(actorFor($this->owner), $r, ['reference' => 'MOMO-1', 'refund' => 4000, 'account_id' => $momo->id]);
    app(AutoPoster::class)->run($this->tenant->id);
    expect(expGl('supplier_returns'))->toBe(0)->and(expGl('momo'))->toBe(4000)->and(expGl('stock_adjustment'))->toBe(1000);
});

it('stops selling a batch the set number of days before it expires', function () {
    Carbon::setTestNow(now()->subDays(5)); // back to the day of the opening stock: A-OLD has 3 days left
    $fefo = fn () => app(StockBatches::class)->fefo(actorFor($this->owner), $this->wh, $this->amox, Qty::toMilli('5'), true, false);
    expect(DB::transaction($fefo)[0][0])->toBe(DB::table('stock_batches')->where('batch_no', 'A-OLD')->value('id'));
    app(Settings::class)->set(actorFor($this->owner), 'stock.block_sale_days_before_expiry', 30);
    expect(DB::transaction($fefo)[0][0])->toBe(DB::table('stock_batches')->where('batch_no', 'A-NEW')->value('id'));
    expect(fn () => DB::transaction(fn () => app(StockBatches::class)->fefo(actorFor($this->owner), $this->wh, $this->pethidine, Qty::toMilli('1'), true, false)))
        ->toThrow(DomainException::class, 'within 30 days');
});

it('prepares disposals and returns from the screen by ticking batches', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->pharmacist);
    Livewire::test(ExpiryReturns::class)->assertSee('Amoxicillin 500mg')->assertSee('A-OLD')->assertDontSee('A-NEW')
        ->callTableBulkAction('dispose', [expKey($this, 'A-OLD')], ['reason' => 'expired', 'method' => 'incineration', 'disposed_on' => now()->toDateString(), 'witness' => 'Kofi'])
        ->assertHasNoTableBulkActionErrors();
    expect(StockAdjustment::where('kind', 'disposal')->sole()->witness_name)->toBe('Kofi');

    Livewire::test(ExpiryReturns::class)
        ->callTableBulkAction('return', [expKey($this, 'P-1')], ['supplier_id' => $this->supplier->id, 'settlement' => 'none', 'reason' => 'expired'])
        ->assertHasNoTableBulkActionErrors();
    expect(SupplierReturn::sole()->settlement)->toBe('none');

    $this->get(route('print.supplier-return', SupplierReturn::sole()))->assertOk()->assertSee('RETURN TO SUPPLIER')->assertSee('Pethidine 50mg');
    $this->get(route('print.disposal', StockAdjustment::where('kind', 'disposal')->sole()))->assertOk()->assertSee('DISPOSAL RECORD')->assertSee('Kofi');
    $this->get(route('print.disposal-register', ['warehouse' => $this->wh->id]))->assertOk()->assertSee('No disposals approved');
});

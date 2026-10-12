<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Filament\Pages\AgedPayables;
use App\Filament\Pages\FinancialReports;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\SupplierBills\Pages\CreateSupplierBill;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\SupplierPayments\Pages\ListSupplierPayments;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '20', 'opening', '', null, '40.00');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
    $this->actingAs($this->fm);
});

it('shows the chart, journals, months and reports to finance, and posts a journal from the screen', function () {
    app(AutoPoster::class)->run($this->tenant->id);
    $this->get(AccountResource::getUrl())->assertOk()->assertSee('Cash on hand – tills')->assertSee('800.00'); // 20 × 40.00 opening stock
    $this->get(FiscalPeriodResource::getUrl())->assertOk();
    foreach (array_keys(FinancialReports::REPORTS) as $r) {
        $this->get(FinancialReports::getUrl(['report' => $r]))->assertOk();
    }
    $this->get(FinancialReports::getUrl(['report' => 'check']))->assertSee('ties to its sub-ledgers');

    Livewire::test(ListJournalEntries::class)->callAction('journal', ['date' => now()->toDateString(), 'memo' => 'Owner put cash in the safe', 'lines' => [
        ['account_id' => Account::where('system_key', 'cash_office')->value('id'), 'debit' => '500', 'credit' => null, 'branch_id' => $this->branches['HQ']->id],
        ['account_id' => Account::where('code', '3100')->value('id'), 'debit' => null, 'credit' => '500', 'branch_id' => $this->branches['HQ']->id],
    ]])->assertNotified('Journal posted');
    $je = JournalEntry::where('kind', 'manual')->sole();
    expect($je->total_minor)->toBe(50000);
    $this->get(JournalEntryResource::getUrl('view', ['record' => $je]))->assertOk()->assertSee('Owner put cash in the safe')->assertSee('500.00');

    Livewire::test(ListAccounts::class)->callAction('create', ['code' => '1135', 'name' => 'Bank – savings', 'type' => 'asset', 'is_active' => true,
        'parent_id' => Account::where('code', '1100')->value('id')])->assertHasNoActionErrors();
    expect(Account::where('code', '1135')->exists())->toBeTrue();
});

it('enters a bill from goods received, pays it, and shows what is owed', function () {
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'Nestlé Ghana', 'payment_terms_days' => 30]);
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($this->owner), new PurchaseOrder,
        ['supplier_id' => $supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['product_uom_id' => $this->milo->units->first()->id, 'qty' => '5', 'unit_cost' => '40.00']]]);
    app(PurchaseOrderService::class)->submit(actorFor($this->owner), $po);
    app(PurchaseOrderService::class)->approve(actorFor(staff($this->owner, 'gm', 5, 'general_manager')), $po->fresh());
    $grn = app(GoodsReceiptService::class)->receive(actorFor($this->owner), $po->fresh('lines'), ['lines' => [['purchase_order_line_id' => $po->lines()->first()->id, 'qty' => '5']]]);

    Livewire::test(CreateSupplierBill::class)
        ->fillForm(['supplier_id' => $supplier->id, 'supplier_invoice_no' => 'NG-77', 'branch_id' => $this->branches['HQ']->id, 'bill_date' => now()->toDateString()])
        ->fillForm(['grns' => [$grn->id]])
        ->assertSet('data.stock_lines', fn ($lines) => count($lines) === 1 && array_values($lines)[0]['unit_cost'] === '40.00')
        ->call('create')->assertHasNoFormErrors();
    $bill = SupplierBill::sole();
    expect($bill->status)->toBe('posted')->and($bill->total_minor)->toBe(24000);
    $this->get(SupplierBillResource::getUrl('view', ['record' => $bill]))->assertOk()->assertSee('NG-77');

    Livewire::test(ListSupplierPayments::class)->callAction('pay', ['supplier_id' => $supplier->id, 'account_id' => Account::where('system_key', 'bank')->value('id'),
        'method' => 'bank', 'amount' => '100', 'reference' => 'TRF-1', 'paid_on' => now()->toDateString(), 'branch_id' => $this->branches['HQ']->id])->assertNotified('Payment recorded');
    expect((int) DB::table('supplier_balances')->where('supplier_id', $supplier->id)->value('balance_minor'))->toBe(14000);
    $this->get(AgedPayables::getUrl())->assertOk()->assertSee('Nestlé Ghana')->assertSee('140.00');
});

it('keeps finance away from people without access, including IT admin', function () {
    foreach ([staff($this->owner, 'it', 4, 'it_admin'), staff($this->owner, 'esi', 2, 'teller')] as $u) {
        $this->flushSession();
        $this->actingAs(withMfa($u));
        $this->get(AccountResource::getUrl())->assertForbidden();
        $this->get(FinancialReports::getUrl())->assertForbidden();
        $this->get(SupplierBillResource::getUrl('create'))->assertForbidden();
    }
});

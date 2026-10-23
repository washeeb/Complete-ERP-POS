<?php

use App\Domain\Inventory\BinService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Purchasing\SupplierService;
use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Resources\Bins\BinResource;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\ReorderRules\ReorderRuleResource;
use App\Filament\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Resources\StockCounts\Pages\ViewStockCount;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Bin;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->officer = withMfa(staff($this->owner, 'ama', 2, 'purchasing_officer'));
    $this->manager = withMfa(staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id));
});

it('creates a supplier with its products, then a purchase order that fills in the supplier cost', function () {
    $this->actingAs($this->officer);
    Livewire::test(CreateSupplier::class)->fillForm(['code' => 'NESTLE', 'name' => 'NESTLÉ GHANA', 'payment_terms_days' => 30,
        'products' => [['product_uom_id' => $this->ctn->id, 'supplier_sku' => 'N-24', 'last_cost' => '240', 'min_order_qty' => '5', 'is_preferred' => true]]])
        ->call('create')->assertHasNoFormErrors();
    $supplier = Supplier::sole();
    $this->get(SupplierResource::getUrl())->assertOk()->assertSee('NESTLÉ GHANA')->assertSee('30 days');

    Livewire::test(CreatePurchaseOrder::class)->fillForm(['supplier_id' => $supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '10', 'unit_cost' => '']]])->call('create')->assertHasNoFormErrors();
    $po = PurchaseOrder::sole();
    expect($po->net_minor)->toBe(240000)->and($po->status)->toBe('draft');

    $this->get(PurchaseOrderResource::getUrl('view', ['record' => $po]))->assertOk()->assertSee($po->number)->assertSee('MILO · MILO 400G')->assertSee('2,400.00');
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])->callAction('submit')->assertNotified('Submitted for approval');
});

it('lets a manager approve and receive from the order screen, into a bin', function () {
    app(BinService::class)->setUseBins(actorFor($this->owner), $this->hq, true);
    $a1 = app(BinService::class)->saveBin(actorFor($this->owner), new Bin, ['warehouse_id' => $this->hq->id, 'code' => 'A-01']);
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'NESTLÉ GHANA']);
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($this->officer), new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '10', 'unit_cost' => '240']]]);
    app(PurchaseOrderService::class)->submit(actorFor($this->officer), $po);

    $this->actingAs($this->manager);
    $this->get(PurchaseOrderResource::getUrl())->assertOk()->assertSee('Waiting for approval');
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])->callAction('approve')->assertNotified('Approved');
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])->assertActionVisible('receive')
        ->callAction('receive', ['supplier_ref' => 'DN-1', 'lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '10', 'bin_id' => $a1->id]]])
        ->assertHasNoActionErrors()->assertNotified('GRN-TEST-000001 posted');

    $grn = GoodsReceipt::sole();
    expect($grn->status)->toBe('posted')->and((string) DB::table('stock_bin_levels')->where('bin_id', $a1->id)->value('on_hand'))->toBe('240.000');
    $this->get(GoodsReceiptResource::getUrl('view', ['record' => $grn]))->assertOk()->assertSee('DN-1')->assertSee('A-01')->assertSee('Posted');
    $this->get(BinResource::getUrl())->assertOk()->assertSee('A-01')->assertSee('240 MILO 400G');
});

it('creates, dispatches and receives a transfer from the screens', function () {
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->ctn, '3', 'opening', '');
    $this->actingAs(withMfa($this->owner));

    Livewire::test(CreateStockTransfer::class)->fillForm(['from_warehouse_id' => $this->hq->id, 'to_warehouse_id' => $this->ba->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '2']]])->call('create')->assertHasNoFormErrors()->assertNotified();
    $t = StockTransfer::sole();
    Livewire::test(ViewStockTransfer::class, ['record' => $t->id])->callAction('dispatch')->assertNotified('Dispatched');
    $this->get(StockTransferResource::getUrl())->assertOk()->assertSee('In transit');
    Livewire::test(ViewStockTransfer::class, ['record' => $t->id])
        ->callAction('receive', ['lines' => [['line_id' => $t->lines[0]->id, 'qty' => '1.5']]])->assertNotified('Received');

    expect($t->fresh()->status)->toBe('received')
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->ba->id)->value('on_hand'))->toBe('36.000')->and($t->fresh()->has_discrepancy)->toBeTrue();
});

it('runs a blind count from the screens: counters do not see expected figures, a second person posts', function () {
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->ctn, '1', 'opening', '');
    $keeper = withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($keeper);

    Livewire::test(ListStockCounts::class)->callAction('start', ['warehouse_id' => $this->hq->id, 'blind' => true]);
    $count = StockCount::sole();
    $this->get(StockCountResource::getUrl('view', ['record' => $count]))->assertOk()->assertSee('MILO 400G')->assertDontSee('Expected / difference');
    Livewire::test(ViewStockCount::class, ['record' => $count->id])
        ->callAction('count', ['items' => [['product_uom_id' => $this->milo->units->firstWhere('is_base', true)->id, 'qty' => '22']]])->assertNotified('1 count(s) saved');
    Livewire::test(ViewStockCount::class, ['record' => $count->id])->callAction('submit')->assertNotified('Submitted');

    $this->flushSession();
    $this->actingAs($this->manager);
    $html = $this->get(StockCountResource::getUrl('view', ['record' => $count]))->getContent();
    expect(strip_tags($html))->toContain('24 / -2');
    Livewire::test(ViewStockCount::class, ['record' => $count->id])->callAction('post')->assertNotified('Posted: 1 difference(s)');
    expect((string) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->value('on_hand'))->toBe('22.000');
});

it('shows what to order and creates draft orders per supplier', function () {
    app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'NESTLÉ GHANA',
        'products' => [['product_uom_id' => $this->ctn->id, 'last_cost' => '240', 'is_preferred' => true]]]);
    app(ReorderService::class)->saveRule(actorFor($this->manager), $this->hq, $this->milo, '24', '96');
    $this->actingAs($this->manager);

    $this->get(ReorderRuleResource::getUrl())->assertOk()->assertSee('MILO 400G');
    Livewire::test(ReorderSuggestions::class)->assertSee('NESTLÉ GHANA')->assertSee('4 CTN')
        ->callTableBulkAction('order', [$this->milo->id])->assertNotified('1 draft purchase order(s) created');
    expect(PurchaseOrder::sole()->status)->toBe('draft');
});

it('shows a long What to order list a page at a time, keeping ticks from earlier pages', function () {
    $extra = collect(range(1, 30))->map(fn ($i) => makeProduct($this->owner, sprintf('ITEM%02d', $i), "Item {$i}", retail: '5.00'));
    app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'NESTLÉ GHANA',
        'products' => [['product_uom_id' => $this->ctn->id, 'last_cost' => '240', 'is_preferred' => true],
            ['product_uom_id' => $extra->last()->units->first()->id, 'last_cost' => '3', 'is_preferred' => true]]]);
    app(ReorderService::class)->saveRule(actorFor($this->manager), $this->hq, $this->milo, '24', '96');
    foreach ($extra as $p) {
        app(ReorderService::class)->saveRule(actorFor($this->manager), $this->hq, $p, '5', '10');
    }
    $this->actingAs($this->manager);

    $page = Livewire::test(ReorderSuggestions::class);
    expect($page->instance()->getTableRecords())->toHaveCount(25); // 31 lines: 25 now, the rest on page 2
    $last = $extra->last();
    $page->call('gotoPage', 2, 'page');
    expect($page->instance()->getTableRecords()->keys())->toContain($last->id);
    // Milo ticked on page 1 and the last item on page 2: both are ordered.
    $page->callTableBulkAction('order', [$this->milo->id, $last->id])->assertNotified('1 draft purchase order(s) created');
    expect(PurchaseOrder::sole()->lines)->toHaveCount(2); // one supplier, both lines
});

it('hides purchasing from people without access', function () {
    $cashier = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($cashier);

    $this->get(PurchaseOrderResource::getUrl())->assertForbidden();
    $this->get(StockCountResource::getUrl())->assertForbidden();
    $this->get(ReorderSuggestions::getUrl())->assertForbidden();
});

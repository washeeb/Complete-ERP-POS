<?php

use App\Domain\Access\AccessService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Support\AccessDenied;
use App\Filament\Widgets\Panels\CashPanel;
use App\Filament\Widgets\Panels\HrPanel;
use App\Filament\Widgets\Panels\MyApprovals;
use App\Filament\Widgets\Panels\PaymentMixChart;
use App\Filament\Widgets\Panels\PharmacyPanel;
use App\Filament\Widgets\Panels\PurchasingPanel;
use App\Filament\Widgets\Panels\ReceivablesAgingChart;
use App\Filament\Widgets\Panels\SalesByBranchChart;
use App\Filament\Widgets\Panels\SalesTrendChart;
use App\Filament\Widgets\Panels\Shortcuts;
use App\Filament\Widgets\Panels\SpendByMonthChart;
use App\Filament\Widgets\Panels\StockWatch;
use App\Filament\Widgets\Panels\TopProductsTable;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Dashboard\Panels;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('names only roles that exist in the role defaults', function () {
    expect(Panels::unknownRoles())->toBe([]);
});

it('gives the owner the full dashboard, with every panel drawing from real data', function () {
    foreach (['erp:demo-catalog', 'erp:demo-purchasing', 'erp:demo-sales'] as $cmd) {
        expect(Artisan::call($cmd, ['--as' => $this->owner->email]))->toBe(0, Artisan::output());
    }
    $this->actingAs(withMfa($this->owner))->get('/admin')->assertOk()->assertSee('Arrange'); // the panels load just after the page
    expect(array_column(Panels::layoutFor($this->owner), 'key'))->toContain('figures', 'approvals', 'sales_trend', 'purchasing', 'stock_watch', 'shortcuts');

    foreach ([SalesTrendChart::class, PaymentMixChart::class, SalesByBranchChart::class, TopProductsTable::class, StockWatch::class,
        PurchasingPanel::class, SpendByMonthChart::class, ReceivablesAgingChart::class, CashPanel::class, HrPanel::class, MyApprovals::class, Shortcuts::class] as $panel) {
        Livewire::test($panel)->assertOk();
    }
    Livewire::test(StockWatch::class)->assertSee('Below minimum')->assertSee('Stock value');
    Livewire::test(PurchasingPanel::class)->assertSee('Waiting for approval')->assertSee('Open orders');
});

it('draws the sales panels from the till\'s sales', function () {
    // Two till sales today, through the till's own path.
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), Warehouse::find($till->warehouse_id), $milo, $milo->units->first(), '10', 'opening', '');
    $teller = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $teller, '482613');
    $t = fakeTill($this, $till, 'esi', '482613');
    $at = fn ($m) => now()->subMinutes($m)->toIso8601String();
    $t->sync([$t->openShift(20000, $at(30)), $t->sale([[$milo, '2', null]], $at(20)), $t->sale([[$milo, '1', null]], $at(10))])->assertOk();
    expect(DB::table('sales')->where('status', 'posted')->count())->toBe(2);

    $this->actingAs(withMfa($this->owner->fresh()), 'web');
    Livewire::test(TopProductsTable::class)->assertSee('MILO 400G')->assertSee('174.00'); // 3 × 58.00
    Livewire::test(PaymentMixChart::class)->assertOk();
    Livewire::test(SalesTrendChart::class)->assertOk();
    Livewire::test(CashPanel::class)->assertSee('Tills open now');
});

it('shows a cashier only the panels a cashier may see, even if they save more', function () {
    $cashier = staff($this->owner, 'efua', 3, 'cashier');
    $this->actingAs(withMfa($cashier)); // panels are checked for the person signed in
    expect(array_column(Panels::layoutFor($cashier), 'key'))->toBe(['cash', 'shortcuts']);

    app(AccessService::class)->updateOwnDashboard(actorFor($cashier), $cashier, [['key' => 'purchasing'], ['key' => 'nonsense'], ['key' => 'shortcuts', 'size' => 'half'], ['key' => 'shortcuts']]);
    expect($cashier->fresh()->dashboard)->toBe([['key' => 'purchasing', 'size' => 'full'], ['key' => 'shortcuts', 'size' => 'half']])
        ->and(array_column(Panels::layoutFor($cashier->fresh()), 'key'))->toBe(['shortcuts']); // buying is not for a cashier
    expect(DB::table('audit_events')->where('action', 'user.dashboard_changed')->exists())->toBeTrue();

    app(AccessService::class)->updateOwnDashboard(actorFor($cashier), $cashier->fresh(), null); // back to the role's
    expect($cashier->fresh()->dashboard)->toBeNull();
    expect(fn () => app(AccessService::class)->updateOwnDashboard(actorFor($this->owner), $cashier->fresh(), []))->toThrow(AccessDenied::class);
});

it('lists what waits for the person, never their own requests', function () {
    Artisan::call('erp:demo-catalog', ['--as' => $this->owner->email]);
    Artisan::call('erp:demo-purchasing', ['--as' => $this->owner->email]);
    $hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $supplier = Supplier::first();
    $unit = SupplierProduct::where('supplier_id', $supplier->id)->value('product_uom_id');
    $buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $orders = app(PurchaseOrderService::class);
    $po = $orders->saveDraft(actorFor($buyer), new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $hq->id, 'lines' => [['product_uom_id' => $unit, 'qty' => '5', 'unit_cost' => '10.00']]]);
    $orders->submit(actorFor($buyer), $po);

    $this->actingAs(withMfa($this->owner));
    $items = collect((new MyApprovals)->items())->keyBy('label');
    expect($items['Purchase orders']['count'])->toBe(1);

    $approver = withMfa(staff($this->owner, 'kofi', 4, 'purchase_approver'));
    $this->actingAs($approver);
    expect(collect((new MyApprovals)->items())->pluck('label')->all())->toBe(['Purchase orders']); // only kinds they may approve
});

it('shows the pharmacy panel to people who dispense', function () {
    $pharmacist = withMfa(staff($this->owner, 'abena', 4, 'pharmacist', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($pharmacist);
    expect(PharmacyPanel::canView())->toBeTrue();
    Livewire::test(PharmacyPanel::class)->assertOk()->assertSee('Prescriptions today');
    expect(array_column(Panels::layoutFor($pharmacist), 'key'))->toContain('pharmacy');
});

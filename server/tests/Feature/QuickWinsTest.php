<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Progress;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
});

it('lets a branch purchasing officer add suppliers, numbered by the system, names in capitals', function () {
    $buyer = staff($this->owner, 'kofi', 2, 'purchasing_officer', ScopeType::Branch, $this->branches['HQ']->id);
    $s = app(SupplierService::class)->save(actorFor($buyer), new Supplier, ['name' => 'ernest  chemists ltd', 'contact_name' => 'ama owusu']);
    expect($s->code)->toMatch('/^SUP-[A-Z0-9]+-000001$/')->and($s->name)->toBe('ERNEST CHEMISTS LTD')->and($s->contact_name)->toBe('AMA OWUSU');
    $typed = app(SupplierService::class)->save(actorFor($buyer), new Supplier, ['code' => 'kama', 'name' => 'Kama Industries', 'email' => 'Sales@Kama.com']);
    expect($typed->code)->toBe('KAMA')->and($typed->email)->toBe('Sales@Kama.com'); // e-mail never changed

    $p = makeProduct($this->owner, 'PARA', 'paracetamol 500mg tab', retail: '5.00');
    $c = app(CustomerService::class)->save($this->a, new Customer, ['name' => 'kofi mensah', 'address' => 'Spintex Rd, Accra']);
    expect($p->fresh()->name)->toBe('PARACETAMOL 500MG TAB')->and($c->name)->toBe('KOFI MENSAH')->and($c->address)->toBe('Spintex Rd, Accra');
});

it('opens the document again after posting, so only the buttons that still apply show', function () {
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00');
    app(InventoryService::class)->adjust($this->a, $this->hq, $milo, $milo->units->first(), '10', 'opening', '');
    $adj = app(StockAdjustmentService::class)->create($this->a, $this->hq, ['reason_code' => 'correction']);
    app(StockAdjustmentService::class)->addLine($this->a, $adj, ['product_id' => $milo->id, 'product_uom_id' => $milo->units->first()->id, 'mode' => 'remove', 'qty' => '1']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(ViewStockAdjustment::class, ['record' => $adj->id])->callAction('post')
        ->assertRedirect(StockAdjustmentResource::getUrl('view', ['record' => $adj->id]));
    expect($adj->fresh()->status)->toBe('posted');
    Livewire::test(ViewStockAdjustment::class, ['record' => $adj->id])->assertActionHidden('post')->assertActionHidden('cancel');
});

it('reports how far long work has got, and nothing once it stopped', function () {
    $this->actingAs($this->owner);
    Progress::start('Checking products.csv', 2000);
    Progress::tick(150);
    $this->getJson(route('progress.status'))->assertOk()->assertJson(['label' => 'Checking products.csv', 'total' => 2000]);
    $this->travel(30)->seconds();
    expect($this->getJson(route('progress.status'))->assertOk()->json())->toBeEmpty();
    Progress::finish();
    expect(Progress::current())->toBeNull();

    // Every back-office page carries the progress card and keeps the current menu section at the top.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner))->get('/admin')->assertOk()->assertSee('id="ses-progress"', false)->assertSee('focusGroup', false);
});

it('prints purchase orders and stock documents for the print preview', function () {
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00');
    $sup = app(SupplierService::class)->save($this->a, new Supplier, ['name' => 'Nestle Ghana']);
    $po = app(PurchaseOrderService::class)->saveDraft($this->a, new PurchaseOrder, ['supplier_id' => $sup->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $milo->units->first()->id, 'qty' => '12', 'unit_cost' => '10.00']]]);
    $this->actingAs(withMfa($this->owner));
    $this->get(route('print.purchase-order', $po))->assertOk()->assertSee('PURCHASE ORDER')->assertSee($po->number)->assertSee('NESTLE GHANA')->assertSee('not yet an order');
});

it('tells the till the expiry of the batch it sells next', function () {
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $med = makeProduct($this->owner, 'AMOX', 'Amoxicillin 500mg', retail: '30.00');
    app(CatalogService::class)->saveProduct($this->a, $med, ['track_batches' => true]);
    $med = $med->fresh(['units']);
    $u = $med->units->first();
    app(InventoryService::class)->adjust($this->a, $this->hq, $med, $u, '5', 'opening', '', null, '20.00', 'B2', '2027-09-30');
    app(InventoryService::class)->adjust($this->a, $this->hq, $med, $u, '3', 'opening', '', null, '20.00', 'B1', '2027-03-31');
    [$device, $code] = app(DeviceService::class)->register($this->a, ['branch_id' => $this->branches['HQ']->id, 'till_id' => $till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin($this->a, $cashier, '4826');
    $token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $products = collect($this->withToken($token)->getJson('/api/v1/pos/catalog')->assertOk()->json('products'));
    expect($products->firstWhere('id', $med->id)['expires_on'])->toBe('2027-03-31');
});

it('puts names already in the system in capitals, once, touching only rows that change', function () {
    $p = makeProduct($this->owner, 'LOW', 'X', retail: '1.00');
    DB::table('products')->where('id', $p->id)->update(['name' => 'ibuprofen 400mg tab', 'generic_name' => 'ibuprofen', 'updated_at' => now()->subDay()]);
    $sup = app(SupplierService::class)->save($this->a, new Supplier, ['name' => 'X']);
    DB::table('suppliers')->where('id', $sup->id)->update(['name' => 'ernest chemists', 'contact_name' => 'ama']);
    $already = makeProduct($this->owner, 'UP', 'ALREADY UPPER', retail: '1.00');
    $before = DB::table('products')->where('id', $already->id)->value('updated_at');

    (require database_path('migrations/2026_11_23_100001_names_in_capitals.php'))->up();

    expect(DB::table('products')->where('id', $p->id)->value('name'))->toBe('IBUPROFEN 400MG TAB')
        ->and(DB::table('products')->where('id', $p->id)->value('generic_name'))->toBe('IBUPROFEN')
        ->and(DB::table('suppliers')->where('id', $sup->id)->value('name'))->toBe('ERNEST CHEMISTS')
        ->and((string) DB::table('products')->where('id', $already->id)->value('updated_at'))->toBe((string) $before); // unchanged rows untouched
});

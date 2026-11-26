<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\SupplierService;
use App\Models\Alteration;
use App\Models\ConsignmentSale;
use App\Models\Product;
use App\Models\RepairJob;
use App\Models\SerialNumber;
use App\Models\Style;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\TaxGroup;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Str;

// Fashion and Electronics in the new back office (part 36): styles, alterations, sell-through & consignment; repairs and
// serial numbers.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
});

it('keeps styles: new, change, create sizes and colours, one price for all, archive; and refuses who may not', function () {
    enableModule($this->owner, $this->hq, 'fashion');
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/fashion/styles/form')->assertOk()->json();
    $std = TaxGroup::where('code', 'STD')->value('id');
    expect($form['values']['tax_group_id'])->toBe($std)->and($form['values']['is_active'])->toBeTrue();
    $this->postJson('/office-api/fashion/styles', ['name' => 'Kente wrap dress', 'tax_group_id' => $std, 'sizes' => [], 'colours' => ['Black']])->assertStatus(422);
    $id = $this->postJson('/office-api/fashion/styles', ['name' => 'Kente wrap dress', 'tax_group_id' => $std, 'season' => 'ss26', 'collection' => 'Heritage', 'price' => '450',
        'sizes' => ['S', 'M'], 'colours' => ['Black', 'Gold'], 'is_active' => true])->assertOk()->json('id');
    $style = Style::find($id);
    expect($style->season)->toBe('SS26')->and($style->price_minor)->toBe(45000);

    $one = $this->getJson("/office-api/fashion/styles/{$id}")->assertOk()->json();
    expect($one)->toMatchArray(['name' => 'Kente wrap dress', 'missing' => 4, 'sizes' => ['S', 'M'], 'colours' => ['Black', 'Gold']])
        ->and($one['actions'])->toMatchArray(['edit' => true, 'generate' => true, 'price' => true]);
    expect($this->postJson("/office-api/fashion/styles/{$id}/action", ['action' => 'generate'])->assertOk()->json('message'))->toStartWith('4 new product(s)');
    $one = $this->getJson("/office-api/fashion/styles/{$id}")->assertOk()->json();
    expect($one['missing'])->toBe(0)->and($one['cells']['Gold']['M']['on_hand'])->toBe('0.000');
    $this->postJson("/office-api/fashion/styles/{$id}/action", ['action' => 'price', 'price' => '500'])->assertOk();

    // Sizes that already have products stay; a new size is added.
    $this->postJson("/office-api/fashion/styles/{$id}", ['name' => 'Kente wrap dress', 'tax_group_id' => $std, 'sizes' => ['S'], 'colours' => ['Black', 'Gold']])->assertStatus(422);
    $this->postJson("/office-api/fashion/styles/{$id}", ['name' => 'Kente wrap', 'tax_group_id' => $std, 'price' => '500', 'sizes' => ['S', 'M', 'L'], 'colours' => ['Black', 'Gold']])->assertOk();
    expect($this->getJson("/office-api/fashion/styles/{$id}/form")->json('values'))->toMatchArray(['name' => 'Kente wrap', 'sizes' => ['S', 'M', 'L'], 'price' => '500.00']);
    $list = $this->getJson('/office-api/fashion/styles?season=SS26')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['products' => 4, 'price' => 50000])->and($list['seasons'][0]['id'])->toBe('SS26');

    $this->postJson("/office-api/fashion/styles/{$id}/archive", ['reason' => 'Season over'])->assertOk();
    expect($this->getJson('/office-api/fashion/styles')->json('total'))->toBe(0)->and($this->getJson('/office-api/fashion/styles?show=archived')->json('total'))->toBe(1);

    $cashier = staff($this->owner, 'esi', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    $this->actingAs($cashier, 'web');
    $this->postJson('/office-api/fashion/styles', ['name' => 'X', 'tax_group_id' => $std, 'sizes' => ['S'], 'colours' => ['Red']])->assertForbidden();
    $this->getJson('/office-api/fashion/alterations')->assertForbidden();
});

it('takes alterations in, moves them along and hands a free one over; settles what a consignor is owed', function () {
    enableModule($this->owner, $this->hq, 'fashion');
    $this->actingAs($this->owner, 'web');
    $new = fn (array $x = []) => $this->postJson('/office-api/fashion/alterations', $x + ['branch_id' => $this->hq->id, 'customer_name' => 'Akua Mensah', 'phone' => '024 111 2222',
        'garment' => 'Kaba and slit', 'work' => 'Take in the waist', 'charge' => '0', 'due_on' => now()->addDays(3)->toDateString()]);
    $new(['charge' => '40'])->assertStatus(422)->assertJsonPath('detail', 'Set up the alteration product first (Settings → Fashion).');
    $id = $new()->assertOk()->json('id');
    $list = $this->getJson('/office-api/fashion/alterations')->assertOk()->json();
    expect($list['rows'][0])->toMatchArray(['customer' => 'Akua Mensah', 'phone' => '0241112222', 'status' => 'received', 'charge' => 0, 'can_hand_over' => false])
        ->and(collect($list['rows'][0]['next'])->pluck('id')->all())->toBe(['in_progress', 'ready', 'cancelled']);
    $act = fn (array $b) => $this->postJson("/office-api/fashion/alterations/{$id}/action", $b);
    $act(['action' => 'hand_over'])->assertStatus(422);
    $act(['action' => 'progress', 'status' => 'collected'])->assertStatus(422);
    $act(['action' => 'progress', 'status' => 'in_progress', 'tailor' => 'Abla'])->assertOk();
    $act(['action' => 'progress', 'status' => 'ready'])->assertOk();
    expect($this->getJson('/office-api/fashion/alterations?status=ready')->json('rows.0.can_hand_over'))->toBeTrue();
    $act(['action' => 'hand_over'])->assertOk();
    expect(Alteration::find($id)->only(['status', 'tailor_name']))->toBe(['status' => 'collected', 'tailor_name' => 'Abla']);

    // Consignment: what Adjoa is owed for items sold, and settling it as a supplier bill.
    $consignor = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'ADJOA', 'name' => 'Adjoa Designs']);
    $bag = makeProduct($this->owner, 'BAG', 'Beaded bag', retail: '100.00');
    ConsignmentSale::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'branch_id' => $this->hq->id, 'sale_id' => (string) Str::uuid7(),
        'sale_line_id' => (string) Str::uuid7(), 'product_id' => $bag->id, 'consignor_id' => $consignor->id, 'qty' => '2', 'net_minor' => 20000, 'share_minor' => 12000,
        'occurred_at' => now()]);
    $rep = $this->getJson('/office-api/fashion/report')->assertOk()->json();
    expect($rep)->toMatchArray(['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'consignment' => true, 'can_settle' => true])
        ->and($rep['owed'])->toHaveCount(1)->and($rep['owed'][0])->toMatchArray(['name' => 'ADJOA DESIGNS', 'branch' => 'HQ', 'qty' => '2', 'sold' => 20000, 'owed' => 12000]);
    $open = $this->postJson('/office-api/fashion/report/settle', ['consignor_id' => $consignor->id, 'branch_id' => $this->hq->id, 'to' => now()->toDateString(),
        'statement_no' => 'ADJOA-NOV'])->assertOk()->json('open');
    $bill = SupplierBill::where('supplier_id', $consignor->id)->sole();
    expect($open)->toBe("/office/supplier-bills/{$bill->id}")->and($bill->supplier_invoice_no)->toBe('ADJOA-NOV')
        ->and($this->getJson('/office-api/fashion/report')->json('owed'))->toBe([]);
    $this->postJson('/office-api/fashion/report/settle', ['consignor_id' => $consignor->id, 'branch_id' => $this->hq->id, 'to' => now()->toDateString()])->assertStatus(422);
});

it('opens a repair job, moves it along with parts and labour, sends it to the supplier and hands it over', function () {
    enableModule($this->owner, $this->hq, 'electronics');
    $this->actingAs($this->owner, 'web');
    $o = actorFor($this->owner);
    $screen = makeProduct($this->owner, 'SCR', 'Replacement screen', retail: '200.00');
    app(InventoryService::class)->adjust($o, $this->wh, $screen, $screen->units->first(), '10', 'opening', '', null, '120.00');
    $supplier = app(SupplierService::class)->save($o, new Supplier, ['code' => 'TEC', 'name' => 'Tecno Ghana']);

    $this->postJson('/office-api/electronics/repairs', ['branch_id' => $this->hq->id, 'customer_name' => 'Kwame', 'fault' => 'Cracked screen'])->assertStatus(422); // no device
    $id = $this->postJson('/office-api/electronics/repairs', ['branch_id' => $this->hq->id, 'customer_name' => 'Kwame', 'phone' => '0201234567', 'device' => 'Tecno Spark 20',
        'accessories' => 'charger', 'fault' => 'Cracked screen', 'promised_on' => now()->addDay()->toDateString()])->assertOk()->json('id');
    $job = $this->getJson("/office-api/electronics/repairs/{$id}")->assertOk()->json();
    expect($job)->toMatchArray(['status' => 'received', 'device' => 'Tecno Spark 20', 'accessories' => 'charger', 'warranty' => false, 'charge' => 0])
        ->and($job['actions'])->toMatchArray(['progress' => true, 'lines' => true, 'hand_over' => false])->and($job['log'])->toHaveCount(1);
    $act = fn (array $b) => $this->postJson("/office-api/electronics/repairs/{$id}/action", $b);
    $act(['action' => 'lines', 'lines' => [['product_uom_id' => $screen->units->first()->id, 'qty' => '1']]])->assertOk();
    expect($this->getJson("/office-api/electronics/repairs/{$id}")->json())->toMatchArray(['charge' => 20000])
        ->and($this->getJson("/office-api/electronics/repairs/{$id}")->json('line_values.0'))->toMatchArray(['qty' => '1', 'unit' => 'SCR · REPLACEMENT SCREEN — PC']);
    $act(['action' => 'progress', 'status' => 'at_supplier'])->assertStatus(422); // which supplier
    $act(['action' => 'progress', 'status' => 'at_supplier', 'supplier_id' => $supplier->id, 'rma_reference' => 'RMA-9', 'note' => 'Board fault'])->assertOk();
    $act(['action' => 'progress', 'status' => 'ready'])->assertOk();
    $act(['action' => 'hand_over', 'warehouse_id' => $this->wh->id])->assertStatus(422); // charges: paid at the till
    $act(['action' => 'lines', 'lines' => []])->assertOk();
    $job = $this->getJson("/office-api/electronics/repairs/{$id}")->json();
    expect($job)->toMatchArray(['supplier' => 'TECNO GHANA', 'rma' => 'RMA-9', 'charge' => 0])->and($job['actions']['hand_over'])->toBeTrue()
        ->and($job['log'][0]['status'])->toBe('Ready to collect')->and($job['log'][1]['note'])->toBe('Board fault');
    $act(['action' => 'hand_over', 'warehouse_id' => $this->wh->id])->assertOk();
    expect(RepairJob::find($id)->status)->toBe('collected')
        ->and($this->getJson('/office-api/electronics/repairs?status=collected')->json('rows.0'))->toMatchArray(['customer' => 'Kwame', 'tone' => 'subtle'])
        ->and($this->getJson('/office-api/electronics/suppliers?q=tecno')->json('results.0.label'))->toBe('TECNO GHANA');
});

it('lists serial numbers with their history and registers numbers for stock already on hand', function () {
    enableModule($this->owner, $this->hq, 'electronics');
    $this->actingAs($this->owner, 'web');
    $o = actorFor($this->owner);
    $phone = makeProduct($this->owner, 'TECNO', 'Tecno Spark 20', retail: '1500.00');
    app(InventoryService::class)->adjust($o, $this->wh, $phone, $phone->units->first(), '2', 'opening', '', null, '1000.00');
    app(CatalogService::class)->saveProduct($o, $phone, ['track_serials' => true, 'warranty_months' => 12]);

    $list = $this->getJson('/office-api/electronics/serials')->assertOk()->json();
    expect($list)->toMatchArray(['total' => 0, 'can_register' => true])->and($list['gaps'])->toBeGreaterThan(0);
    expect($this->getJson('/office-api/electronics/serial-products?q=spark')->json('results.0.id'))->toBe($phone->id);
    $reg = fn (array $s) => $this->postJson('/office-api/electronics/serials/register', ['warehouse_id' => $this->wh->id, 'product_id' => $phone->id, 'serials' => $s]);
    $reg(['111', '222', '333'])->assertStatus(422); // only 2 on hand
    expect($reg(['356938035643809', '356938035643810'])->assertOk()->json('message'))->toBe('2 serial number(s) registered.');
    $rows = $this->getJson('/office-api/electronics/serials?status=in_stock&q=3569')->assertOk()->json('rows');
    expect($rows)->toHaveCount(2)->and($rows[0])->toMatchArray(['product' => 'TECNO SPARK 20', 'status' => 'in_stock', 'where' => $this->wh->code]);
    $one = $this->getJson('/office-api/electronics/serials/'.SerialNumber::where('serial', '356938035643809')->value('id'))->assertOk()->json();
    expect($one)->toMatchArray(['serial' => '356938035643809', 'sku' => 'TECNO', 'status_label' => 'In stock', 'receipt' => null, 'history' => []]); // registering moves no stock

    $cashier = staff($this->owner, 'esi', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    $this->actingAs($cashier, 'web');
    $this->postJson('/office-api/electronics/serials/register', ['warehouse_id' => $this->wh->id, 'product_id' => $phone->id, 'serials' => ['9']])->assertForbidden();
    $this->getJson('/office-api/electronics/repairs')->assertForbidden();
    expect(Product::find($phone->id)->track_serials)->toBeTrue();
});

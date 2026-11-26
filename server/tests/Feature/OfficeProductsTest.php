<?php

use App\Domain\Inventory\InventoryService;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Product;
use App\Models\Till;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Products in the new back office (release 3): the current screen's search, filters and columns, a product on its own
// page, and the actions on ticked products, through the same services and permission checks.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::find(Till::where('branch_id', $this->branches['HQ']->id)->value('warehouse_id'));
    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00', carton: ['24', '1000.00'], baseBarcode: '6001067000011');
    $this->sugar = makeProduct($this->owner, 'SUGAR-1KG', 'Sugar 1kg', retail: '18.50');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->milo->units->firstWhere('is_base', true), '40', 'opening', '');
    $this->owner = withMfa($this->owner->fresh());
});

it('lists products with the default price and the stock on hand; finds one by name or a scanned barcode', function () {
    $this->actingAs($this->owner, 'web');
    $r = $this->getJson('/office-api/products')->assertOk()->json();
    expect($r['total'])->toBe(2)->and($r['active'])->toBe(2)->and($r['out'])->toBe(1)->and($r['stock_shown'])->toBeTrue();
    $milo = collect($r['rows'])->firstWhere('sku', 'MILO-400');
    expect($milo['price'])->toBe(4500)->and($milo['on_hand'])->toBe('40')->and($milo['unit'])->toBe('PC');

    expect($this->getJson('/office-api/products?q=sugar')->json('rows.0.sku'))->toBe('SUGAR-1KG')
        ->and($this->getJson('/office-api/products?q=6001067000011')->json('rows.0.sku'))->toBe('MILO-400')
        ->and($this->getJson('/office-api/products?stock=out')->json('rows.0.sku'))->toBe('SUGAR-1KG')
        ->and($this->getJson('/office-api/products?sort=sku&dir=desc')->json('rows.0.sku'))->toBe('SUGAR-1KG');
});

it('offers the filters, the forms of the actions, and the pages they lead to', function () {
    $this->actingAs($this->owner, 'web');
    $r = $this->getJson('/office-api/products/options')->assertOk()->json();
    expect($r['can']['manage'])->toBeTrue()->and($r['links']['create'])->toBe('/office/products/new')
        ->and($r['links']['import'])->toStartWith('/office/import')->and($r['labels']['kinds'])->not->toBeEmpty()
        ->and($r['batches_off'])->toBe(2);
});

it('shows one product: details, units and barcodes, prices, stock where it is, and its latest movements', function () {
    $this->actingAs($this->owner, 'web');
    $r = $this->getJson("/office-api/products/{$this->milo->id}")->assertOk()->json();
    expect($r['facts']['SKU'])->toBe('MILO-400')
        ->and($r['units'])->toHaveCount(2)->and($r['units'][0]['base'])->toBeTrue()->and($r['units'][0]['barcodes'])->toBe(['6001067000011'])
        ->and($r['units'][1]['factor'])->toBe('24')
        ->and(collect($r['prices'])->pluck('amount')->all())->toContain(4500, 100000)
        ->and($r['stock_total'])->toBe('40')->and(collect($r['stock'])->firstWhere('on_hand', '40')['where'])->toStartWith('HQ')
        ->and($r['movements'][0]['qty'])->toBe('40')->and($r['movements'][0]['in'])->toBeTrue()
        ->and($r['pricing_note'])->toBeString()->and($r['actions']['edit'])->toEndWith('/edit');
});

it('shows stock only for the branches whose stock the person may see', function () {
    $auditor = withMfa(staff($this->owner, 'ama', 1, 'auditor', ScopeType::Branch, $this->branches['BA']->id));
    $this->actingAs($auditor, 'web');
    $milo = collect($this->getJson('/office-api/products')->assertOk()->json('rows'))->firstWhere('sku', 'MILO-400');
    expect($milo['on_hand'])->toBe('0'); // the 40 are at HQ
    $r = $this->getJson("/office-api/products/{$this->milo->id}")->assertOk()->json();
    expect($r['stock_total'])->toBe('0')->and($r['movements'])->toBe([])->and($r['actions']['edit'])->toBeNull()->and($r['pricing_note'])->toBeNull();
    $this->postJson('/office-api/products/bulk', ['action' => 'batches', 'ids' => [$this->milo->id]])->assertForbidden();
});

it('refuses someone who may not see products', function () {
    $teller = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($teller, 'web');
    $this->getJson('/office-api/products')->assertForbidden();
    $this->getJson("/office-api/products/{$this->milo->id}")->assertForbidden();
});

it('moves ticked products to another category, and every product the filters pick', function () {
    $this->actingAs($this->owner, 'web');
    $drinks = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'DRINKS', 'is_active' => true]);
    $this->postJson('/office-api/products/bulk', ['action' => 'move', 'field' => 'category_id', 'to' => $drinks->id, 'ids' => [$this->milo->id]])
        ->assertOk()->assertJson(['done' => 1]);
    expect($this->milo->fresh()->category_id)->toBe($drinks->id)->and($this->sugar->fresh()->category_id)->not->toBe($drinks->id);

    $this->postJson('/office-api/products/bulk', ['action' => 'move', 'field' => 'category_id', 'to' => $drinks->id, 'match' => ['q' => 'sugar']])
        ->assertOk()->assertJson(['done' => 1]);
    expect($this->sugar->fresh()->category_id)->toBe($drinks->id);
    $this->postJson('/office-api/products/bulk', ['action' => 'move', 'field' => 'tax_group_id', 'to' => '', 'ids' => [$this->milo->id]])->assertStatus(422);
});

it('switches on batches, prepares labels, and hands the ticked products to Export prices', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/products/bulk', ['action' => 'batches', 'ids' => [$this->milo->id, $this->sugar->id]])->assertOk()->assertJson(['done' => 2]);
    expect($this->milo->fresh()->track_batches)->toBeTrue();

    $r = $this->postJson('/office-api/products/labels', ['kind' => 'shelf', 'layout' => 'a4_24', 'copies' => 2, 'ids' => [$this->sugar->id]])->assertOk()->json();
    expect($r['url'])->toContain('/labels/')->and($r['count'])->toBe(1);

    $r = $this->postJson('/office-api/products/hand-off', ['to' => 'prices', 'ids' => [$this->milo->id]])->assertOk()->json();
    expect($r['url'])->toBe('/office/price-list?only=1')->and(session('price_list.products'))->toBe([$this->milo->id]);
});

it('changes the unit of a product, and lists the ones that cannot change', function () {
    $this->actingAs($this->owner, 'web');
    $tab = Uom::firstOrCreate(['tenant_id' => $this->tenant->id, 'code' => 'TAB'], ['name' => 'Tablet']);
    $r = $this->postJson('/office-api/products/bulk', ['action' => 'unit', 'mode' => 'relabel', 'uom_id' => $tab->id, 'reason' => 'Wrong label', 'ids' => [$this->sugar->id]])
        ->assertOk()->json();
    expect($r['done'])->toBe(1)->and(Product::find($this->sugar->id)->base_uom_id)->toBe($tab->id);
    $this->postJson('/office-api/products/bulk', ['action' => 'unit', 'mode' => 'convert', 'uom_id' => $tab->id, 'reason' => 'x', 'ids' => [$this->milo->id]])
        ->assertStatus(422); // convert needs "how many"
});

// The product form (release 3, part 2): the current form's fields, saved through the same CatalogService::saveProduct.

it('opens an empty form for a new product, and creates it with its unit, barcode and price', function () {
    $this->actingAs($this->owner, 'web');
    $f = $this->getJson('/office-api/products/form')->assertOk()->json();
    expect($f['id'])->toBeNull()->and($f['values']['track_stock'])->toBeTrue()->and($f['values']['base_uom_id'])->not->toBe('')
        ->and($f['sections']['prices'])->toBeTrue()->and($f['choices']['uoms'])->not->toBeEmpty();

    $ctn = Uom::where('code', 'CTN')->value('id');
    $v = $f['values'];
    $v['name'] = 'Peak milk 400g';
    $v['base_barcodes'] = ['6001067099991'];
    $v['units'] = [['uom_id' => $ctn, 'factor' => '12', 'is_sellable' => true, 'is_purchasable' => true, 'barcodes' => []]];
    $v['prices'][0]['amount'] = '25.50';
    $id = $this->postJson('/office-api/products', ['values' => $v])->assertOk()->json('id');

    $p = Product::with('prices', 'units', 'barcodes')->find($id);
    expect($p->name)->toBe('PEAK MILK 400G')->and($p->sku)->not->toBe('')->and($p->units)->toHaveCount(2)
        ->and($p->prices->first()->amount_minor)->toBe(2550)->and($p->barcodes->pluck('code')->all())->toContain('6001067099991');
});

it('edits a product with a reason, and refuses a rule the service enforces', function () {
    $this->actingAs($this->owner, 'web');
    $f = $this->getJson("/office-api/products/{$this->milo->id}/form")->assertOk()->json();
    expect($f['values']['name'])->toBe('MILO 400G')->and($f['values']['units'][0]['factor'])->toBe('24')->and($f['base_unit_locked'])->toBeTrue()
        ->and(collect($f['values']['prices'])->pluck('amount')->all())->toContain('45.00');

    $v = $f['values'];
    $v['location'] = 'Shelf B2';
    $v['reason'] = 'Moved to aisle B';
    $v['base_uom_id'] = Uom::where('code', 'CTN')->value('id'); // stock has moved: ignored, not an error
    $this->postJson("/office-api/products/{$this->milo->id}", ['values' => $v])->assertOk();
    $p = $this->milo->fresh();
    expect($p->location)->toBe('Shelf B2')->and($p->base_uom_id)->toBe(Uom::where('code', 'PC')->value('id'))
        ->and(AuditEvent::where('subject_id', $p->id)->where('action', 'product.updated')->orderByDesc('seq')->value('reason'))->toBe('Moved to aisle B');

    $v['plu'] = 'abc'; // a scale code is up to 5 digits
    $this->postJson("/office-api/products/{$this->milo->id}", ['values' => $v])->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, 'PLU'));
});

it('adds a missing category from the form, and picks one that already exists', function () {
    $this->actingAs($this->owner, 'web');
    $a = $this->postJson('/office-api/products/quick-add', ['kind' => 'category', 'name' => 'Beverages'])->assertOk()->json();
    $b = $this->postJson('/office-api/products/quick-add', ['kind' => 'category', 'name' => 'beverages'])->assertOk()->json();
    expect($a['existed'])->toBeFalse()->and($b['existed'])->toBeTrue()->and($b['id'])->toBe($a['id']);
    expect($this->getJson('/office-api/products/ingredients?q=milo')->assertOk()->json('results.0.label'))->toContain('MILO');
});

it('keeps the form from someone who may not change products', function () {
    $auditor = withMfa(staff($this->owner, 'ama', 1, 'auditor', ScopeType::Branch, $this->branches['BA']->id));
    $this->actingAs($auditor, 'web');
    $this->getJson('/office-api/products/form')->assertForbidden();
    $this->postJson("/office-api/products/{$this->milo->id}", ['values' => ['name' => 'X']])->assertForbidden();
    $this->postJson('/office-api/products/quick-add', ['kind' => 'brand', 'name' => 'Nestle'])->assertForbidden();
});

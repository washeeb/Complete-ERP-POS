<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Models\Industry;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Security\ScopeType;
use App\Support\IndustryContext;

// The price list (with Export) and Shared products in the new back office (catalog and pricing setup, part 10).

it('lists every unit with its price on the chosen list, narrows it, takes the ticked products, and exports it', function () {
    [, $owner] = installCompany();
    $branches = setUpBranches($owner);
    withMfa($owner);
    $milo = makeProduct($owner, 'MILO', 'Milo 400g', retail: '12.00', carton: ['24', '330.00']);
    $sugar = makeProduct($owner, 'SUGAR', 'Sugar 1kg', retail: '9.50');
    $this->actingAs($owner, 'web');

    $o = $this->getJson('/office-api/price-list/options')->assertOk()->json();
    expect($o['default_list'])->toBe(PriceList::where('code', 'RETAIL')->value('id'))->and($o['may_cost'])->toBeTrue();
    expect(collect($this->getJson('/office-api/price-list/products?q=milo')->json('rows'))->pluck('id')->all())->toBe([$milo->id]);

    $l = $this->getJson('/office-api/price-list?q=milo')->assertOk()->json();
    expect($l['total'])->toBe(2)->and($l['list'])->not->toBeNull()->and(collect($l['rows'])->pluck('gross')->all())->toBe([1200, 33000])
        ->and($l['rows'][1]['unit'])->toContain('× 24');
    expect($this->getJson('/office-api/price-list?'.http_build_query(['product' => [$sugar->id]]))->json('total'))->toBe(1);

    $this->postJson('/office-api/products/hand-off', ['to' => 'prices', 'ids' => [$sugar->id]])->assertOk()->assertJsonPath('url', fn ($u) => str_ends_with($u, '?only=1'));
    $t = $this->getJson('/office-api/price-list?only=1')->json();
    expect($t['total'])->toBe(1)->and($t['ticked'])->toBe(1)->and($t['rows'][0]['product'])->toBe('SUGAR 1KG');
    expect($this->getJson('/office-api/price-list')->json('total'))->toBeGreaterThan(2); // without only=1: all products

    $csv = $this->get('/office-api/export/price-list?q=milo&format=csv')->assertOk()->streamedContent();
    expect($csv)->toContain('Customer pays')->toContain('MILO 400G')->toContain('Cost')->not->toContain('SUGAR 1KG');

    $keeper = staff($owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $branches['HQ']->id); // sees prices, not what they cost
    $this->actingAs($keeper, 'web');
    expect($this->getJson('/office-api/price-list?q=milo')->assertOk()->json('rows.0.cost'))->toBeNull();
    expect($this->get('/office-api/export/price-list?q=milo&format=csv')->assertOk()->streamedContent())->not->toContain('Margin');
});

it('shows shared products with where they are used, moves the ticked ones to their suggestion and all to one industry', function () {
    [$tenant, $owner] = installCompany();
    $b = setUpBranches($owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Grocery Spintex', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
    ]);
    withMfa($owner);
    $pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    $save = fn ($name) => app(CatalogService::class)->saveProduct(actorFor($owner), new Product, ['name' => $name, 'industry_id' => IndustryContext::SHARED, 'base_uom_id' => Uom::where('code', 'PC')->value('id'), 'tax_group_id' => TaxGroup::where('code', 'STD')->value('id'), 'track_stock' => true, 'is_active' => true]);
    $here = $save('Stocked');
    $there = $save('Not anywhere');
    app(InventoryService::class)->adjust(actorFor($owner), $b['P1']->warehouses()->first(), $here, ProductUom::where('product_id', $here->id)->where('is_base', true)->first(),
        '5', 'opening', '', 'test', '1.00');
    $this->actingAs($owner, 'web');

    $l = $this->getJson('/office-api/shared-products')->assertOk()->json();
    $rows = collect($l['rows'])->keyBy('id');
    expect($l['total'])->toBe(2)->and($rows[$here->id])->toMatchArray(['used' => $pharmacy->name, 'suggested' => $pharmacy->name])
        ->and($rows[$there->id]['suggested'])->toBeNull()->and(collect($l['industries'])->pluck('id')->all())->toContain($grocery->id);

    $this->postJson('/office-api/shared-products/move-suggested', ['ids' => [$here->id, $there->id]])->assertOk()
        ->assertJsonPath('message', '1 product(s) moved. 1 with no suggestion stayed.');
    expect($here->fresh()->industry_id)->toBe($pharmacy->id)->and($there->fresh()->industry_id)->toBeNull();

    $this->postJson('/office-api/shared-products/move-all', ['industry' => 'nope'])->assertStatus(422);
    $this->postJson('/office-api/shared-products/move-all', ['industry' => $grocery->id])->assertOk();
    expect($there->fresh()->industry_id)->toBe($grocery->id)->and(Product::where('tenant_id', $tenant->id)->whereNull('industry_id')->count())->toBe(0);

    $this->actingAs(staff($owner, 'stock', 3, 'stock_controller', ScopeType::Branch, $b['G1']->id), 'web');
    $this->getJson('/office-api/shared-products')->assertForbidden();
    $this->postJson('/office-api/shared-products/move-all', ['industry' => $grocery->id])->assertForbidden();
});

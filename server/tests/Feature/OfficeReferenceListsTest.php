<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\TaxGroup;
use App\Models\TaxRate;
use App\Models\Uom;

// Categories, brands, units, tax rates, tax groups and price lists in the new back office (catalog and pricing setup,
// part 7), through CatalogService, as the current screens.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner);
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
});

it('adds, moves products between and deletes categories and brands', function () {
    $this->actingAs($this->owner, 'web');
    $a = $this->postJson('/office-api/lists/categories', ['name' => 'Drinks'])->assertOk()->json('id');
    expect(Category::find($a)->name)->toBe('DRINKS');
    $this->postJson('/office-api/lists/categories', ['name' => 'drinks'])->assertStatus(422); // once
    $b = $this->postJson('/office-api/lists/categories', ['name' => 'Soft drinks', 'parent_id' => $a])->assertOk()->json('id');
    $this->postJson("/office-api/lists/categories/{$a}", ['name' => 'Drinks', 'parent_id' => $a])->assertStatus(422); // not inside itself

    Product::whereKey($this->milo->id)->update(['category_id' => $a]);
    $rows = collect($this->getJson('/office-api/lists/categories?q=drinks')->assertOk()->json('rows'))->keyBy('id');
    expect($rows[$a])->toMatchArray(['products' => 1, 'can_move' => true, 'can_delete' => true])->and($rows[$b]['parent'])->toBe('DRINKS');
    $this->postJson("/office-api/lists/categories/{$a}/delete")->assertStatus(422); // still used
    $this->postJson("/office-api/lists/categories/{$a}/move", ['to' => $b])->assertOk();
    expect($this->milo->fresh()->category_id)->toBe($b);
    $this->postJson("/office-api/lists/categories/{$b}/delete")->assertStatus(422); // MILO now there
    $this->postJson("/office-api/lists/categories/{$b}/move", ['to' => null])->assertOk();
    $this->postJson("/office-api/lists/categories/{$b}/delete")->assertOk();

    $br = $this->postJson('/office-api/lists/brands', ['name' => 'Nestle'])->assertOk()->json('id');
    expect(Brand::find($br)->name)->toBe('NESTLE')->and(collect($this->getJson('/office-api/lists/brands')->json('rows'))->pluck('name'))->toContain('NESTLE');
    $this->postJson("/office-api/lists/brands/{$br}/archive", ['reason' => 'x'])->assertForbidden(); // brands are not archived, as now
});

it('keeps unit codes once, merges a unit, and sets tax rates, tax groups and price lists', function () {
    $this->actingAs($this->owner, 'web');
    $bx = $this->postJson('/office-api/lists/units', ['code' => 'bx', 'name' => 'Box', 'decimals' => 0])->assertOk()->json('id');
    expect(Uom::find($bx)->code)->toBe('BX');
    $this->postJson('/office-api/lists/units', ['code' => 'BX', 'name' => 'Other box', 'decimals' => 0])->assertStatus(422);
    $pc = ProductUom::where('product_id', $this->milo->id)->value('uom_id');
    ProductUom::where('product_id', $this->milo->id)->update(['uom_id' => $bx]);
    $this->postJson("/office-api/lists/units/{$bx}/merge", ['to' => $pc])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, '1 product'));
    expect(Uom::find($bx)->merged_into_id)->toBe($pc);

    $levy = $this->postJson('/office-api/lists/tax-rates', ['code' => 'LEVY2', 'name' => 'Levy 2', 'rate_bp' => 250, 'sort' => 9])->assertOk()->json('id');
    $this->postJson("/office-api/lists/tax-rates/{$levy}", ['code' => 'LEVY3', 'name' => 'Levy 2', 'rate_bp' => 300])->assertStatus(422); // code stays
    $this->postJson("/office-api/lists/tax-rates/{$levy}", ['name' => 'Levy two', 'rate_bp' => 300])->assertOk();
    expect(TaxRate::find($levy))->rate_bp->toBe(300)->code->toBe('LEVY2');

    $g = $this->postJson('/office-api/lists/tax-groups', ['name' => 'Levy only', 'rate_ids' => [$levy]])->assertOk()->json('id');
    $row = collect($this->getJson('/office-api/lists/tax-groups')->json('rows'))->firstWhere('id', $g);
    expect($row)->toMatchArray(['taxes' => 'LEVY2 3.00%', 'verified' => null, 'can_verify' => true]);
    $this->postJson("/office-api/lists/tax-groups/{$g}/verify", ['note' => ''])->assertStatus(422);
    $this->postJson("/office-api/lists/tax-groups/{$g}/verify", ['note' => 'Checked by our accountant'])->assertOk();
    expect(TaxGroup::find($g)->verified_at)->not->toBeNull();

    $pl = $this->postJson('/office-api/lists/price-lists', ['name' => 'Staff price', 'prices_include_tax' => true, 'pos_selectable' => true])->assertOk()->json('id');
    expect(PriceList::find($pl)->code)->toBe('STAFF_PRICE');
    $this->postJson("/office-api/lists/price-lists/{$pl}/archive", ['reason' => 'Not used'])->assertOk();
    expect(collect($this->getJson('/office-api/lists/price-lists')->json('rows'))->pluck('id'))->not->toContain($pl);
    $this->postJson("/office-api/lists/price-lists/{$pl}/restore")->assertOk();

    $clerk = staff($this->owner, 'efua', 2);
    $this->actingAs($clerk, 'web');
    foreach (['tax-rates', 'tax-groups', 'price-lists'] as $kind) {
        $this->getJson("/office-api/lists/{$kind}")->assertForbidden();
    }
    $this->postJson('/office-api/lists/units', ['code' => 'ZZ', 'name' => 'Z', 'decimals' => 0])->assertForbidden();
});

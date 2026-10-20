<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\UnitChangeService;
use App\Domain\Imports\ImportService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Uoms\Pages\ListUoms;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Uom;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->strip = Uom::firstOrCreate(['code' => 'STRIP'], ['name' => 'Strip', 'decimals' => 0]);
    $this->tab = Uom::firstOrCreate(['code' => 'TAB'], ['name' => 'Tablet', 'decimals' => 0]);
    // A medicine counted in strips (batch-tracked), GHS 12.00 a strip, 5 strips in two batches at GHS 8.00 cost.
    $this->med = makeProduct($this->owner, 'PARA', 'Paracetamol 500mg', retail: '12.00');
    app(CatalogService::class)->correctBaseUnit($this->a, $this->med, $this->strip->id, 'Counted in strips');
    app(CatalogService::class)->saveProduct($this->a, $this->med->fresh(), ['track_batches' => true]);
    $this->med = $this->med->fresh(['units']);
    $base = $this->med->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust($this->a, $this->hq, $this->med, $base, '3', 'opening', '', null, '8.00', 'B1', '2027-06-30');
    app(InventoryService::class)->adjust($this->a, $this->hq, $this->med, $base, '2', 'opening', '', null, '8.00', 'B2', '2027-09-30');
    app(ReorderService::class)->saveRule($this->a, $this->hq, $this->med, '2', '6');
});

it('converts strips to tablets: stock × 10 per batch, same value, tablet price ÷ 10, reorder levels × 10, ledger in balance', function () {
    $valueBefore = (float) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->med->id)->selectRaw('on_hand * avg_cost_minor AS v')->value('v');
    app(UnitChangeService::class)->convert($this->a, $this->med, $this->tab->id, '10', 'Sold in single tablets');
    $p = Product::find($this->med->id);
    $level = DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $p->id)->first();

    expect($p->base_uom_id)->toBe($this->tab->id)
        ->and((string) $level->on_hand)->toBe('50.000')
        ->and(round((float) $level->on_hand * (float) $level->avg_cost_minor))->toBe(round($valueBefore))
        ->and(DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->where('l.product_id', $p->id)->orderBy('b.batch_no')->pluck('l.on_hand')->map(fn ($v) => (string) $v)->all())
        ->toBe(['30.000', '20.000'])
        ->and((string) ProductUom::where('product_id', $p->id)->where('uom_id', $this->strip->id)->value('factor'))->toStartWith('10.')
        ->and(ProductUom::where('product_id', $p->id)->where('is_base', true)->value('uom_id'))->toBe($this->tab->id)
        ->and(Price::where('product_uom_id', ProductUom::where('product_id', $p->id)->where('uom_id', $this->tab->id)->value('id'))->value('amount_minor'))->toBe(120)
        ->and(Price::where('product_uom_id', ProductUom::where('product_id', $p->id)->where('uom_id', $this->strip->id)->value('id'))->value('amount_minor'))->toBe(1200)
        ->and((string) DB::table('reorder_rules')->where('product_id', $p->id)->value('min_qty'))->toBe('20.000')
        ->and(app(StockLedger::class)->check($this->tenant->id))->toBe([])
        ->and(DB::table('stock_movements')->where('product_id', $p->id)->where('type', 'unit_conversion')->count())->toBe(4) // out and in, per batch
        ->and(DB::table('audit_events')->where('action', 'product.unit_converted')->count())->toBe(1);
});

it('refuses while the product is on an open document', function () {
    $base = $this->med->units->firstWhere('is_base', true);
    $adj = app(StockAdjustmentService::class)->create($this->a, $this->hq, ['reason_code' => 'correction']);
    app(StockAdjustmentService::class)->addLine($this->a, $adj, ['product_id' => $this->med->id, 'product_uom_id' => $base->id, 'mode' => 'remove', 'qty' => '1']);
    expect(fn () => app(UnitChangeService::class)->convert($this->a, $this->med, $this->tab->id, '10', 'x'))
        ->toThrow(DomainException::class, "stock adjustment {$adj->number}");
});

it('relabels when the label was wrong, removing an unused copy of the new unit first', function () {
    $p = makeProduct($this->owner, 'GAUZE', 'Gauze roll', retail: '5.00');
    app(CatalogService::class)->saveProduct($this->a, $p, ['units' => [['uom_id' => $this->tab->id, 'factor' => '1', 'is_sellable' => true, 'is_purchasable' => true]]]);
    expect(ProductUom::where('product_id', $p->id)->count())->toBe(2);
    app(UnitChangeService::class)->relabel($this->a, $p->fresh(), $this->tab->id, 'Wrong label');
    expect($p->fresh()->base_uom_id)->toBe($this->tab->id)->and(ProductUom::where('product_id', $p->id)->count())->toBe(1);
});

it('changes many products from the list and from a file, each with its own pack size', function () {
    $pack = Uom::firstOrCreate(['code' => 'PK'], ['name' => 'Pack', 'decimals' => 0]);
    $a = makeProduct($this->owner, 'AMOX', 'Amoxicillin 500mg', retail: '30.00');
    $b = makeProduct($this->owner, 'IBU', 'Ibuprofen 400mg', retail: '20.00');
    app(CatalogService::class)->correctBaseUnit($this->a, $a, $pack->id, 'packs');
    app(CatalogService::class)->correctBaseUnit($this->a, $b, $pack->id, 'packs');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(ListProducts::class)->callTableBulkAction('changeUnit', [$a->id], ['mode' => 'convert', 'uom_id' => $this->tab->id, 'per' => '20', 'reason' => 'Singles'])
        ->assertNotified('1 product(s) changed');
    expect($a->fresh()->base_uom_id)->toBe($this->tab->id);

    $path = tempnam(sys_get_temp_dir(), 'uc').'.csv';
    file_put_contents($path, "sku,new_unit,per,mode\nIBU,Tablet,30,convert\n{$this->med->sku},TAB,10,\n");
    $import = app(ImportService::class)->run($this->a, 'unit_changes', $path, 'units.csv', [], null, true);
    expect($import->status)->toBe('imported', json_encode($import->errors))->and($b->fresh()->base_uom_id)->toBe($this->tab->id)->and($this->med->fresh()->base_uom_id)->toBe($this->tab->id)
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->med->id)->value('on_hand'))->toBe('50.000');
});

it('edits a unit code and merges a duplicate unit into the right one', function () {
    $pcs = Uom::firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces', 'decimals' => 0]);
    $p = makeProduct($this->owner, 'SYR', 'Syringe 5ml', retail: '2.00');
    app(CatalogService::class)->correctBaseUnit($this->a, $p, $pcs->id, 'typed PCS');
    app(CatalogService::class)->saveSimple($this->a, $this->strip, ['code' => 'str', 'name' => 'Strip', 'decimals' => 0]);
    expect($this->strip->fresh()->code)->toBe('STR');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    $pc = Uom::where('code', 'PC')->first();
    Livewire::test(ListUoms::class)->callTableAction('merge', $pcs->id, ['to' => $pc->id])->assertNotified('PCS merged: 1 product(s) moved');
    expect($p->fresh()->base_uom_id)->toBe($pc->id)->and($pcs->fresh()->merged_into_id)->toBe($pc->id)
        ->and(App\Domain\Imports\Importer::unitId('pcs'))->toBe($pc->id); // files that still say PCS land on PC
});

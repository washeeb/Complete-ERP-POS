<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Org\OrgService;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Models\Uom;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
});

it('lists a new product on Stock on hand at 0 in every store, before anything moves', function () {
    $p = makeProduct($this->owner, 'GYP', 'Gyprone Plus Tabs', retail: '10.00');
    $stores = Warehouse::where('is_active', true)->count();
    expect(DB::table('stock_levels')->where('product_id', $p->id)->count())->toBe($stores)
        ->and((string) DB::table('stock_levels')->where('product_id', $p->id)->where('warehouse_id', $this->hq->id)->value('on_hand'))->toBe('0.000');

    // Nothing has moved: the base unit can still change, and an opening balance can still go in.
    expect($p->hasMoved())->toBeFalse();
    $pk = Uom::firstOrCreate(['code' => 'PK'], ['name' => 'Pack', 'decimals' => 0]);
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $p, ['base_uom_id' => $pk->id]);
    $p = $p->fresh();
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $p, $p->units->first(), '6', 'opening', '', null, '4.00');
    expect($p->hasMoved())->toBeTrue();

    // A new store gets every product at 0.
    $ws = app(OrgService::class)->saveWarehouse(actorFor($this->owner), new Warehouse, ['branch_id' => $this->branches['HQ']->id, 'code' => 'HQ-2', 'name' => 'Back store']);
    expect(DB::table('stock_levels')->where('warehouse_id', $ws->id)->where('product_id', $p->id)->exists())->toBeTrue();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    $never = makeProduct($this->owner, 'MAX', 'Maxmox /Letap (Amoxyllin) 250mg', retail: '5.00');
    Livewire::test(ListStockLevels::class)->searchTable('MAXMOX')->assertSee('Maxmox /Letap')
        ->filterTable('level', 'out')->assertSee('Maxmox /Letap')->assertDontSee('Gyprone Plus Tabs');
});

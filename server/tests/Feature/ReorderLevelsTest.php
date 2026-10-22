<?php

use App\Domain\Imports\Importers\ProductsUpdateImporter;
use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\ReorderLevels;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Purchasing\SmartReorderLevels;
use App\Domain\Purchasing\SupplierService;
use App\Filament\Pages\SuggestedLevels;
use App\Models\ReorderRule;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Qty;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']); // bought by the carton of 24
    $this->soap = makeProduct($this->owner, 'SOAP', 'Key soap', retail: '5.00'); // one unit only
    $this->levels = app(ReorderLevels::class);
});

it('gives every stock product the default minimum of 5 buying units, ordered up to twice that', function () {
    $l = $this->levels->forWarehouse($this->wh);
    expect($l[$this->milo->id])->toBe(['min' => 120_000, 'max' => 240_000, 'source' => 'default']) // 5 cartons of 24
        ->and($l[$this->soap->id])->toBe(['min' => 5_000, 'max' => 10_000, 'source' => 'default']);

    // The supplier's unit wins over the biggest unit.
    $pc = $this->milo->units->firstWhere('is_base', true);
    app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'Nestlé Ghana',
        'products' => [['product_uom_id' => $pc->id, 'last_cost' => '10', 'is_preferred' => true]]]);
    expect($this->levels->forWarehouse($this->wh)[$this->milo->id]['min'])->toBe(5_000);

    // A new product has it at once; 0 switches the default off.
    $new = makeProduct($this->owner, 'NEW', 'New item', retail: '1.00');
    expect($this->levels->forWarehouse($this->wh))->toHaveKey($new->id);
    app(Settings::class)->set(actorFor($this->owner), 'stock.default_min_qty', 0);
    expect($this->levels->forWarehouse($this->wh))->toBe([]);
});

it('lets a product have its own figure per store, and go back to the default', function () {
    $rule = app(ReorderService::class)->saveRule(actorFor($this->owner), $this->wh, $this->soap, '20', '50');
    expect($this->levels->forWarehouse($this->wh)[$this->soap->id])->toBe(['min' => 20_000, 'max' => 50_000, 'source' => 'own']);
    $other = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    expect($this->levels->forWarehouse($other)[$this->soap->id]['source'])->toBe('default');

    app(ReorderService::class)->useDefault(actorFor($this->owner), $rule);
    expect($this->levels->forWarehouse($this->wh)[$this->soap->id]['source'])->toBe('default')->and(ReorderRule::count())->toBe(1);
});

it('leaves slow movers without a default when asked, and lists low stock for buyers', function () {
    $inv = app(InventoryService::class);
    $inv->adjust(actorFor($this->owner), $this->wh, $this->soap, $this->soap->units->first(), '5', 'opening', '', null, '3.00');
    $inv->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->firstWhere('is_base', true), '500', 'opening', '', null, '10.00');
    expect(array_keys($this->levels->low($this->wh)))->toBe([$this->soap->id]); // 5 is at the minimum

    $suggest = collect(app(ReorderService::class)->suggestions($this->wh))->firstWhere('sku', 'SOAP');
    expect($suggest['min'])->toBe(5_000)->and($suggest['suggest_qty'])->toBe(5_000)->and($suggest['source'])->toBe('default'); // up to 10

    DB::table('products')->where('id', $this->soap->id)->update(['created_at' => now()->subDays(200)]);
    app(Settings::class)->set(actorFor($this->owner), 'stock.default_exclude_slow_movers', true);
    expect($this->levels->forWarehouse($this->wh))->not->toHaveKey($this->soap->id);
    $this->artisan('erp:reorder-levels --alerts')->assertSuccessful();
});

it('suggests smart levels from sales to accept, or applies them itself without touching own figures', function () {
    $forecast = [
        ['product_id' => $this->milo->id, 'avg_daily' => 30.0, 'lead_days' => 7, 'suggested_min' => 250.4, 'suggested_max' => 670.2],
        ['product_id' => $this->soap->id, 'avg_daily' => 2.0, 'lead_days' => 7, 'suggested_min' => 5.0, 'suggested_max' => 33.0], // about the default: not listed
    ];
    $smart = app(SmartReorderLevels::class);
    expect($smart->recalculate($this->wh, $forecast))->toBe(['suggested' => 1, 'applied' => 0]);
    $row = DB::table('reorder_level_suggestions')->sole();
    expect((string) $row->min_qty)->toBe('251.000')->and($this->levels->forWarehouse($this->wh)[$this->milo->id]['source'])->toBe('default');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->owner);
    Livewire::test(SuggestedLevels::class)->assertSee('MILO 400G')->callTableBulkAction('accept', [$row->id])->assertNotified('1 level(s) accepted');
    expect($this->levels->forWarehouse($this->wh)[$this->milo->id])->toBe(['min' => 251_000, 'max' => 671_000, 'source' => 'smart'])
        ->and(DB::table('reorder_level_suggestions')->count())->toBe(0);

    // Automatic: smart and default levels follow the sales; an own figure stays.
    app(ReorderService::class)->saveRule(actorFor($this->owner), $this->wh, $this->soap, '1', '2');
    app(Settings::class)->set(actorFor($this->owner), 'stock.smart_levels', 'auto');
    $forecast[0]['suggested_min'] = 500.0;
    $forecast[0]['suggested_max'] = 900.0;
    $forecast[1]['suggested_min'] = 40.0;
    expect($smart->recalculate($this->wh, $forecast))->toBe(['suggested' => 1, 'applied' => 1]);
    $l = $this->levels->forWarehouse($this->wh);
    expect($l[$this->milo->id]['min'])->toBe(500_000)->and($l[$this->soap->id])->toBe(['min' => 1_000, 'max' => 2_000, 'source' => 'own']);
});

it('reads own minimums from the products file, in the buying unit', function () {
    $importer = app(ProductsUpdateImporter::class);
    $importer->apply(actorFor($this->owner), ['sku' => 'MILO', 'min_qty' => '2', 'max_qty' => ''], [], null);
    $l = $this->levels->forWarehouse($this->wh)[$this->milo->id];
    expect($l)->toBe(['min' => 48_000, 'max' => 96_000, 'source' => 'own']); // 2 cartons, up to 4
    expect(Qty::format($l['min']))->toBe('48.000');
    $importer->apply(actorFor($this->owner), ['sku' => 'MILO', 'min_qty' => 'default'], [], null);
    expect($this->levels->forWarehouse($this->wh)[$this->milo->id]['source'])->toBe('default');
});

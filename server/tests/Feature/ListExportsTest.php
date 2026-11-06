<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Filament\Pages\PriceListExport;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Support\TableExport;
use App\Models\Brand;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->actingAs(withMfa($this->owner), 'web');
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->med = makeProduct($this->owner, 'A1', 'Thing and Co', retail: '12.19', carton: [10, '100.00'])->fresh(['units']);
    $this->base = $this->med->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust($this->a, $this->hq, $this->med, $this->base, '5', 'opening', '', 'x', '2.00');
    $this->csv = fn ($test, string $action = 'exportCsv') => base64_decode($test->callAction(TestAction::make($action)->table())->effects['download']['content'] ?? '');
});

it('exports the stock ledger with its rows, and filters by date, product, type and person', function () {
    $other = makeProduct($this->owner, 'B1', 'Other', retail: '1.00')->fresh(['units']);
    app(InventoryService::class)->adjust($this->a, $this->hq, $other, $other->units->first(), '1', 'adjustment', 'found', 'x');

    expect(($this->csv)(Livewire::test(ListStockMovements::class)))->toContain('THING AND CO')->toContain('OTHER');
    $picked = Livewire::test(ListStockMovements::class)->filterTable('product_id', [$this->med->id])->assertSuccessful();
    expect(($this->csv)($picked))->toContain('THING AND CO')->not->toContain('OTHER');

    Livewire::test(ListStockMovements::class)->filterTable('occurred_at_dates', ['from' => now()->addDay()->toDateString()])->assertCountTableRecords(0);
    Livewire::test(ListStockMovements::class)->filterTable('type', ['opening'])->assertCountTableRecords(1);
    Livewire::test(ListStockMovements::class)->filterTable('actor_id', [$this->owner->id])->assertSuccessful();
});

it('refuses a PDF too long to build and says what to do, without a server error', function () {
    $rows = [];
    for ($i = 0; $i <= TableExport::PDF_ROWS; $i++) {
        $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'branch_id' => $this->hq->branch_id, 'warehouse_id' => $this->hq->id, 'product_id' => $this->med->id, 'qty_base' => '1.000',
            'uom_id' => $this->base->uom_id, 'qty_entered' => '1.000', 'factor' => '1', 'type' => 'sale', 'actor_name' => 'Ama', 'origin_node' => 'HQ', 'occurred_at' => now(), 'received_at' => now()];
    }
    foreach (array_chunk($rows, 500) as $part) {
        DB::table('stock_movements')->insert($part);
    }
    Livewire::test(ListStockMovements::class)->callAction(TestAction::make('exportPdf')->table())->assertNotified('802 rows are too many for a PDF');
});

it('lists prices by unit with tax, filters by brand, and shows cost only to those who set prices', function () {
    $brand = Brand::create(['name' => 'Acme']);
    app(CatalogService::class)->saveProduct($this->a, $this->med, ['brand_id' => $brand->id]);
    $plain = makeProduct($this->owner, 'C1', 'Plain', retail: '5.00')->fresh(['units']);

    $text = ($this->csv)(Livewire::test(PriceListExport::class));
    expect($text)->toContain('THING AND CO')->toContain('PC')->toContain('× 10')->toContain('GH₵ 12.19')->toContain('PLAIN');
    $byBrand = Livewire::test(PriceListExport::class)->filterTable('brand', [$brand->id]);
    expect(($this->csv)($byBrand))->toContain('THING AND CO')->not->toContain('PLAIN');

    Livewire::test(PriceListExport::class)->filterTable('in_stock')->assertCountTableRecords(2);
    Livewire::test(PriceListExport::class)->assertTableColumnExists('cost');

    session()->put('price_list.products', [$plain->id]);
    Livewire::test(PriceListExport::class)->assertCountTableRecords(1)->call('resetTable')->assertSuccessful();

    $clerk = withMfa(staff($this->owner, 'clerk', 2, 'storekeeper', ScopeType::Tenant, $this->tenant->id));
    $this->actingAs($clerk, 'web');
    expect(($this->csv)(Livewire::test(PriceListExport::class)))->not->toContain('Margin');
});

it('switches on batches in bulk, leaves those already on, and puts stock in OPENING', function () {
    $on = makeProduct($this->owner, 'ON1', 'Already on', retail: '1.00');
    app(CatalogService::class)->saveProduct($this->a, $on, ['track_batches' => true]);
    $version = $on->fresh()->version;

    $r = app(CatalogService::class)->switchOnBatches($this->a, [$this->med->fresh(), $on->fresh()]);
    expect($r['done'])->toBe(1)->and($r['untouched'])->toBe(1)->and($on->fresh()->version)->toBe($version)
        ->and($this->med->fresh()->track_batches)->toBeTrue()
        ->and(DB::table('stock_batches')->where('product_id', $this->med->id)->value('batch_no'))->toBe('OPENING')
        ->and((float) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->med->id)->value('on_hand'))->toBe(5.0);

    $more = makeProduct($this->owner, 'M1', 'More', retail: '1.00');
    Livewire::test(ListProducts::class)->callAction('batchesAll')->assertHasNoActionErrors();
    expect($more->fresh()->track_batches)->toBeTrue();
});

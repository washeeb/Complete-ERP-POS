<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Imports\Importers\OpeningBatchesImporter;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ImportData;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->med = makeProduct($this->owner, 'MED', 'Cytotec', retail: '10.00')->fresh(['units']);
    app(InventoryService::class)->adjust($this->a, $this->hq, $this->med, $this->med->units->first(), '100', 'opening', '', null, '4.00');
    app(CatalogService::class)->saveProduct($this->a, $this->med, ['track_batches' => true]); // stock becomes batch OPENING
    $this->sheet = app(OpeningBatchesImporter::class);
    $this->row = fn (array $over = []) => $over + ['warehouse' => $this->hq->code, 'sku' => $this->med->fresh()->sku, 'expires_on' => '2029-02-28', 'batch_no' => '', 'qty' => ''];
    $this->batch = fn (string $no) => DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->where('l.warehouse_id', $this->hq->id)->where('b.batch_no', $no)->first(['l.on_hand', 'b.expires_on']);
});

it('lists stock with no batch, and only that', function () {
    $rows = collect($this->sheet->exportRows($this->a, [], null));
    expect($rows)->toHaveCount(1)->and($rows[0]['no_batch_qty'])->toBe('100')->and($rows[0]['expires_on'])->toBe('');
    $this->sheet->apply($this->a, ($this->row)(), [], null);
    expect(collect($this->sheet->exportRows($this->a, [], null)))->toHaveCount(0);
});

it('dates all of OPENING when no batch is given, and leaves rows with no date', function () {
    expect($this->sheet->apply($this->a, ($this->row)(['expires_on' => '']), [], null))->toBe('unchanged');
    expect(($this->batch)('OPENING')->expires_on)->toBeNull();
    $this->sheet->apply($this->a, ($this->row)(), [], null);
    expect(($this->batch)('OPENING')->expires_on)->toContain('2029-02-28');
});

it('splits OPENING into real batches, with quantity and value unchanged', function () {
    $value = fn () => (int) DB::table('stock_movements')->where('product_id', $this->med->id)->whereNotIn('type', ['putaway', 'batch_assign'])->selectRaw('SUM(ROUND(qty_entered * COALESCE(unit_cost_minor, 0))) AS v')->value('v');
    $before = $value();
    $this->sheet->apply($this->a, ($this->row)(['batch_no' => '835886', 'qty' => '60']), [], null);
    $this->sheet->apply($this->a, ($this->row)(['batch_no' => 'B2', 'expires_on' => '2028-06-30']), [], null); // all that is left

    expect((float) ($this->batch)('835886')->on_hand)->toBe(60.0)->and((float) ($this->batch)('B2')->on_hand)->toBe(40.0)->and((float) ($this->batch)('OPENING')->on_hand)->toBe(0.0)
        ->and((float) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->med->id)->value('on_hand'))->toBe(100.0)
        ->and($value())->toBe($before)->and(app(StockLedger::class)->check($this->tenant->id))->toBeEmpty()
        ->and(DB::table('stock_adjustments')->count())->toBe(0); // nothing to approve
});

it('refuses more than OPENING holds, and a batch with another date', function () {
    expect(fn () => $this->sheet->apply($this->a, ($this->row)(['batch_no' => 'B1', 'qty' => '500']), [], null))->toThrow(DomainException::class, 'cannot move');
    $this->sheet->apply($this->a, ($this->row)(['batch_no' => 'B1', 'qty' => '10']), [], null);
    expect(fn () => $this->sheet->apply($this->a, ($this->row)(['batch_no' => 'B1', 'qty' => '10', 'expires_on' => '2030-01-01']), [], null))->toThrow(DomainException::class, 'already recorded');
});

it('sells a dated batch first once the sheet is done', function () {
    $this->sheet->apply($this->a, ($this->row)(['batch_no' => 'SOON', 'qty' => '10', 'expires_on' => now()->addMonths(2)->toDateString()]), [], null);
    $this->sheet->apply($this->a, ($this->row)(['batch_no' => 'LATER', 'expires_on' => now()->addYears(2)->toDateString()]), [], null);
    DB::transaction(fn () => app(StockLedger::class)->post($this->a, $this->hq, 'sale', [['product' => $this->med->fresh(), 'unit' => $this->med->units->first(), 'qty_milli' => -3000]], alreadyHappened: true));
    expect((float) ($this->batch)('SOON')->on_hand)->toBe(7.0)->and((float) ($this->batch)('LATER')->on_hand)->toBe(90.0);
});

it('is only for those who may adjust stock', function () {
    $cashier = staff($this->owner, 'pick', 2, 'picker_packer', ScopeType::Tenant, $this->tenant->id);
    expect(fn () => $this->sheet->apply(actorFor($cashier), ($this->row)(), [], null))->toThrow(AccessDenied::class);
});

it('downloads the list from the import page, with the columns in order', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    $test = Livewire::withQueryParams(['type' => 'opening_batches'])->test(ImportData::class)->call('downloadCurrent')->assertFileDownloaded();
    $lines = explode("\n", trim(base64_decode($test->effects['download']['content'])));
    expect($lines[0])->toContain('warehouse,sku,product,no_batch_qty,expires_on,batch_no,qty')
        ->and($lines[1])->toContain($this->hq->code)->toContain('CYTOTEC')->toContain(',100,');
});

<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockCostRuns;
use App\Domain\Inventory\StockLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\EstimatedCosts;
use App\Filament\Resources\StockCostRuns\Pages\CreateStockCostRun;
use App\Filament\Resources\StockCostRuns\Pages\ListStockCostRuns;
use App\Filament\Resources\StockCostRuns\Pages\ViewStockCostRun;
use App\Filament\Resources\StockCostRuns\RelationManagers\LinesRelationManager;
use App\Models\Product;
use App\Models\StockCostRun;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->runs = app(StockCostRuns::class);
    app(Settings::class)->set($this->a, 'stock.cost_run_two_people', false);
    $this->stockUp = function (Product $p, string $qty, ?string $cost = null) {
        app(InventoryService::class)->adjust($this->a, $this->hq, $p, $p->units->firstWhere('is_base', true), $qty, 'opening', '', 'Loaded', $cost);
    };
    $this->avg = fn (Product $p) => DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $p->id)->value('avg_cost_minor');
    $this->qty = fn (Product $p) => (float) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $p->id)->value('on_hand');
    $this->books = fn () => GlCheck::subLedgers($this->tenant->id)['inventory'][1];
    $this->deliver = fn (Product $p, int $milli, int $cost) => DB::transaction(fn () => app(StockLedger::class)->post($this->a, $this->hq, 'receipt',
        [['product' => $p, 'unit' => $p->units->firstWhere('is_base', true), 'qty_milli' => $milli, 'unit_cost_minor' => $cost]]));
});

it('works a cost out from the selling price either way, with or without tax', function () {
    expect(StockCostRuns::costFrom('1400', 'divide', 14000))->toBe(1000)
        ->and(StockCostRuns::costFrom('1400', 'margin', 4000))->toBe(840)
        ->and(StockCostRuns::costFrom('50', 'divide', 14000))->toBe(36) // 35.71, half up
        ->and(StockCostRuns::priceUsed('1219', 2190, true, 'shelf'))->toBe('1219')
        ->and((int) round((float) StockCostRuns::priceUsed('1219', 2190, true, 'remove_tax')))->toBe(1000)
        ->and((int) round((float) StockCostRuns::priceUsed('1000', 2190, false, 'shelf')))->toBe(1219)
        ->and(StockCostRuns::priceUsed('1000', 2190, false, 'remove_tax'))->toBe('1000');
});

it('gives stock with no cost a value, and leaves quantities and selling prices alone', function () {
    $p = makeProduct($this->owner, 'NOCOST', 'Amoxicillin', retail: '14.00')->fresh(['units']);
    ($this->stockUp)($p, '10');
    expect(($this->books)())->toBe(0);
    $priceBefore = DB::table('prices')->where('product_id', $p->id)->value('amount_minor');

    $run = $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4']);
    expect($run->stock_value_minor)->toBe(10000)->and($run->lines_count)->toBe(1)->and($run->status)->toBe('draft')
        ->and(($this->books)())->toBe(0); // a preview changes nothing

    $posted = $this->runs->post($this->a, $run);
    expect($posted->status)->toBe('posted')->and($posted->stock_value_minor)->toBe(10000)
        ->and(($this->qty)($p))->toBe(10.0)
        ->and((float) ($this->avg)($p))->toBe(1000.0)
        ->and(($this->books)())->toBe(10000)
        ->and(DB::table('prices')->where('product_id', $p->id)->value('amount_minor'))->toBe($priceBefore);

    // A sale now carries the estimated cost.
    app(AutoPoster::class)->run($this->tenant->id);
    $check = app(GlCheck::class)->run($this->tenant->id);
    expect(collect($check['ties'])->firstWhere('control', 'inventory')['difference'])->toBe(0)
        ->and($check['opening_left'])->toBe(-10000);
});

it('skips what has a real cost unless told to replace it, and lists what it cannot cost', function () {
    $real = makeProduct($this->owner, 'REAL', 'Real cost', retail: '14.00')->fresh(['units']);
    ($this->stockUp)($real, '5', '7.00');
    $noPrice = makeProduct($this->owner, 'NOPRICE', 'No price', retail: '14.00')->fresh(['units']);
    ($this->stockUp)($noPrice, '5');
    DB::table('prices')->where('product_id', $noPrice->id)->delete();

    $run = $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4']);
    expect($run->lines_count)->toBe(0)->and($run->skipped_count)->toBe(1)->and($run->already_costed_count)->toBe(1)
        ->and($run->lines()->first()->skip_reason)->toContain('No selling price');
    expect(fn () => $this->runs->post($this->a, $run))->toThrow(DomainException::class);

    $replace = $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4', 'overwrite' => true, 'product_ids' => [$real->id]]);
    expect($replace->stock_value_minor)->toBe(1500); // 3 500 in the books, 5 000 after
    $this->runs->post($this->a, $replace);
    expect((float) ($this->avg)($real))->toBe(1000.0)->and(($this->books)())->toBe(5000);
});

it('runs on a selection, with a factor per category or brand', function () {
    $a = makeProduct($this->owner, 'AAA', 'First', retail: '14.00')->fresh(['units']);
    $b = makeProduct($this->owner, 'BBB', 'Second', retail: '16.00')->fresh(['units']);
    ($this->stockUp)($a, '1');
    ($this->stockUp)($b, '1');
    $cat = $b->category_id;

    $picked = $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4', 'product_ids' => [$a->id]]);
    expect($picked->lines_count)->toBe(1)->and($picked->stock_value_minor)->toBe(1000);

    if ($cat) {
        DB::table('products')->where('id', $a->id)->update(['category_id' => null]);
        $mixed = $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4', 'overrides' => [['kind' => 'category', 'id' => $cat, 'value' => '1.6']]]);
        expect($mixed->stock_value_minor)->toBe(2000); // 14 ÷ 1.4 + 16 ÷ 1.6
    }
});

it('adds the cost of past sales once, against the same account, and tops the books up after a delivery', function () {
    $sold = makeProduct($this->owner, 'SOLD', 'Sold at no cost', retail: '14.00')->fresh(['units']);
    ($this->stockUp)($sold, '10');
    $base = $sold->units->firstWhere('is_base', true);
    DB::transaction(fn () => app(StockLedger::class)->post($this->a, $this->hq, 'sale', [['product' => $sold, 'unit' => $base, 'qty_milli' => -4000]], alreadyHappened: true));

    $topped = makeProduct($this->owner, 'MIXED', 'Delivered onto no-cost stock', retail: '14.00')->fresh(['units']);
    ($this->stockUp)($topped, '10');
    ($this->deliver)($topped, 5000, 800); // the average is now 8.00, on 15 pieces; the books hold 5 × 8

    $run = $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4']);
    expect($run->stock_value_minor)->toBe(6000 + 8000)->and($run->catch_up_minor)->toBe(4000);
    $this->runs->post($this->a, $run);
    expect((float) ($this->avg)($topped))->toBe(800.0)->and(($this->books)())->toBe(6000 + 12000);

    app(AutoPoster::class)->run($this->tenant->id);
    $cogs = fn () => (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.system_key', 'cogs')->sum('l.amount_minor');
    expect($cogs())->toBe(4000);

    // A second run does not count the same sales again.
    $again = $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4']);
    expect($again->catch_up_minor)->toBe(0)->and($again->lines_count)->toBe(0);
});

it('needs a second person by default, and the right permission', function () {
    app(Settings::class)->set($this->a, 'stock.cost_run_two_people', true);
    $p = makeProduct($this->owner, 'TWO', 'Two people', retail: '14.00')->fresh(['units']);
    ($this->stockUp)($p, '1');
    $run = $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4']);
    expect(fn () => $this->runs->post($this->a, $run))->toThrow(DomainException::class, 'approval');
    $run = $this->runs->submit($this->a, $run);
    expect(fn () => $this->runs->post($this->a, $run))->toThrow(DomainException::class, 'nobody approves');

    $it = staff($this->owner, 'itperson', 4, 'it_admin', ScopeType::Tenant, $this->tenant->id);
    expect(fn () => $this->runs->prepare(actorFor($it), ['method' => 'divide', 'value' => '1.4']))->toThrow(AccessDenied::class);

    $gm = withMfa(staff($this->owner, 'gm', 5, 'general_manager', ScopeType::Tenant, $this->tenant->id));
    expect($this->runs->post(actorFor($gm), $run)->status)->toBe('posted');
});

it('undoes a run, except where a real delivery has changed the cost', function () {
    $p = makeProduct($this->owner, 'UNDO', 'Undone', retail: '14.00')->fresh(['units']);
    $q = makeProduct($this->owner, 'KEPT', 'Delivered since', retail: '14.00')->fresh(['units']);
    ($this->stockUp)($p, '10');
    ($this->stockUp)($q, '10');
    $run = $this->runs->post($this->a, $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4']));
    ($this->deliver)($q, 10000, 1200);
    $books = ($this->books)();

    $this->runs->undo($this->a, $run, 'Wrong factor');
    expect($run->fresh()->status)->toBe('reversed')
        ->and(($this->avg)($p))->toBeNull()
        ->and(($this->qty)($p))->toBe(10.0)
        ->and((float) ($this->avg)($q))->toBe(1100.0)
        ->and(($this->books)())->toBe($books - 10000);

    $back = StockCostRun::where('reverses_id', $run->id)->first();
    expect($back->stock_value_minor)->toBe(-10000)->and($back->lines()->whereNotNull('skip_reason')->count())->toBe(1);
    expect(fn () => $this->runs->undo($this->a, $run->fresh(), 'Again'))->toThrow(DomainException::class);
});

it('refuses a factor that makes no sense', function () {
    expect(fn () => $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '0.9']))->toThrow(DomainException::class, 'more than 1');
    expect(fn () => $this->runs->prepare($this->a, ['method' => 'margin', 'value' => '100']))->toThrow(DomainException::class, 'between 0 and 100');
});

it('keeps batches whole and leaves out batches that do not add up, stock below zero and consignment', function () {
    $med = makeProduct($this->owner, 'BATCH', 'In batches', retail: '14.00');
    app(CatalogService::class)->saveProduct($this->a, $med, ['track_batches' => true]);
    $med = $med->fresh(['units']);
    $base = $med->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust($this->a, $this->hq, $med, $base, '3', 'opening', '', null, null, 'B1', '2027-06-30');
    app(InventoryService::class)->adjust($this->a, $this->hq, $med, $base, '2', 'opening', '', null, null, 'B2', '2027-09-30');
    $below = makeProduct($this->owner, 'BELOW', 'Below zero', retail: '14.00')->fresh(['units']);
    DB::transaction(fn () => app(StockLedger::class)->post($this->a, $this->hq, 'sale', [['product' => $below, 'unit' => $below->units->firstWhere('is_base', true), 'qty_milli' => -2000]], alreadyHappened: true));

    $run = $this->runs->post($this->a, $this->runs->prepare($this->a, ['method' => 'divide', 'value' => '1.4']));
    expect($run->stock_value_minor)->toBe(5000)
        ->and($run->lines()->where('kind', 'skipped')->first()->skip_reason)->toContain('below zero')
        ->and(DB::table('stock_batch_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $med->id)->orderBy('on_hand')->pluck('on_hand')->map(fn ($q) => (float) $q)->all())->toBe([2.0, 3.0])
        ->and(($this->books)())->toBe(5000);
});

it('shows the screens: the form, the preview, the products list and the estimated costs report', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    $p = makeProduct($this->owner, 'SCREEN', 'On screen', retail: '14.00')->fresh(['units']);
    ($this->stockUp)($p, '2');

    Livewire::test(CreateStockCostRun::class)->fillForm(['method' => 'divide', 'value' => '1.4', 'tax_mode' => 'shelf', 'effective_on' => now()->toDateString()])
        ->call('create')->assertHasNoFormErrors();
    $run = StockCostRun::first();
    expect($run->stock_value_minor)->toBe(2000);
    Livewire::test(ListStockCostRuns::class)->assertSuccessful()->assertSee($run->number);
    Livewire::test(ViewStockCostRun::class, ['record' => $run->id])->assertSuccessful()->assertSee('Preview')->callAction('post')->assertHasNoActionErrors();
    expect($run->fresh()->status)->toBe('posted');
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $run->fresh(), 'pageClass' => ViewStockCostRun::class])->assertSuccessful()->assertSee('ON SCREEN');
    Livewire::test(EstimatedCosts::class)->assertSuccessful()->assertSee('ON SCREEN')->assertSee('1 product(s) carry');

});

<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockChecks;
use App\Filament\Pages\MyDay;
use App\Filament\Pages\ShrinkReport;
use App\Filament\Pages\SlowStock;
use App\Models\StockTransfer;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $this->inv = app(InventoryService::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** A sale movement straight in the ledger (the till's sale is tested elsewhere). */
function soldAt(Warehouse $w, $product, string $qty, Carbon $at): void
{
    DB::table('stock_movements')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $w->tenant_id, 'branch_id' => $w->branch_id, 'warehouse_id' => $w->id,
        'product_id' => $product->id, 'qty_base' => '-'.$qty, 'uom_id' => Uom::where('code', 'PC')->value('id'), 'qty_entered' => '-'.$qty, 'factor' => 1,
        'type' => 'sale', 'actor_name' => 'Till', 'origin_node' => 'hq', 'occurred_at' => $at, 'received_at' => $at, 'unit_cost_minor' => 100]);
}

it('lists stock that has not sold, and moves it in one click to the store where it sells', function () {
    $slow = makeProduct($this->owner, 'SLOW', 'Slow cream', retail: '20.00');
    $fast = makeProduct($this->owner, 'FAST', 'Fast tablets', retail: '5.00');
    Carbon::setTestNow(now()->subDays(120));
    $this->inv->adjust($this->a, $this->hq, $slow, $slow->units->first(), '12', 'opening', '', null, '8.00');
    $this->inv->adjust($this->a, $this->hq, $fast, $fast->units->first(), '50', 'opening', '', null, '2.00');
    Carbon::setTestNow();
    soldAt($this->hq, $fast, '5', now()->subDays(3));
    soldAt($this->ba, $slow, '7', now()->subDays(10));

    $this->actingAs(withMfa($this->owner), 'web');
    $page = Livewire::test(SlowStock::class)->set('warehouse', $this->hq->id)->set('days', 90);
    $rows = $page->instance()->rows();
    expect($rows->pluck('sku')->all())->toBe(['SLOW'])->and($rows->first()->value)->toBe(9600)->and($rows->first()->move_to->id)->toBe($this->ba->id);
    $page->assertSee('SLOW CREAM')->assertSee('Sells at')
        ->callAction('move', arguments: ['product' => $slow->id, 'to' => $this->ba->id]);
    $t = StockTransfer::sole();
    expect($t->status)->toBe('draft')->and($t->to_warehouse_id)->toBe($this->ba->id)->and((string) $t->lines()->sole()->qty)->toBe('12.000');
});

it('points at losses larger than usual, products written off again and again, and possible duplicates', function () {
    $p = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00');
    makeProduct($this->owner, 'MILO2', 'milo  400G', retail: '15.00'); // same name, other spacing and capitals
    $this->inv->adjust($this->a, $this->hq, $p, $p->units->first(), '500', 'opening', '', null, '10.00');
    Carbon::setTestNow(now()->subDays(60));
    foreach (range(1, 5) as $i) {
        $this->inv->adjust($this->a, $this->hq, $p, $p->units->first(), '-1', 'adjustment', 'damaged'); // usual: GHS 10 a line
    }
    Carbon::setTestNow();
    foreach (['-2', '-1', '-30'] as $q) {
        $this->inv->adjust($this->a, $this->hq, $p, $p->units->first(), $q, 'adjustment', 'theft');
    }

    $c = app(StockChecks::class);
    expect($c->largerThanUsual($this->hq->id)->pluck('value')->all())->toBe([30000]) // 30 × GHS 10, over 3 × the usual and GHS 100
        ->and($c->againAndAgain($this->hq->id)->first()->times)->toBe(3)
        ->and($c->possibleDuplicates($this->tenant->id)->first()->skus)->toBe('MILO, MILO2');

    $sc = withMfa(staff($this->owner, 'kwesi', 3, 'stock_controller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($sc, 'web')->get(MyDay::getUrl())->assertOk()->assertSee('Losses larger than usual')->assertSee('Written off three times or more')
        ->assertSee('Possible duplicate products');
    $this->get(ShrinkReport::getUrl())->assertOk()->assertSee('Last six months')->assertSee('Recorded by')->assertSee('Lost again and again');
    // A branch person cannot look at another branch's losses by typing its store into the address.
    Livewire::test(ShrinkReport::class)->set('warehouse', $this->ba->id)->assertSet('warehouse', '');
});

<?php

use App\Domain\Inventory\CycleCounts;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\MyDay;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $inv = app(InventoryService::class);
    $this->products = [];
    foreach ([['FAST', 'Fast seller', '50.00', '100'], ['SLOW', 'Slow seller', '5.00', '20'], ['MID', 'Middle', '10.00', '40']] as [$sku, $name, $cost, $qty]) {
        $p = makeProduct($this->owner, $sku, $name, retail: '99.00', tax: 'EXEMPT');
        $inv->adjust($this->a, $this->hq, $p, $p->units->first(), $qty, 'opening', '', null, $cost);
        $this->products[$sku] = $p;
    }
    // Movement in the last 90 days: FAST moves a lot of value, SLOW nothing.
    $inv->adjust($this->a, $this->hq, $this->products['FAST'], $this->products['FAST']->units->first(), '-60', 'adjustment', 'damaged');
    $inv->adjust($this->a, $this->hq, $this->products['MID'], $this->products['MID']->units->first(), '-5', 'adjustment', 'damaged');
    app(Settings::class)->set($this->a, 'stock.cycle_count_per_day', 2);
});

function postCount($t, array $counted): StockCount
{
    $svc = app(StockCountService::class);
    $count = $svc->start($t->a, $t->hq, null, true, array_map(fn ($sku) => $t->products[$sku]->id, array_keys($counted)));
    foreach ($counted as $sku => $qty) {
        $svc->record($t->a, $count, $t->products[$sku], $t->products[$sku]->units->first(), $qty);
    }
    $count = $svc->submit($t->a, $count->fresh());
    $manager = withMfa(staff($t->owner, 'mgr'.random_int(100, 999), 4, 'warehouse_manager'));
    $svc->post(actorFor($manager), $count->fresh());

    return $count->fresh();
}

it('picks the products to count by value moved, never counted first, and counts often-wrong ones twice as often', function () {
    $due = app(CycleCounts::class)->due($this->hq);
    expect($due->pluck('class', 'sku')->all())->toBe(['FAST' => 'A', 'MID' => 'C', 'SLOW' => 'C']) // FAST is over 95% of the value moved; all never counted: A first
        ->and($due->first()->last_counted)->toBeNull();

    $count = app(CycleCounts::class)->start($this->a, $this->hq);
    expect($count->note)->toBe(CycleCounts::note())->and($count->lines)->toHaveCount(2)->and($count->blind)->toBeTrue()
        ->and(fn () => app(CycleCounts::class)->start($this->a, $this->hq))->toThrow(DomainException::class, "Today's count");
    app(StockCountService::class)->cancel($this->a, $count, 'test');

    // Counted today: not due again for 30 / 60 / 120 days. FAST came out wrong twice: it is "often wrong".
    postCount($this, ['FAST' => '39', 'MID' => '35', 'SLOW' => '20']);
    postCount($this, ['FAST' => '38']);
    expect(app(CycleCounts::class)->due($this->hq))->toBeEmpty()
        ->and(app(CycleCounts::class)->oftenWrongIds($this->hq))->toBe([$this->products['FAST']->id]);
    $this->travel(16)->days(); // FAST (A, often wrong): every 15 days
    expect(app(CycleCounts::class)->due($this->hq)->pluck('sku')->all())->toBe(['FAST']);
});

it('shows My day for a stock controller and starts today\'s count from it', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $sc = withMfa(staff($this->owner, 'kwesi', 3, 'stock_controller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($sc, 'web')->get(MyDay::getUrl())->assertOk()->assertSee("Today's cycle count")->assertSee('FAST SELLER')
        ->assertSee('Negative stock')->assertSee('Never counted here');
    Livewire::test(MyDay::class)->set('warehouse', $this->hq->id)->callAction('startCycle')->assertNotified();
    expect(StockCount::where('note', CycleCounts::note())->sole()->lines()->count())->toBe(2);
});

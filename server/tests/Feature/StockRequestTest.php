<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockRequestService;
use App\Domain\Inventory\TransferService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequest;
use App\Filament\Resources\StockRequests\Pages\ViewStockRequest;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\Industry;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 0, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 0, 'modules' => ['pharmacy']],
        ['code' => 'P2', 'name' => 'Pharmacy Tema', 'is_hq' => false, 'tills' => 0, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Grocery Spintex', 'is_hq' => false, 'tills' => 0, 'modules' => ['grocery']],
    ]);
    $this->wh = collect($this->b)->map(fn ($b) => Warehouse::where('branch_id', $b->id)->first());
    $pharmacy = Industry::where('module_key', 'pharmacy')->value('id');
    $grocery = Industry::where('module_key', 'grocery')->value('id');

    $item = function (string $sku, string $name, ?string $industry, ?string $stockAt = null, string $cost = '1.00') {
        $p = makeProduct($this->owner, $sku, $name, retail: '5.00');
        app(CatalogService::class)->saveProduct(actorFor($this->owner), $p, ['track_batches' => true]);
        $p->forceFill(['industry_id' => $industry])->saveQuietly();
        $p = $p->fresh();
        if ($stockAt) {
            app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh[$stockAt], $p, $p->units->first(), '50', 'opening', '', null, $cost, "{$sku}-B1", now()->addYear()->toDateString());
        }

        return $p->fresh();
    };
    $this->para = $item('PCM', 'Paracetamol 500mg', $pharmacy, 'P1');
    $this->waterPh = $item('PH-WATER', 'Bottled water (pharmacy)', $pharmacy, 'P1', '2.50');
    $this->waterGr = $item('GR-WATER', 'Bottled water', $grocery);

    $this->p1Keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['P1']->id);
    $this->p1Mgr = staff($this->owner, 'kojo', 4, 'branch_manager', ScopeType::Branch, $this->b['P1']->id);
    $this->p2Keeper = staff($this->owner, 'efua', 2, 'storekeeper', ScopeType::Branch, $this->b['P2']->id);
    $this->g1Keeper = staff($this->owner, 'ama', 2, 'storekeeper', ScopeType::Branch, $this->b['G1']->id);
    $this->g1Mgr = staff($this->owner, 'kwesi', 4, 'branch_manager', ScopeType::Branch, $this->b['G1']->id);
    $this->svc = app(StockRequestService::class);
});

function srqOnHand(Warehouse $w, Product $p): string
{
    return (string) (DB::table('stock_levels')->where('warehouse_id', $w->id)->where('product_id', $p->id)->value('on_hand') ?? '0.000');
}

function srqReceive(StockRequest $r, $user): void
{
    app(TransferService::class)->receive(actorFor($user), StockTransfer::findOrFail($r->fresh()->stock_transfer_id));
}

it('lets a pharmacy ask its sister pharmacy for stock, and keeps the industries apart', function () {
    // Straight transfers across industries, or of another industry's products, are refused.
    expect(fn () => app(TransferService::class)->saveDraft(actorFor($this->owner), new StockTransfer, ['from_warehouse_id' => $this->wh['P1']->id,
        'to_warehouse_id' => $this->wh['G1']->id, 'lines' => [['product_uom_id' => $this->waterPh->units->first()->id, 'qty' => '1']]]))
        ->toThrow(DomainException::class, 'different industries');
    expect(fn () => app(TransferService::class)->saveDraft(actorFor($this->owner), new StockTransfer, ['from_warehouse_id' => $this->wh['HQ']->id,
        'to_warehouse_id' => $this->wh['G1']->id, 'lines' => [['product_uom_id' => $this->para->units->first()->id, 'qty' => '1']]]))
        ->toThrow(DomainException::class, 'Pharmacy catalogue');

    $r = $this->svc->request(actorFor($this->p2Keeper), ['from_warehouse_id' => $this->wh['P1']->id, 'to_warehouse_id' => $this->wh['P2']->id,
        'note' => 'Weekend cover', 'lines' => [['product_uom_id' => $this->para->units->first()->id, 'qty' => '10']]]);
    expect($r->number)->toStartWith('SRQ-')->and($r->cross_industry)->toBeFalse()->and($r->waitingFor())->toBe('supply');

    expect(fn () => $this->svc->supply(actorFor($this->p2Keeper), $r))->toThrow(AccessDenied::class); // the asking branch cannot send it to itself
    $this->svc->supply(actorFor($this->p1Keeper), $r, [$r->lines[0]->id => ['qty' => '8']]); // only 8 to spare
    expect($r->fresh()->status)->toBe('supplied')->and(srqOnHand($this->wh['P1'], $this->para))->toBe('42.000')
        ->and($r->fresh()->lines[0]->supplied_qty)->toBe('8.000');

    srqReceive($r, $this->p2Keeper);
    expect(srqOnHand($this->wh['P2'], $this->para))->toBe('8.000')->and($r->fresh()->status)->toBe('received')
        ->and(DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->where('l.warehouse_id', $this->wh['P2']->id)->value('b.batch_no'))->toBe('PCM-B1');
});

it('moves stock between industries only after both sides approve, at cost, as the receiving catalogue\'s product', function () {
    expect(fn () => $this->svc->request(actorFor($this->g1Keeper), ['from_warehouse_id' => $this->wh['P1']->id, 'to_warehouse_id' => $this->wh['G1']->id,
        'lines' => [['product_uom_id' => $this->para->units->first()->id, 'qty' => '1']]]))->toThrow(DomainException::class, 'not in your catalogue');

    $r = $this->svc->request(actorFor($this->g1Keeper), ['from_warehouse_id' => $this->wh['P1']->id, 'to_warehouse_id' => $this->wh['G1']->id,
        'lines' => [['product_uom_id' => $this->waterGr->units->first()->id, 'qty' => '5']]]);
    expect($r->cross_industry)->toBeTrue()->and($r->waitingFor())->toBe('requester');

    expect(fn () => $this->svc->supply(actorFor($this->p1Keeper), $r))->toThrow(DomainException::class, 'approval');
    expect(fn () => $this->svc->approve(actorFor($this->g1Keeper), $r))->toThrow(AccessDenied::class);
    $this->svc->approve(actorFor($this->g1Mgr), $r);
    expect($r->fresh()->waitingFor())->toBe('supplier');
    expect(fn () => $this->svc->approve(actorFor($this->g1Mgr), $r->fresh()))->toThrow(AccessDenied::class); // the other side must approve
    $this->svc->approve(actorFor($this->p1Mgr), $r->fresh());
    expect($r->fresh()->status)->toBe('approved');

    $line = $r->lines[0]->id;
    expect(fn () => $this->svc->supply(actorFor($this->p1Keeper), $r->fresh()))->toThrow(DomainException::class, 'which of your products');
    $this->svc->supply(actorFor($this->p1Keeper), $r->fresh(), [$line => ['send_product_id' => $this->waterPh->id]]);
    srqReceive($r, $this->g1Keeper);

    expect(srqOnHand($this->wh['P1'], $this->waterPh))->toBe('45.000')
        ->and(srqOnHand($this->wh['G1'], $this->waterGr))->toBe('5.000')
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->wh['G1']->id)->where('product_id', $this->waterGr->id)->value('avg_cost_minor'))->toBe('250.0000')
        ->and(DB::table('stock_batches')->where('product_id', $this->waterGr->id)->value('batch_no'))->toBe('PH-WATER-B1')
        ->and($r->fresh()->status)->toBe('received');
});

it('asks someone other than the requester to approve, and lets either side stop a request', function () {
    $mgrAsks = $this->svc->request(actorFor($this->g1Mgr), ['from_warehouse_id' => $this->wh['P1']->id, 'to_warehouse_id' => $this->wh['G1']->id,
        'lines' => [['product_uom_id' => $this->waterGr->units->first()->id, 'qty' => '2']]]);
    expect(fn () => $this->svc->approve(actorFor($this->g1Mgr), $mgrAsks))->toThrow(DomainException::class, 'Someone other');

    $this->svc->cancel(actorFor($this->g1Keeper), $mgrAsks, 'Bought locally');
    expect($mgrAsks->fresh()->status)->toBe('cancelled');

    $r = $this->svc->request(actorFor($this->p2Keeper), ['from_warehouse_id' => $this->wh['P1']->id, 'to_warehouse_id' => $this->wh['P2']->id,
        'lines' => [['product_uom_id' => $this->para->units->first()->id, 'qty' => '5']]]);
    expect(fn () => $this->svc->reject(actorFor($this->p2Keeper), $r, 'no'))->toThrow(AccessDenied::class);
    $this->svc->reject(actorFor($this->p1Keeper), $r, 'Running low ourselves');
    expect($r->fresh()->status)->toBe('rejected')
        ->and(fn () => $this->svc->supply(actorFor($this->p1Keeper), $r->fresh()))->toThrow(DomainException::class, 'already rejected');
});

it('runs a request between industries through the screens', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->g1Keeper), 'web');
    Livewire::test(CreateStockRequest::class)->fillForm(['to_warehouse_id' => $this->wh['G1']->id, 'from_warehouse_id' => $this->wh['P1']->id,
        'lines' => [['product_uom_id' => $this->waterGr->units->first()->id, 'qty' => '3']]])->call('create')->assertHasNoFormErrors();
    $r = StockRequest::firstOrFail();
    Livewire::test(ViewStockRequest::class, ['record' => $r->id])->assertActionHidden('approve')->assertActionHidden('supply')->assertActionVisible('cancel');

    $this->actingAs(withMfa($this->g1Mgr), 'web');
    Livewire::test(ViewStockRequest::class, ['record' => $r->id])->callAction('approve')->assertNotified('Approved');
    $this->actingAs(withMfa($this->p1Mgr), 'web');
    expect(StockRequestResource::getNavigationBadge())->toBe('1');
    Livewire::test(ViewStockRequest::class, ['record' => $r->id])->callAction('approve')->assertNotified('Approved');
    Livewire::test(ViewStockRequest::class, ['record' => $r->id])
        ->callAction('supply', ['lines' => [['line_id' => $r->lines[0]->id, 'qty' => '3', 'send_product_id' => $this->waterPh->id]]])->assertNotified('Sent');
    expect($r->fresh()->status)->toBe('supplied')->and(srqOnHand($this->wh['P1'], $this->waterPh))->toBe('47.000');
    $this->get(StockRequestResource::getUrl('index'))->assertOk()->assertSee($r->number);
});

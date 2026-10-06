<?php

use App\Domain\Inventory\BinService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\TransferService;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Bin;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\ReviewItem;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->pc = $this->milo->units->firstWhere('is_base', true);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'nestle', 'name' => 'Nestlé Ghana',
        'products' => [['product_uom_id' => $this->ctn->id, 'supplier_sku' => 'N-MILO-24', 'last_cost' => '240.00', 'min_order_qty' => '5', 'is_preferred' => true]]]);

    $this->officer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    $this->baKeeper = staff($this->owner, 'efua', 2, 'storekeeper', ScopeType::Branch, $this->branches['BA']->id);
    $this->baManager = staff($this->owner, 'kwesi', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id);
    $this->po = app(PurchaseOrderService::class);
    $this->grn = app(GoodsReceiptService::class);
});

function whQty(Warehouse $w, string $productId): string
{
    return (string) DB::table('stock_levels')->where('warehouse_id', $w->id)->where('product_id', $productId)->value('on_hand');
}

function inBin(Bin $b, string $productId): string
{
    return (string) (DB::table('stock_bin_levels')->where('bin_id', $b->id)->where('product_id', $productId)->value('on_hand') ?? '0.000');
}

function approvedPo($t, string $cartons): PurchaseOrder
{
    $po = $t->po->saveDraft(actorFor($t->officer), new PurchaseOrder, ['supplier_id' => $t->supplier->id, 'warehouse_id' => $t->hq->id,
        'lines' => [['product_uom_id' => $t->ctn->id, 'qty' => $cartons]]]);
    $t->po->submit(actorFor($t->officer), $po);

    return $t->po->approve(actorFor($t->manager), $po->fresh());
}

it('runs receive → put-away → transfer → count end to end, with the ledger and costs consistent', function () {
    app(BinService::class)->setUseBins(actorFor($this->owner), $this->hq, true);
    $a1 = app(BinService::class)->saveBin(actorFor($this->owner), new Bin, ['warehouse_id' => $this->hq->id, 'code' => 'a-01', 'pick_order' => 1]);
    $recv = BinService::receivingBin($this->hq->fresh());

    // Purchase order: officer drafts (cost from the supplier list), a manager approves.
    $po = approvedPo($this, '10');
    expect($po->number)->toMatch('/^PO-\w+-000001$/')->and($po->status)->toBe('approved')->and($po->net_minor)->toBe(240000);

    // Receive: 10 cartons = 240 pieces into the receiving bin at GHS 10.00 a piece.
    $grn = $this->grn->receive(actorFor($this->keeper), $po, ['supplier_ref' => 'DN-5531', 'lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '10']]]);
    expect($grn->status)->toBe('posted')->and($grn->number)->toMatch('/^GRN-/')
        ->and(whQty($this->hq, $this->milo->id))->toBe('240.000')->and(inBin($recv, $this->milo->id))->toBe('240.000')
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->value('avg_cost_minor'))->toBe('1000.0000')
        ->and($po->fresh()->status)->toBe('received');

    // Put away half onto the shelf.
    app(BinService::class)->putAway(actorFor($this->keeper), $this->hq->fresh(), $this->milo, $this->ctn, '5', $recv->id, $a1->id);
    expect(inBin($a1, $this->milo->id))->toBe('120.000')->and(inBin($recv, $this->milo->id))->toBe('120.000')->and(whQty($this->hq, $this->milo->id))->toBe('240.000');

    // Transfer 2 cartons to branch A: taken from the shelf first; in transit until received; 46 pieces arrive.
    $trf = app(TransferService::class)->saveDraft(actorFor($this->keeper), new StockTransfer, ['from_warehouse_id' => $this->hq->id, 'to_warehouse_id' => $this->ba->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '2']]]);
    app(TransferService::class)->dispatch(actorFor($this->keeper), $trf);
    expect(inBin($a1, $this->milo->id))->toBe('72.000')->and(whQty($this->hq, $this->milo->id))->toBe('192.000')
        ->and(TransferService::inTransitTo($this->ba->id)[$this->milo->id])->toBe(48000);
    expect(fn () => app(TransferService::class)->receive(actorFor($this->keeper), $trf->fresh()))->toThrow(AccessDenied::class); // HQ keeper cannot receive at A
    $line = $trf->lines()->first();
    app(TransferService::class)->receive(actorFor($this->baKeeper), $trf->fresh(), [$line->id => '1.917']); // 46 pieces ≈ 1.917 cartons
    expect(whQty($this->ba, $this->milo->id))->toBe('46.008')
        ->and(ReviewItem::where('type', 'transfer_short')->exists())->toBeTrue()
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->ba->id)->value('avg_cost_minor'))->toBe('1000.0000');

    // Count at branch A (blind): 45 found. Counter submits; someone else posts.
    $counts = app(StockCountService::class);
    $count = $counts->start(actorFor($this->baKeeper), $this->ba);
    $counts->record(actorFor($this->baKeeper), $count, $this->milo, $this->pc, '45');
    $counts->submit(actorFor($this->baKeeper), $count->fresh());
    expect(fn () => $counts->post(actorFor($this->baKeeper), $count->fresh()))->toThrow(AccessDenied::class);
    $r = $counts->post(actorFor($this->baManager), $count->fresh());
    expect($r['lines'])->toBe(1)->and($r['value_minor'])->toBe(-1008) // −1.008 pc × GHS 10
        ->and(whQty($this->ba, $this->milo->id))->toBe('45.000')
        ->and(app(StockLedger::class)->check())->toBe([]);
});

it('never lets the creator approve, and enforces approval limits', function () {
    $po = $this->po->saveDraft(actorFor($this->officer), new PurchaseOrder, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '30']]]); // GHS 7,200 before tax
    $this->po->submit(actorFor($this->officer), $po);

    expect(fn () => $this->po->approve(actorFor($this->officer), $po->fresh()))->toThrow(DomainException::class, 'someone else must approve');
    $officer2 = staff($this->owner, 'abena', 2, 'purchasing_officer');
    expect(fn () => $this->po->approve(actorFor($officer2), $po->fresh()))->toThrow(DomainException::class, 'above your approval limit');
    $this->po->approve(actorFor($this->manager), $po->fresh()); // GHS 20,000 limit

    expect($po->fresh()->status)->toBe('approved')->and($po->fresh()->approved_by_name)->toBe('Kojo');
});

it('holds a delivery above the 5% tolerance until a manager accepts it', function () {
    $po = approvedPo($this, '10');
    $line = $po->lines[0];

    $held = $this->grn->receive(actorFor($this->keeper), $po, ['lines' => [['purchase_order_line_id' => $line->id, 'qty' => '11']]]); // +10 %
    expect($held->status)->toBe('held')->and(whQty($this->hq, $this->milo->id))->toBe('')
        ->and(ReviewItem::where('type', 'grn_held')->where('status', 'open')->exists())->toBeTrue();

    $this->grn->approveHeld(actorFor($this->manager), $held);
    expect($held->fresh()->status)->toBe('posted')->and(whQty($this->hq, $this->milo->id))->toBe('264.000')
        ->and(ReviewItem::where('type', 'grn_held')->where('status', 'open')->exists())->toBeFalse();

    // Within tolerance goes straight through: 10.5 cartons on a new order of 10.
    $po2 = approvedPo($this, '10');
    expect($this->grn->receive(actorFor($this->keeper), $po2, ['lines' => [['purchase_order_line_id' => $po2->lines[0]->id, 'qty' => '10.5']]])->status)->toBe('posted');
});

it('reverses a goods received note and reopens the order, never editing the posted note', function () {
    $po = approvedPo($this, '10');
    $grn = $this->grn->receive(actorFor($this->keeper), $po, ['lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '4']]]);
    expect($po->fresh()->status)->toBe('partially_received');

    expect(fn () => $this->grn->reverse(actorFor($this->keeper), $grn, 'wrong item'))->toThrow(AccessDenied::class);
    $this->grn->reverse(actorFor($this->manager), $grn, 'Counted wrong at the door');

    expect($grn->fresh()->status)->toBe('reversed')->and(whQty($this->hq, $this->milo->id))->toBe('0.000')
        ->and($po->fresh()->status)->toBe('approved')->and((string) $po->lines()->first()->received_qty)->toBe('0.000')
        ->and(GoodsReceipt::where('id', $grn->id)->value('net_minor'))->toBe(96000);
});

it('suggests reorders up to max in supplier cartons, counting what is already on order', function () {
    app(ReorderService::class)->saveRule(actorFor($this->manager), $this->hq, $this->milo, '100', '300');
    $s = app(ReorderService::class)->suggestions($this->hq);
    expect($s)->toHaveCount(1)->and($s[0]['suggest_qty'])->toBe(13000) // 300 pc = 12.5 → 13 cartons
        ->and($s[0]['supplier'])->toBe('Nestlé Ghana');

    $pos = app(ReorderService::class)->createDrafts(actorFor($this->officer), $this->hq, [$this->milo->id]);
    expect($pos)->toHaveCount(1)->and($pos[0]->status)->toBe('draft')->and((string) $pos[0]->lines[0]->qty)->toBe('13.000')
        ->and(app(ReorderService::class)->suggestions($this->hq))->toBe([]); // now covered by the open order
});

it('numbers documents per node without gaps', function () {
    $a = approvedPo($this, '5');
    $b = approvedPo($this, '5');
    $node = config('erp.node.id');

    expect([$a->number, $b->number])->toBe(["PO-{$node}-000001", "PO-{$node}-000002"]);
});

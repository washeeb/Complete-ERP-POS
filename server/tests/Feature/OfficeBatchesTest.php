<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Models\Markdown;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Batches & expiry in the new back office (release 7, part 4b): the store's batches, markdowns (apply, withdraw),
// Correct expiry, and Add or move a batch as a draft stock adjustment.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $med = makeProduct($this->owner, 'AMOX', 'Amoxicillin 500mg', retail: '30.00');
    app(CatalogService::class)->saveProduct($a, $med, ['track_batches' => true]);
    $this->med = $med->fresh(['units']);
    $inv = app(InventoryService::class);
    $inv->adjust($a, $this->hq, $this->med, $this->med->units->first(), '10', 'opening', '', null, '20.00', 'B1', '2027-03-31');
    $inv->adjust($a, $this->hq, $this->med, $this->med->units->first(), '4', 'opening', '', null, '20.00', 'SOON', now()->addDays(3)->toDateString());
    $this->b1 = StockBatch::where('batch_no', 'B1')->sole();
    $this->soon = StockBatch::where('batch_no', 'SOON')->sole();
    $this->owner = withMfa($this->owner);
});

it('shows the batches, marks one down and withdraws it, and corrects an expiry date', function () {
    $this->actingAs($this->owner, 'web');
    $d = $this->getJson("/office-api/batches?warehouse={$this->hq->id}")->assertOk()->json();
    expect($d)->toMatchArray(['warehouse' => $this->hq->id, 'can_correct' => true, 'can_add' => true])
        ->and(array_column($d['batches'], 'batch_no'))->toContain('B1', 'SOON')->and($d['sheet'])->toBe('/office/import?type=opening_batches');
    expect($d['can_mark_down'])->toBeTrue();
    $s = collect($d['suggestions'])->firstWhere('batch_no', 'SOON');
    expect($s)->not->toBeNull();
    $this->postJson('/office-api/batches/markdowns', ['warehouse' => $this->hq->id, 'batch' => $this->soon->id, 'percent' => '0'])->assertStatus(422);
    $this->postJson('/office-api/batches/markdowns', ['warehouse' => $this->hq->id, 'batch' => $this->soon->id, 'percent' => $s['percent']])->assertOk();
    $after = $this->getJson("/office-api/batches?warehouse={$this->hq->id}")->json();
    expect($after['markdowns'])->toHaveCount(1)->and($after['labels'])->toContain('markdown-labels');
    $this->postJson("/office-api/batches/markdowns/{$after['markdowns'][0]['id']}/withdraw")->assertOk();
    expect(Markdown::where('status', 'active')->count())->toBe(0);

    $this->postJson('/office-api/batches/correct', ['batch' => $this->b1->id, 'expires_on' => '2027-04-30', 'reason' => ''])->assertStatus(422);
    $this->postJson('/office-api/batches/correct', ['batch' => $this->b1->id, 'expires_on' => '2027-04-30', 'reason' => 'Date mistyped at receiving'])->assertOk();
    expect($this->b1->fresh()->expires_on->toDateString())->toBe('2027-04-30');
});

it('adds a found batch and moves stock to the right batch as draft adjustments', function () {
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/batches/products?q=amox')->json('products.0.id'))->toBe($this->med->id);
    expect($this->getJson("/office-api/batches/product-batches?warehouse={$this->hq->id}&product={$this->med->id}")->json('batches'))->toHaveCount(2);

    $found = $this->postJson('/office-api/batches/add', ['warehouse' => $this->hq->id, 'kind' => 'found', 'product_id' => $this->med->id, 'batch_no' => 'x9',
        'expires_on' => '2027-06-30', 'qty' => '3'])->assertOk()->json('adjustment');
    expect($found['open'])->toBe("/office/adjustments/{$found['id']}");
    $a = StockAdjustment::with('lines')->find($found['id']);
    expect($a->status)->toBe('draft')->and($a->lines)->toHaveCount(1)->and($a->lines[0]->batch_no)->toBe('X9');

    $this->postJson('/office-api/batches/add', ['warehouse' => $this->hq->id, 'kind' => 'move', 'product_id' => $this->med->id, 'batch_no' => 'B2',
        'expires_on' => '2027-06-30', 'qty' => '2'])->assertStatus(422); // which batch to take it from
    $moved = $this->postJson('/office-api/batches/add', ['warehouse' => $this->hq->id, 'kind' => 'move', 'product_id' => $this->med->id, 'from_batch_id' => $this->b1->id,
        'batch_no' => 'B2', 'expires_on' => '2027-06-30', 'qty' => '2'])->assertOk()->json('adjustment');
    expect(StockAdjustment::with('lines')->find($moved['id'])->lines)->toHaveCount(2);
});

it('keeps batches from a teller and another branch out of reach', function () {
    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/batches')->assertForbidden();

    $other = Warehouse::where('branch_id', $this->b['BA']->id)->first();
    $bm = withMfa(staff($this->owner, 'kofi', 4, 'branch_manager', ScopeType::Branch, $this->b['BA']->id));
    $this->actingAs($bm, 'web');
    expect($this->getJson("/office-api/batches?warehouse={$this->hq->id}")->json('warehouse'))->toBe($other->id); // falls back to their own store
    $this->postJson('/office-api/batches/add', ['warehouse' => $this->hq->id, 'kind' => 'found', 'product_id' => $this->med->id, 'batch_no' => 'X',
        'expires_on' => '2027-06-30', 'qty' => '1'])->assertForbidden();
});

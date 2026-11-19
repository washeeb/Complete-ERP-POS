<?php

use App\Domain\Inventory\InventoryService;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Stock reports in the new back office (release 7, part 4a): Shrink and Slow and dead stock, with Move and the export.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $this->other = Warehouse::where('branch_id', $this->b['BA']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    $unit = $this->milo->units->first();
    $inv = app(InventoryService::class);
    $inv->adjust(actorFor($this->owner), $this->wh, $this->milo, $unit, '20', 'opening', '', null, '40.00');
    $inv->adjust(actorFor($this->owner), $this->wh, $this->milo, $unit, '-2', 'adjustment', 'damaged', 'Dropped');
    $this->manager = withMfa(staff($this->owner, 'abena', 4, 'warehouse_manager'));
});

it('shows shrink by reason, product and month, and exports it', function () {
    $this->actingAs($this->manager, 'web');
    $d = $this->getJson('/office-api/shrink')->assertOk()->json();
    expect($d['from'])->toBe(now()->startOfMonth()->toDateString())->and($d['total'])->toContain('80.00')->and($d['entries'])->toBe(1)
        ->and(array_column($d['tables'], 'title'))->toContain('By reason (at cost)', 'Top products', 'Last six months');
    expect($d['tables'][1]['rows'][0]['cells'])->toMatchArray([0 => 'MILO 400G', 2 => '2'])->and($d['tables'][2]['rows'][0]['cells'][1]['bar'])->toBe(100);
    expect($this->getJson("/office-api/shrink?warehouse={$this->other->id}")->json('entries'))->toBe(0);

    $csv = $this->get('/office-api/shrink/export?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('By reason (at cost)')->toContain('MILO 400G');
});

it('lists stock not sold for the period, and moves it as a draft transfer', function () {
    $this->travel(120)->days(); // it came in four months ago and never sold
    $this->actingAs($this->manager, 'web');
    $d = $this->getJson("/office-api/slow-stock?warehouse={$this->wh->id}&days=90")->assertOk()->json();
    expect($d)->toMatchArray(['warehouse' => $this->wh->id, 'days' => 90, 'count' => 1, 'can_move' => true])
        ->and($d['rows'][0])->toMatchArray(['name' => 'MILO 400G', 'on_hand' => '18', 'last_sold' => null])->and($d['rows'][0]['idle_days'])->toBeGreaterThanOrEqual(119);
    expect($this->getJson("/office-api/slow-stock?warehouse={$this->wh->id}&days=180")->json('count'))->toBe(0);
    $csv = $this->get("/office-api/slow-stock/export?warehouse={$this->wh->id}&days=90&format=csv");
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Mark it down');

    $t = $this->postJson('/office-api/slow-stock/move', ['warehouse' => $this->wh->id, 'product' => $this->milo->id, 'to' => $this->other->id])->assertOk()->json('transfer');
    expect($t['open'])->toBe("/office/transfers/{$t['id']}");
    $draft = StockTransfer::with('lines')->find($t['id']);
    expect($draft->status)->toBe('draft')->and($draft->to_warehouse_id)->toBe($this->other->id)->and($draft->lines)->toHaveCount(1);
});

it('keeps stock reports from a teller', function () {
    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/shrink')->assertForbidden();
    $this->getJson('/office-api/slow-stock')->assertForbidden();
});

<?php

use App\Domain\Inventory\InventoryService;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Stock adjustments in the new back office (release 3, part 4): the draft form, the document, its actions and the
// list, all through StockAdjustmentService, so the approval limit, photos of losses and posting rules are the same.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::find(Till::where('branch_id', $this->branches['HQ']->id)->value('warehouse_id'));
    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00', carton: ['24', '1000.00']);
    $this->pc = $this->milo->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->pc, '40', 'opening', '', null, '30.00');
    $this->owner = withMfa($this->owner->fresh());
    $this->onHand = fn () => StockLevel::where('warehouse_id', $this->hq->id)->where('product_id', $this->milo->id)->value('on_hand');
});

it('saves a draft with its lines, shows what it would do, and posts it to the ledger', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/adjustments/form')->assertOk()->json();
    expect($form['choices']['modes'])->toHaveCount(3)->and($form['values']['reason_code'])->toBe('correction');
    expect($this->getJson('/office-api/stock/units?q=milo')->assertOk()->json('results'))->toHaveCount(2);
    expect($this->getJson("/office-api/stock/unit-stock?unit={$this->pc->id}&warehouse={$this->hq->id}")->json('stock.qty'))->toEqual(40);

    $id = $this->postJson('/office-api/adjustments', ['values' => ['warehouse_id' => $this->hq->id, 'reason_code' => 'damaged', 'reference' => 'DR-7',
        'lines' => [['product_uom_id' => $this->pc->id, 'mode' => 'remove', 'qty' => '3', 'note' => 'Dropped']]]])->assertOk()->json('id');
    $doc = $this->getJson("/office-api/adjustments/{$id}")->assertOk()->json();
    expect($doc['status'])->toBe('draft')->and($doc['lines']['rows'])->toHaveCount(1)->and($doc['lines']['rows'][0][7])->toBe('37.000')
        ->and($doc['value'])->toBe(-9000)->and($doc['actions']['post'])->toBe('Post')->and($doc['actions']['edit'])->toBeTrue();

    // Edit the draft: the lines are replaced in order.
    $this->postJson("/office-api/adjustments/{$id}", ['values' => ['reason_code' => 'damaged', 'reference' => 'DR-7', 'lines' => [
        ['product_uom_id' => $this->pc->id, 'mode' => 'remove', 'qty' => '2'], ['product_uom_id' => $this->pc->id, 'mode' => 'remove', 'qty' => '1', 'reason_code' => 'theft'],
    ]]])->assertOk();
    expect(StockAdjustment::find($id)->lines()->count())->toBe(2);

    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'post'])->assertOk()->assertJsonPath('status', 'posted');
    expect(($this->onHand)())->toBe('37.000');
    $this->getJson("/office-api/adjustments/{$id}/form")->assertForbidden(); // posted: never edited, corrected by another adjustment
    $list = $this->getJson('/office-api/adjustments?status=posted')->assertOk()->json();
    expect($list['rows'][0]['value'])->toBe(-9000)->and($list['total'])->toBe(1);
    $this->get("/office-api/adjustments/{$id}/voucher?format=csv")->assertOk();
    expect($this->get('/office-api/export/adjustments?format=csv')->streamedContent())->toContain('DR-7');
});

it('needs someone else to approve an adjustment above the limit', function () {
    app(Settings::class)->set(actorFor($this->owner), 'stock.adjustment_approval_minor', 1000);
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/adjustments', ['values' => ['warehouse_id' => $this->hq->id, 'reason_code' => 'expired',
        'lines' => [['product_uom_id' => $this->pc->id, 'mode' => 'remove', 'qty' => '5']]]])->assertOk()->json('id');
    expect($this->getJson("/office-api/adjustments/{$id}")->json('over_limit'))->not->toBeNull();
    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'post'])->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, 'submit it first'));
    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'submit'])->assertOk()->assertJsonPath('actions.post', 'Approve and post');
    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'post'])->assertStatus(422);

    $gm = withMfa(staff($this->owner, 'kwame', 5, 'general_manager', ScopeType::Tenant, $this->tenant->id));
    $this->actingAs($gm, 'web');
    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'post'])->assertOk();
    expect(($this->onHand)())->toBe('35.000');
});

it('asks a photo of a loss when the company wants one, and takes one uploaded from the form', function () {
    Storage::fake('local');
    app(Settings::class)->set(actorFor($this->owner), 'stock.adjustment_photos', 'losses');
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/adjustments', ['values' => ['warehouse_id' => $this->hq->id, 'reason_code' => 'damaged',
        'lines' => [['product_uom_id' => $this->pc->id, 'mode' => 'remove', 'qty' => '1']]]])->assertOk()->json('id');
    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'submit'])->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, 'photo'));

    $path = $this->post('/office-api/stock/photos', ['photo' => UploadedFile::fake()->image('broken.jpg')], ['Accept' => 'application/json'])->assertOk()->json('path');
    expect($path)->toStartWith('stock-photos/');
    $this->postJson("/office-api/adjustments/{$id}", ['values' => ['reason_code' => 'damaged',
        'lines' => [['product_uom_id' => $this->pc->id, 'mode' => 'remove', 'qty' => '1', 'photo_path' => $path]]]])->assertOk();
    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'submit'])->assertOk();
    expect($this->getJson("/office-api/adjustments/{$id}")->json('photos'))->toHaveCount(1);
});

it('cancels with a reason, refuses a bad line with its number, and keeps adjustments from a teller', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/adjustments', ['values' => ['warehouse_id' => $this->hq->id, 'reason_code' => 'found',
        'lines' => [['product_uom_id' => $this->pc->id, 'mode' => 'add', 'qty' => '0']]]])->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_starts_with($d, 'Line 1:'));
    $id = $this->postJson('/office-api/adjustments', ['values' => ['warehouse_id' => $this->hq->id, 'reason_code' => 'found',
        'lines' => [['product_uom_id' => $this->pc->id, 'mode' => 'add', 'qty' => '2']]]])->assertOk()->json('id');
    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'cancel'])->assertStatus(422);
    $this->postJson("/office-api/adjustments/{$id}/action", ['action' => 'cancel', 'reason' => 'Typed twice'])->assertOk()->assertJsonPath('status', 'cancelled');

    $teller = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($teller, 'web');
    $this->getJson('/office-api/adjustments')->assertForbidden();
    $this->getJson("/office-api/adjustments/{$id}")->assertForbidden();
});

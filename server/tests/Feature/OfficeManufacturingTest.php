<?php

use App\Domain\Inventory\InventoryService;
use App\Models\Bom;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Models\WorkCentre;
use App\Models\WorkOrder;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Manufacturing in the new back office (part 35): work centres, bills of materials, work orders and production planning.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    enableModule($this->owner, $this->hq, 'manufacturing');
    withMfa($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->dough = makeProduct($this->owner, 'DOUGH', 'Bread dough', retail: '1.00');
    $this->flour = makeProduct($this->owner, 'FLOUR', 'Flour (kg)', retail: '1.00');
    $this->yeast = makeProduct($this->owner, 'YEAST', 'Yeast (sachet)', retail: '1.00');
    $inv = app(InventoryService::class);
    $inv->adjust(actorFor($this->owner), $this->wh, $this->flour, $this->flour->units->first(), '100', 'opening', '', null, '5.00');
    $inv->adjust(actorFor($this->owner), $this->wh, $this->yeast, $this->yeast->units->first(), '50', 'opening', '', null, '2.00');
});

function officeBom($t, ?string $centre = null): string
{
    $u = fn ($p) => $p->units->first()->id;

    return $t->postJson('/office-api/manufacturing/boms', ['product_id' => $t->dough->id, 'output_qty' => '10', 'qc_required' => false,
        'lines' => [['kind' => 'material', 'product_uom_id' => $u($t->flour), 'qty' => '5', 'scrap_pct' => '0', 'backflush' => true],
            ['kind' => 'material', 'product_uom_id' => $u($t->yeast), 'qty' => '1', 'scrap_pct' => '0', 'backflush' => true]],
        'operations' => $centre ? [['name' => 'Mix', 'work_centre_id' => $centre, 'setup_minutes' => 0, 'run_minutes' => 30, 'backflush' => true]] : []])->assertOk()->json('id');
}

it('keeps work centres and bills of materials: save, put in use, revise, stop using', function () {
    $this->actingAs($this->owner, 'web');
    $centre = $this->postJson('/office-api/manufacturing/centres', ['name' => 'Mixer', 'branch_id' => $this->hq->id, 'hourly_rate' => '60', 'hours_per_day' => '8'])->assertOk()->json('id');
    expect(WorkCentre::find($centre)->hourly_rate_minor)->toBe(6000)
        ->and($this->getJson('/office-api/manufacturing/centres')->assertOk()->json('rows.0'))->toMatchArray(['name' => 'Mixer', 'rate' => 6000, 'hours' => '8']);

    $this->postJson('/office-api/manufacturing/boms', ['product_id' => $this->dough->id, 'output_qty' => '10', 'lines' => [['kind' => 'by_product',
        'product_uom_id' => $this->flour->units->first()->id, 'qty' => '1']]])->assertStatus(422); // no material
    $id = officeBom($this, $centre);
    $form = $this->getJson("/office-api/manufacturing/boms/{$id}/form")->assertOk()->json();
    expect($form['values']['lines'])->toHaveCount(2)->and($form['values']['operations'][0])->toMatchArray(['name' => 'Mix', 'run_minutes' => '30']);
    $one = $this->getJson("/office-api/manufacturing/boms/{$id}")->assertOk()->json();
    expect($one)->toMatchArray(['status' => 'draft', 'batch' => '10'])->and($one['lines']['rows'])->toHaveCount(2)
        ->and($one['estimate']['material'])->toBe(270) // (5 × 5.00 + 1 × 2.00) / 10 per dough
        ->and($one['actions'])->toMatchArray(['edit' => true, 'activate' => true, 'make' => false]);

    $this->postJson("/office-api/manufacturing/boms/{$id}/action", ['action' => 'activate'])->assertOk();
    $this->getJson("/office-api/manufacturing/boms/{$id}/form")->assertStatus(422); // in use: a new revision instead
    $open = $this->postJson("/office-api/manufacturing/boms/{$id}/action", ['action' => 'revise'])->assertOk()->json('open');
    $rev = Bom::where('product_id', $this->dough->id)->where('revision', 2)->sole();
    expect($open)->toBe("/office/manufacturing/boms/{$rev->id}/edit")->and($rev->status)->toBe('draft');
    $list = $this->getJson('/office-api/manufacturing/boms')->assertOk()->json();
    expect($list['total'])->toBe(2)->and(collect($list['rows'])->pluck('status')->sort()->values()->all())->toBe(['active', 'draft']);
    $this->postJson("/office-api/manufacturing/centres/{$centre}/archive", ['reason' => 'Sold the mixer'])->assertOk();
    expect(WorkCentre::find($centre)->archived_at)->not->toBeNull();
});

it('makes a work order: release, issue, output, time, finish; cancels another; and prints the job card', function () {
    $this->actingAs($this->owner, 'web');
    $centre = $this->postJson('/office-api/manufacturing/centres', ['name' => 'Mixer', 'branch_id' => $this->hq->id, 'hourly_rate' => '60'])->assertOk()->json('id');
    $bom = officeBom($this, $centre);
    $this->postJson("/office-api/manufacturing/boms/{$bom}/action", ['action' => 'activate'])->assertOk();
    $form = $this->getJson("/office-api/manufacturing/orders/form?product={$this->dough->id}")->assertOk()->json();
    expect($form['values']['product_id'])->toBe($this->dough->id)->and(collect($form['warehouses'])->pluck('id'))->toContain($this->wh->id);

    $id = $this->postJson('/office-api/manufacturing/orders', ['warehouse_id' => $this->wh->id, 'product_id' => $this->dough->id, 'qty' => '20'])->assertOk()->json('id');
    $act = fn (array $b) => $this->postJson("/office-api/manufacturing/orders/{$id}/action", $b);
    expect($this->getJson("/office-api/manufacturing/orders/{$id}")->json('actions'))->toMatchArray(['release' => true, 'output' => false, 'cancel' => true]);
    $act(['action' => 'release', 'sub_assemblies' => false])->assertOk();
    $one = $this->getJson("/office-api/manufacturing/orders/{$id}")->assertOk()->json();
    expect($one['status'])->toBe('released')->and($one['materials']['rows'])->toHaveCount(2)->and($one['steps'][0]['id'])->toBe('10');

    $act(['action' => 'output', 'good' => '20'])->assertOk(); // backflushes 10 flour and 2 yeast
    expect((string) StockLevel::where('warehouse_id', $this->wh->id)->where('product_id', $this->dough->id)->value('on_hand'))->toBe('20.000')
        ->and((string) StockLevel::where('warehouse_id', $this->wh->id)->where('product_id', $this->flour->id)->value('on_hand'))->toBe('90.000');
    $flourLine = collect($this->getJson("/office-api/manufacturing/orders/{$id}")->json('issue_lines'))->firstWhere('item', 'FLOUR · FLOUR (KG)');
    $act(['action' => 'issue', 'quantities' => [$flourLine['id'] => '1']])->assertOk();
    $act(['action' => 'return', 'quantities' => [$flourLine['id'] => '1']])->assertOk();
    $act(['action' => 'issue', 'quantities' => []])->assertStatus(422);
    $act(['action' => 'time', 'seq' => 10, 'minutes' => '15', 'note' => 'Extra mixing'])->assertOk();
    $act(['action' => 'finish'])->assertOk();
    $done = $this->getJson("/office-api/manufacturing/orders/{$id}")->json();
    expect($done)->toMatchArray(['status' => 'done', 'done' => '20'])->and($done['costs'])->not->toBe([]);

    $other = $this->postJson('/office-api/manufacturing/orders', ['warehouse_id' => $this->wh->id, 'product_id' => $this->dough->id, 'qty' => '5'])->assertOk()->json('id');
    $this->postJson("/office-api/manufacturing/orders/{$other}/action", ['action' => 'cancel'])->assertStatus(422);
    $this->postJson("/office-api/manufacturing/orders/{$other}/action", ['action' => 'cancel', 'reason' => 'Raised twice'])->assertOk();
    expect(WorkOrder::find($other)->status)->toBe('cancelled');
    expect($this->getJson('/office-api/manufacturing/orders')->json('total'))->toBe(0)->and($this->getJson('/office-api/manufacturing/orders?open=0')->json('total'))->toBe(2);
    $this->get("/office-api/manufacturing/orders/{$id}/card")->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('plans production from a reorder level and raises the suggested work orders as drafts', function () {
    $this->actingAs($this->owner, 'web');
    $bom = officeBom($this);
    $this->postJson("/office-api/manufacturing/boms/{$bom}/action", ['action' => 'activate'])->assertOk();
    DB::table('reorder_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh->id, 'product_id' => $this->dough->id,
        'min_qty' => 30, 'max_qty' => 40, 'origin_node' => 'test', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $plan = $this->getJson("/office-api/manufacturing/planning?warehouse={$this->wh->id}")->assertOk()->json();
    expect($plan['warehouse'])->toBe($this->wh->id)->and($plan['can_make'])->toBeTrue()
        ->and(collect($plan['make'])->pluck('suggested', 'sku')->all())->toBe(['DOUGH' => '40.000'])
        ->and(collect($plan['buy'])->pluck('sku')->all())->toBe([]); // 20 flour and 4 yeast are in stock
    $this->postJson('/office-api/manufacturing/planning', ['warehouse' => $this->wh->id, 'product_id' => $this->flour->id])->assertStatus(422);
    $this->postJson('/office-api/manufacturing/planning', ['warehouse' => $this->wh->id])->assertOk();
    $wo = WorkOrder::where('product_id', $this->dough->id)->sole();
    expect($wo->status)->toBe('draft')->and((string) $wo->qty_planned)->toBe('40.000');

    $cashier = staff($this->owner, 'esi', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    $this->actingAs($cashier, 'web');
    $this->getJson('/office-api/manufacturing/orders')->assertForbidden();
    $this->getJson('/office-api/manufacturing/boms')->assertForbidden();
    $this->postJson('/office-api/manufacturing/planning', ['warehouse' => $this->wh->id])->assertForbidden();
});

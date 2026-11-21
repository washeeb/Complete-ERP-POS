<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\BinService;
use App\Domain\Inventory\ExpiryService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\SupplierService;
use App\Models\Account;
use App\Models\Bin;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Models\Waybill;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Returns to suppliers, Waybills, Bins and My day in the new back office (part 17).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $this->milk = makeProduct($this->owner, 'MILK', 'Fresh milk 1L', retail: '15.00')->fresh(['units']);
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->milk, ['track_batches' => true]);
    $this->milk = $this->milk->fresh(['units']);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milk, $this->milk->units->first(), '30', 'opening', '', null, '10.00', 'M-1', now()->addDays(10)->toDateString());
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'DAIRY', 'name' => 'Sample Dairy', 'return_days_before_expiry' => 0]);
    $this->gm = withMfa(staff($this->owner, 'gm', 5, 'general_manager'));
    withMfa($this->owner);
});

it('approves, settles and sends back a return to a supplier with its waybill', function () {
    $batch = DB::table('stock_batches')->where('batch_no', 'M-1')->value('id');
    $r = app(ExpiryService::class)->prepareReturn(actorFor($this->owner), ["{$this->hq->id}|{$batch}"], $this->supplier->id, 'refund', 'near_expiry', 'Collected by the rep');
    $this->actingAs($this->owner, 'web');

    $list = $this->getJson('/office-api/supplier-returns')->assertOk()->json();
    expect($list)->toMatchArray(['total' => 1, 'waiting' => 1])->and($list['rows'][0])->toMatchArray(['number' => $r->number, 'supplier' => 'SAMPLE DAIRY', 'status' => 'submitted']);
    $doc = $this->getJson("/office-api/supplier-returns/{$r->id}")->assertOk()->json();
    expect($doc['actions'])->toMatchArray(['post' => true, 'cancel' => true, 'settle' => null])->and($doc['lines'][0]['batch'])->toBe('M-1')
        ->and($doc['actions']['waybill'])->toBe("/office/waybills/new?kind=supplier_return&source={$r->id}");
    $this->postJson("/office-api/supplier-returns/{$r->id}/post")->assertStatus(422); // never one's own

    $this->actingAs($this->gm, 'web');
    $doc = $this->postJson("/office-api/supplier-returns/{$r->id}/post")->assertOk()->json('doc');
    expect($doc['status'])->toBe('posted')->and($doc['actions']['settle']['refund'])->toBe(30000)->and($doc['actions']['settle']['accounts'])->not->toBeEmpty();
    $this->postJson("/office-api/supplier-returns/{$r->id}/settle", ['reference' => 'MOMO-77', 'refund' => '280', 'account_id' => Account::where('system_key', 'bank')->value('id')])->assertOk();
    expect(SupplierReturn::find($r->id)->settlement_ref)->toBe('MOMO-77');

    // The waybill for it: prepared from the document, dispatched, delivered with the signed copy.
    Storage::fake('local');
    $form = $this->getJson('/office-api/waybills/form?kind=supplier_return')->assertOk()->json();
    expect(collect($form['kinds'])->pluck('id'))->toContain('supplier_return')->and(collect($form['sources'])->pluck('id'))->toContain($r->id);
    $id = $this->postJson('/office-api/waybills', ['kind' => 'supplier_return', 'source_id' => $r->id, 'vehicle_no' => 'GR 1234-24', 'packages' => 2])->assertOk()->json('id');
    $w = $this->getJson("/office-api/waybills/{$id}")->assertOk()->json();
    expect($w)->toMatchArray(['status' => 'prepared', 'source' => $r->number])->and($w['lines'])->not->toBeEmpty()->and($w['actions']['dispatch'])->not->toBeNull();
    $this->postJson("/office-api/waybills/{$id}/dispatch", ['driver_name' => 'Kojo'])->assertOk()->assertJsonPath('waybill.status', 'dispatched');
    $this->post("/office-api/waybills/{$id}/deliver", ['received_by' => 'Dairy rep', 'pod' => UploadedFile::fake()->create('signed.pdf', 30, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('waybill.status', 'delivered');
    Storage::disk('local')->assertExists(Waybill::find($id)->pod_path);
    expect($this->getJson('/office-api/waybills?q=GR 1234')->json('total'))->toBe(1)->and($this->getJson("/office-api/supplier-returns/{$r->id}")->json('actions.waybill'))->toBe("/office/waybills/{$id}");
});

it('keeps bins: the next code, moving stock between them, and archiving an empty one', function () {
    app(BinService::class)->setUseBins(actorFor($this->owner), $this->hq, true);
    $this->actingAs($this->owner, 'web');

    $help = $this->getJson("/office-api/bins/help?warehouse={$this->hq->id}")->assertOk()->json();
    $this->postJson('/office-api/bins', ['warehouse_id' => $this->hq->id, 'code' => $help['next_code'], 'pick_order' => 10])->assertOk();
    $this->postJson('/office-api/bins', ['warehouse_id' => $this->hq->id, 'code' => 'bad code!'])->assertStatus(422);
    $shelf = Bin::where('kind', 'storage')->sole();
    $receiving = Bin::where('kind', 'receiving')->sole();

    $rows = collect($this->getJson("/office-api/bins?warehouse={$this->hq->id}")->assertOk()->json('rows'));
    expect($rows->firstWhere('id', $receiving->id)['holds'])->toContain('FRESH MILK')->and($rows->firstWhere('id', $shelf->id)['holds'])->toBe('empty');
    $units = $this->getJson("/office-api/bins/help?warehouse={$this->hq->id}&except={$receiving->id}&product={$this->milk->id}")->json();
    expect(collect($units['bins'])->pluck('id'))->toContain($shelf->id)->not->toContain($receiving->id);
    $this->postJson("/office-api/bins/{$receiving->id}/move", ['product_id' => $this->milk->id, 'product_uom_id' => $units['units'][0]['id'], 'qty' => '3', 'to_bin_id' => $shelf->id])->assertOk();
    expect((float) DB::table('stock_bin_levels')->where('bin_id', $shelf->id)->sum('on_hand'))->toBe(3.0);

    $this->postJson("/office-api/bins/{$shelf->id}/archive", ['reason' => 'Shelf removed'])->assertStatus(422); // still holds stock
    $this->postJson('/office-api/bins', ['warehouse_id' => $this->hq->id, 'code' => 'Z-99'])->assertOk();
    $spare = Bin::where('code', 'Z-99')->sole();
    $this->postJson("/office-api/bins/{$spare->id}/archive", ['reason' => 'Shelf removed'])->assertOk();
    expect($spare->fresh()->archived_at)->not->toBeNull()->and(collect($this->getJson('/office-api/bins?archived=1')->json('rows'))->pluck('code')->all())->toBe(['Z-99']);
});

it('shows my day for a store and starts today\'s cycle count', function () {
    app(Settings::class)->set(actorFor($this->owner), 'stock.cycle_count_per_day', 5);
    $keeper = withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($keeper, 'web');

    $day = $this->getJson('/office-api/my-day')->assertOk()->json();
    expect($day['warehouse'])->toBe($this->hq->id)->and(collect($day['cards'])->firstWhere('label', 'Expiring in 30 days')['value'])->toBe(1)
        ->and($day['cycle']['on'])->toBeTrue()->and($day['cycle']['due'])->not->toBeEmpty();
    $other = Warehouse::where('branch_id', $this->b['BA']->id)->value('id');
    $this->postJson('/office-api/my-day/start-cycle', ['warehouse' => $other])->assertStatus(422); // not a store this person sees
    $open = $this->postJson('/office-api/my-day/start-cycle', ['warehouse' => $this->hq->id])->assertOk()->json('open');
    expect($open)->toBe('/office/counts/'.StockCount::sole()->id)->and($this->getJson('/office-api/my-day')->json('cycle.started.number'))->toBe(StockCount::sole()->number);
});

<?php

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiReply;
use App\Domain\Purchasing\SupplierService;
use App\Models\Account;
use App\Models\DirectPurchase;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

// Direct purchases in the new back office (release 5, part 4): the list, one purchase with the checks before posting,
// the form with the invoice's pages, the AI scan and a product added on the spot, and Send for approval, Post, Cancel.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.direct_approval_minor', 100000000);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'ECP', 'name' => 'Sample Wholesale Pharmacy']);
    $this->med = makeProduct($this->owner, 'MED1', 'Multivitamin Caps', retail: '300.00')->fresh(['units']);
    $this->base = $this->med->units->firstWhere('is_base', true);
    $this->cash = Account::where('type', 'asset')->where('is_header', false)->whereNull('control')->first();
    $this->values = fn (array $v = [], array $line = []) => ['values' => $v + ['supplier_id' => $this->supplier->id, 'invoice_no' => 'PSI-1', 'invoice_date' => now()->toDateString(),
        'warehouse_id' => $this->hq->id, 'prices_include_tax' => false, 'printed_total' => '', 'attachments' => [], 'settlement' => 'paid', 'method' => 'cash',
        'account_id' => $this->cash->id, 'reference' => '', 'due_on' => '', 'price_list_id' => '', 'note' => '',
        'lines' => [$line + ['product_uom_id' => $this->base->id, 'qty' => '5', 'unit_price' => '195.36', 'tax_group_id' => '', 'batch_no' => '', 'expires_on' => '',
            'supplier_sku' => '', 'sell_uom_id' => '', 'new_price' => '']]]];
    $this->onHand = fn () => (string) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->med->id)->value('on_hand');
});

it('enters an invoice with its page, shows the checks, posts it and lists it', function () {
    Storage::fake('local');
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/direct-purchases/form')->assertOk()->json();
    expect($form['values']['warehouse_id'])->not->toBe('')->and($form['choices']['accounts'])->not->toBeEmpty();
    expect($this->getJson('/office-api/direct-purchases/units?q=multi')->json('results.0.id'))->toBe($this->base->id)
        ->and($this->getJson("/office-api/direct-purchases/unit?id={$this->base->id}")->json('sell_units.0.id'))->toBe($this->base->id);

    $paths = $this->post('/office-api/direct-purchases/upload', ['files' => [UploadedFile::fake()->image('page1.jpg')]], ['Accept' => 'application/json'])->assertOk()->json('paths');
    $id = $this->postJson('/office-api/direct-purchases', ($this->values)(['attachments' => $paths, 'printed_total' => '1000'], ['new_price' => '320']))->assertOk()->json('id');
    $this->postJson('/office-api/direct-purchases', ($this->values)(['invoice_no' => 'PSI-2', 'attachments' => ['direct-purchases/someone-elses.pdf']]))->assertStatus(422);
    $this->postJson('/office-api/direct-purchases', ($this->values)())->assertStatus(422)->assertJsonPath('error', 'duplicate_invoice');

    $show = $this->getJson("/office-api/direct-purchases/{$id}")->assertOk()->json();
    expect($show['status'])->toBe('draft')->and($show['pages'])->toBe(1)->and($show['warnings'][0])->toContain('the invoice says')
        ->and($show['lines'][0])->toMatchArray(['unit_price' => 19536, 'new_price' => 32000])->and($show['actions']['post'])->not->toBeNull();
    $this->get("/office-api/direct-purchases/{$id}/page/1")->assertOk();
    $this->get("/office-api/direct-purchases/{$id}/page/2")->assertNotFound();

    $edit = $this->getJson("/office-api/direct-purchases/{$id}/form")->assertOk()->json('values');
    expect($edit['lines'][0])->toMatchArray(['product_uom_id' => $this->base->id, 'unit_price' => '195.36', 'new_price' => '320.00'])->and($edit['attachments'])->toBe($paths);
    $this->postJson("/office-api/direct-purchases/{$id}", ['values' => ['printed_total' => ''] + $edit])->assertOk(); // its own page is kept

    $posted = $this->postJson("/office-api/direct-purchases/{$id}/action", ['action' => 'post'])->assertOk()->json();
    expect($posted['status'])->toBe('posted')->and($posted['goods_receipt']['open'])->toStartWith('/office/goods-received/')->and($posted['payment'])->not->toBeNull()
        ->and($posted['actions']['edit'])->toBeFalse()->and(($this->onHand)())->toBe('5.000');

    $list = $this->getJson('/office-api/direct-purchases?q=PSI-1&settlement=paid')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['posted'])->toBe(117216)->and($list['rows'][0]['status'])->toBe('posted');
    $csv = $this->get('/office-api/export/direct-purchases?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('PSI-1');
});

it('sends one above the limit for approval; someone else posts it; cancels another', function () {
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.direct_approval_minor', 50000);
    $store = staff($this->owner, 'storeman', 2, 'storekeeper', ScopeType::Tenant, $this->tenant->id);
    $gm = withMfa(staff($this->owner, 'gm', 5, 'branch_manager', ScopeType::Tenant, $this->tenant->id));
    $this->actingAs($store, 'web');
    $id = $this->postJson('/office-api/direct-purchases', ($this->values)())->assertOk()->json('id');
    $show = $this->getJson("/office-api/direct-purchases/{$id}")->json();
    expect($show['actions']['submit'])->toBeTrue()->and($show['actions']['post'])->toBeNull()->and($show['actions']['why_not'])->toContain('approval');
    expect($this->postJson("/office-api/direct-purchases/{$id}/action", ['action' => 'submit'])->assertOk()->json('status'))->toBe('submitted');
    $this->postJson("/office-api/direct-purchases/{$id}/action", ['action' => 'post'])->assertStatus(422);

    $this->actingAs($gm, 'web');
    expect($this->getJson('/office-api/direct-purchases')->json('waiting'))->toBe(1);
    $edit = $this->getJson("/office-api/direct-purchases/{$id}/form")->assertOk()->json(); // an approver may correct it while it waits
    expect($edit['correcting'])->toBeTrue();
    expect($this->postJson("/office-api/direct-purchases/{$id}/action", ['action' => 'post'])->assertOk()->json('status'))->toBe('posted');

    $this->actingAs($store, 'web');
    $other = $this->postJson('/office-api/direct-purchases', ($this->values)(['invoice_no' => 'PSI-9']))->json('id');
    $this->postJson("/office-api/direct-purchases/{$other}/action", ['action' => 'cancel'])->assertStatus(422)->assertJsonValidationErrors('reason');
    expect($this->postJson("/office-api/direct-purchases/{$other}/action", ['action' => 'cancel', 'reason' => 'Typed twice'])->json('status'))->toBe('cancelled');
});

it('reads a scanned invoice into the form and adds a missing product with its pack', function () {
    Storage::fake('local');
    SupplierProduct::create(['supplier_id' => $this->supplier->id, 'product_id' => $this->med->id, 'product_uom_id' => $this->base->id, 'supplier_sku' => 'OTC-11598']);
    AiGateway::fake(new FakeAiProvider([new AiReply(json_encode(['supplier_name' => 'Sample Wholesale Pharmacy', 'invoice_number' => 'PSI-586040', 'invoice_date' => now()->toDateString(),
        'total' => 1099.08, 'lines' => [
            ['sku' => 'OTC-11598', 'description' => 'WELLMAN', 'qty' => 5, 'lot' => 'WLM2605', 'expiry_date' => '2028-07-31', 'unit_price' => 195.36],
            ['sku' => 'NEW-1', 'description' => 'KY LUBRICATING JELLY 82G', 'qty' => 2, 'unit_price' => 61.14]]]))]));
    $this->actingAs($this->owner, 'web');
    $form = $this->post('/office-api/direct-purchases/scan', ['files' => [UploadedFile::fake()->image('inv.png')]], ['Accept' => 'application/json'])->assertOk()->json();
    AiGateway::fake(null);
    expect($form)->toMatchArray(['supplier_id' => $this->supplier->id, 'invoice_no' => 'PSI-586040', 'printed_total' => '1099.08'])
        ->and($form['lines'][0])->toMatchArray(['product_uom_id' => $this->base->id, 'batch_no' => 'WLM2605', 'qty' => '5'])
        ->and($form['lines'][1])->toMatchArray(['product_uom_id' => '', 'read_as' => 'KY LUBRICATING JELLY 82G'])->and($form['attachments'])->toHaveCount(1);

    $pc = Uom::firstOrCreate(['code' => 'PC'], ['name' => 'Piece', 'decimals' => 0]);
    $box = Uom::firstOrCreate(['code' => 'BOX'], ['name' => 'Box', 'decimals' => 0]);
    $line = $this->postJson('/office-api/direct-purchases/add-product', ['name' => 'KY Jelly 82g', 'base_uom_id' => $pc->id, 'pack_uom_id' => $box->id, 'pack_factor' => 12,
        'tax_group_id' => TaxGroup::first()->id, 'track_batches' => false])->assertOk()->json();
    expect($line['factor'])->toEqual(12)->and($line['label'])->toContain('KY')->and($line['sell_units'])->toHaveCount(2);
});

it('lets a teller see nothing', function () {
    $teller = staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/direct-purchases')->assertForbidden();
    expect(DirectPurchase::count())->toBe(0);
});

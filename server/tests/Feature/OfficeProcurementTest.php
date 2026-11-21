<?php

use App\Domain\Purchasing\SupplierService;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Purchase requests, Requests for quotation and Contracts and blanket orders in the new back office (part 18).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $save = fn (string $name) => app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => $name]);
    [$this->sa, $this->sb] = [$save('Kofi Wholesale'), $save('Accra Traders')];
    $this->buyer = withMfa(staff($this->owner, 'ama', 2, 'purchasing_officer'));
    withMfa($this->owner);
});

it('asks for stock, then a buyer orders it or asks for quotes', function () {
    $keeper = withMfa(staff($this->owner, 'yaw', 3, 'stock_controller', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($keeper, 'web');
    $form = $this->getJson('/office-api/purchase-requests/form')->assertOk()->json();
    expect(collect($form['warehouses'])->pluck('id'))->toContain($this->hq->id);
    expect(collect($this->getJson('/office-api/procurement/units?q=milo')->assertOk()->json('results'))->pluck('id'))->toContain($this->ctn->id);
    $this->postJson('/office-api/purchase-requests', ['warehouse_id' => $this->hq->id, 'lines' => []])->assertStatus(422);
    $id = $this->postJson('/office-api/purchase-requests', ['warehouse_id' => $this->hq->id, 'note' => 'Running low',
        'lines' => [['kind' => 'stock', 'product_uom_id' => $this->ctn->id, 'qty' => '6']]])->assertOk()->json('id');
    $r = $this->getJson("/office-api/purchase-requests/{$id}")->assertOk()->json();
    expect($r)->toMatchArray(['status' => 'requested', 'by' => 'Yaw'])->and($r['lines'][0]['qty'])->toContain('6')
        ->and($r['actions'])->toMatchArray(['order' => null, 'reject' => false, 'cancel' => true]); // the person who asked may cancel; a buyer orders

    $this->actingAs($this->buyer, 'web');
    $r = $this->getJson("/office-api/purchase-requests/{$id}")->json();
    expect($r['actions']['order']['suppliers'])->not->toBeEmpty()->and($r['actions']['quotes'])->not->toBeNull()->and($r['actions']['cancel'])->toBeFalse();
    $quotes = $this->postJson("/office-api/purchase-requests/{$id}/action", ['action' => 'quotes', 'supplier_ids' => [$this->sa->id, $this->sb->id],
        'closes_at' => now()->addDays(2)->format('Y-m-d H:i')])->assertOk()->json();
    $rfq = Rfq::sole();
    expect($quotes['open'])->toBe("/office/rfqs/{$rfq->id}")->and(PurchaseRequest::find($id)->status)->toBe('quoting');
    expect($this->getJson('/office-api/purchase-requests?status=quoting')->json('total'))->toBe(1);

    // A second request, ordered straight away: a draft purchase order to that supplier.
    $this->actingAs($keeper, 'web');
    $two = $this->postJson('/office-api/purchase-requests', ['warehouse_id' => $this->hq->id, 'lines' => [['kind' => 'stock', 'product_uom_id' => $this->ctn->id, 'qty' => '2']]])->json('id');
    $this->actingAs($this->buyer, 'web');
    $open = $this->postJson("/office-api/purchase-requests/{$two}/action", ['action' => 'order', 'supplier_id' => $this->sa->id])->assertOk()->json('open');
    expect($open)->toBe('/office/purchase-orders/'.PurchaseOrder::sole()->id.'/edit')->and(PurchaseRequest::find($two)->status)->toBe('ordered');
});

it('runs a request for quotation: sealed quotes, close, compare, award into a draft order', function () {
    $this->actingAs($this->buyer, 'web');
    expect($this->getJson('/office-api/rfqs/form')->assertOk()->json('suppliers'))->not->toBeEmpty();
    $id = $this->postJson('/office-api/rfqs', ['title' => 'Milo for December', 'warehouse_id' => $this->hq->id, 'closes_at' => now()->addDays(2)->format('Y-m-d H:i'),
        'supplier_ids' => [$this->sa->id], 'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '10']]])->assertOk()->json('id');
    $rfq = $this->getJson("/office-api/rfqs/{$id}")->assertOk()->json();
    expect($rfq['unsealed'])->toBeFalse()->and($rfq['comparison'])->toBeNull()->and($rfq['actions']['send']['channels'])->not->toBeEmpty()
        ->and($rfq['invitations'][0]['link'])->toContain('http');

    $this->postJson("/office-api/rfqs/{$id}/action", ['action' => 'invite', 'supplier_id' => $this->sb->id])->assertOk();
    $rfq = $this->getJson("/office-api/rfqs/{$id}")->json();
    expect($rfq['invitations'])->toHaveCount(2);
    $line = $rfq['lines'][0]['id'];
    [$ia, $ib] = [collect($rfq['invitations'])->firstWhere('supplier', 'KOFI WHOLESALE')['id'], collect($rfq['invitations'])->firstWhere('supplier', 'ACCRA TRADERS')['id']];
    $this->postJson("/office-api/rfqs/{$id}/action", ['action' => 'quote', 'invitation' => $ia, 'lines' => [$line => ['price' => '300']], 'delivery_days' => 2])->assertOk();
    $this->postJson("/office-api/rfqs/{$id}/action", ['action' => 'quote', 'invitation' => $ib, 'lines' => [$line => ['price' => '320']], 'delivery_days' => 5])->assertOk();
    expect($this->getJson("/office-api/rfqs/{$id}")->json('comparison'))->toBeNull(); // still sealed

    $after = $this->postJson("/office-api/rfqs/{$id}/action", ['action' => 'close'])->assertOk()->json('rfq');
    expect($after['unsealed'])->toBeTrue()->and($after['comparison']['lines'][0]['cells'])->toHaveCount(2)
        ->and($after['actions']['award']['lines'][0]['suggested'])->toBe($ia);
    $this->postJson("/office-api/rfqs/{$id}/action", ['action' => 'award', 'awards' => [$line => $ib]])->assertStatus(422); // not the cheapest: needs the reason
    $open = $this->postJson("/office-api/rfqs/{$id}/action", ['action' => 'award', 'awards' => [$line => $ia]])->assertOk()->json('open');
    expect($open)->toBe('/office/purchase-orders/'.PurchaseOrder::sole()->id.'/edit')->and(Rfq::find($id)->status)->toBe('awarded');
});

it('keeps a contract: draft with its signed copy, put in force by someone else, ordered against, closed', function () {
    Storage::fake('local');
    $this->actingAs($this->buyer, 'web');
    $form = $this->getJson('/office-api/agreements/form')->assertOk()->json();
    expect($form['values']['kind'])->toBe('contract')->and($form['kinds'])->toHaveCount(2);
    $lines = json_encode([['kind' => 'stock', 'product_uom_id' => $this->ctn->id, 'unit_cost' => '290', 'note' => 'Price held']]);
    $id = $this->post('/office-api/agreements', ['supplier_id' => $this->sa->id, 'kind' => 'contract', 'title' => 'Milo prices 2027', 'starts_on' => now()->toDateString(),
        'ends_on' => now()->addYear()->toDateString(), 'lines' => $lines, 'file' => UploadedFile::fake()->create('signed.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertOk()->json('id');
    $a = PurchaseAgreement::find($id);
    Storage::disk('local')->assertExists($a->file_path);
    $doc = $this->getJson("/office-api/agreements/{$id}")->assertOk()->json();
    expect($doc)->toMatchArray(['status' => 'draft', 'title' => 'Milo prices 2027'])->and($doc['file']['name'])->toBe('signed.pdf')
        ->and($doc['lines'][0])->toMatchArray(['price' => 29000, 'left' => 'No limit'])->and($doc['actions']['edit'])->toBeTrue();
    expect($this->getJson("/office-api/agreements/{$id}/form")->json('values.lines.0.unit_cost'))->toBe('290.00');

    $gm = withMfa(staff($this->owner, 'gm', 5, 'general_manager'));
    $this->actingAs($gm, 'web');
    $this->postJson("/office-api/agreements/{$id}/action", ['action' => 'activate'])->assertOk()->assertJsonPath('agreement.status', 'active');
    $line = $a->lines()->value('id');
    $open = $this->postJson("/office-api/agreements/{$id}/action", ['action' => 'release', 'warehouse_id' => $this->hq->id, 'qtys' => [$line => '3']])->assertOk()->json('open');
    $po = PurchaseOrder::sole();
    expect($open)->toBe("/office/purchase-orders/{$po->id}/edit")->and($this->getJson("/office-api/agreements/{$id}")->json('orders.0.number'))->toBe($po->number);
    $this->postJson("/office-api/agreements/{$id}/action", ['action' => 'close', 'reason' => 'Renegotiated'])->assertOk()->assertJsonPath('agreement.status', 'closed');
    expect($this->getJson('/office-api/agreements?q=milo')->json('total'))->toBe(1);
});

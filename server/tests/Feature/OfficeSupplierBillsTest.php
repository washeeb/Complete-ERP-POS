<?php

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiReply;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Purchasing\SupplierService;
use App\Models\Account;
use App\Models\AiDocument;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

// Supplier bills in the new back office (release 6, part 2): the list, Enter a bill (goods received, other charges),
// approval of a bill above the order price, Scan an invoice, and an invoice the supplier sent, through BillService,
// InvoiceScanner and SupplierPortal.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $ctn = $milo->units->firstWhere('is_base', false);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Nestle Ghana']);
    $buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $gm = staff($this->owner, 'kojo', 5, 'general_manager');
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['HQ']->id);
    $svc = app(PurchaseOrderService::class);
    $po = $svc->saveDraft(actorFor($buyer), new PurchaseOrder, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $ctn->id, 'qty' => '10', 'unit_cost' => '40']]]);
    $svc->submit(actorFor($buyer), $po);
    $po = $svc->approve(actorFor($gm), $po->fresh());
    $this->grn = app(GoodsReceiptService::class)->receive(actorFor($keeper), $po, ['supplier_ref' => 'DN-1', 'lines' => [['purchase_order_line_id' => $po->lines()->first()->id, 'qty' => '10']]]);
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $this->fm = staff($this->owner, 'kwame', 5, 'finance_manager');
    $this->rent = Account::where('code', '6200')->value('id');
    $this->values = fn (array $v = []) => $v + ['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'NG-5501', 'branch_id' => $this->b['HQ']->id,
        'bill_date' => now()->toDateString(), 'due_on' => '', 'note' => '', 'stock_lines' => [], 'order_lines' => [], 'expense_lines' => []];
});

it('enters a bill for goods received above the order price; someone else approves it', function () {
    $this->actingAs($this->accountant, 'web');
    $billable = $this->getJson("/office-api/supplier-bills/billable?supplier={$this->supplier->id}")->assertOk()->json();
    expect($billable['grns'][0]['id'])->toBe($this->grn->id)->and($billable['stock'][0])->toMatchArray(['qty' => '10', 'cost' => '40.00', 'grn' => $this->grn->id]);
    $line = $billable['stock'][0]['id'];

    $this->postJson('/office-api/supplier-bills', ['values' => ($this->values)(['stock_lines' => [['goods_receipt_line_id' => $line, 'qty' => '11', 'unit_cost' => '40']]])])
        ->assertStatus(422)->assertJsonPath('detail', fn ($m) => str_contains($m, 'only 10'));
    $id = $this->postJson('/office-api/supplier-bills', ['values' => ($this->values)(['stock_lines' => [['goods_receipt_line_id' => $line, 'qty' => '10', 'unit_cost' => '41']]])])
        ->assertOk()->json('id');
    $show = $this->getJson("/office-api/supplier-bills/{$id}")->assertOk()->json();
    expect($show)->toMatchArray(['status' => 'pending_approval', 'variance' => 1000])->and($show['lines'][0]['order_price'])->toBe(4000)
        ->and($show['actions']['approve'])->toBeFalse(); // not one's own (and an accountant enters, does not approve)

    $list = $this->getJson('/office-api/supplier-bills?q=NG-5501')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['waiting'])->toBe(1)->and($list['rows'][0]['variance'])->toBe(1000);
    $csv = $this->get('/office-api/export/supplier-bills?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('NG-5501');

    $this->actingAs($this->fm, 'web');
    $after = $this->postJson("/office-api/supplier-bills/{$id}/action", ['action' => 'approve', 'reason' => 'Price rise notified by phone'])->assertOk()->json();
    expect($after)->toMatchArray(['status' => 'posted', 'variance_reason' => 'Price rise notified by phone']);
});

it('charges rent straight to an account, and rejects a bill with a reason', function () {
    $this->actingAs($this->accountant, 'web');
    expect($this->getJson('/office-api/supplier-bills/form')->json('can_add_supplier'))->toBeFalse();
    $this->postJson('/office-api/supplier-bills/add-supplier', ['name' => 'Osu Properties'])->assertForbidden(); // suppliers are added by those who manage them
    $landlord = $this->actingAs($this->owner, 'web')->postJson('/office-api/supplier-bills/add-supplier', ['name' => 'Osu Properties'])->assertOk()->json('id');
    $this->actingAs($this->accountant, 'web');
    $rent = $this->postJson('/office-api/supplier-bills', ['values' => ($this->values)(['supplier_id' => $landlord, 'supplier_invoice_no' => 'R-OCT',
        'expense_lines' => [['account_id' => $this->rent, 'description' => 'October rent', 'amount' => '3000', 'tax_group_id' => '', 'department_id' => '', 'project_id' => '', 'maintenance_order_id' => '']]])])
        ->assertOk()->json('id');
    expect(SupplierBill::find($rent))->status->toBe('posted')->total_minor->toBe(300000);

    $held = $this->postJson('/office-api/supplier-bills', ['values' => ($this->values)(['supplier_invoice_no' => 'NG-9',
        'stock_lines' => [['goods_receipt_line_id' => $this->grn->lines[0]->id, 'qty' => '5', 'unit_cost' => '50']]])])->json('id');
    $this->actingAs($this->fm, 'web');
    $this->postJson("/office-api/supplier-bills/{$held}/action", ['action' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('reason');
    expect($this->postJson("/office-api/supplier-bills/{$held}/action", ['action' => 'reject', 'reason' => 'Wrong price'])->json('status'))->toBe('rejected');
});

it('fills the form from a scanned invoice and from one the supplier sent, and marks each as used', function () {
    Storage::fake('local');
    AiGateway::fake(new FakeAiProvider([new AiReply(json_encode(['supplier_name' => 'Nestle Ghana', 'invoice_number' => 'NG-77', 'invoice_date' => now()->toDateString(),
        'lines' => [['description' => 'Milo 400g carton', 'qty' => 10, 'unit_price' => 40, 'line_total' => 400]], 'subtotal' => 400, 'total' => 480]))]));
    $this->actingAs($this->accountant, 'web');
    $scan = $this->post('/office-api/supplier-bills/scan', ['branch_id' => $this->b['HQ']->id, 'file' => UploadedFile::fake()->image('inv.png')], ['Accept' => 'application/json'])
        ->assertOk()->json('scan');
    AiGateway::fake(null);
    $form = $this->getJson("/office-api/supplier-bills/form?scan={$scan}")->assertOk()->json();
    expect($form['values'])->toMatchArray(['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'NG-77'])->and($form['scan'])->toBe($scan)->and($form['notice'])->toContain('scanned');
    $id = $this->postJson('/office-api/supplier-bills', ['values' => $form['values'], 'scan' => $scan])->assertOk()->json('id');
    expect(AiDocument::find($scan))->status->toBe('used')->supplier_bill_id->toBe($id);

    $sent = app(SupplierPortal::class)->submitInvoice($this->supplier, ['invoice_no' => 'NG-88', 'amount' => '100', 'invoice_date' => now()->toDateString()], null, null, 'Mr Mensah');
    $form = $this->getJson("/office-api/supplier-bills/form?invoice={$sent->id}")->assertOk()->json();
    expect($form['values']['supplier_invoice_no'])->toBe('NG-88')->and($form['invoice'])->toBe($sent->id);
    $this->postJson('/office-api/supplier-bills', ['values' => ['expense_lines' => [['account_id' => $this->rent, 'description' => 'Delivery', 'amount' => '100', 'tax_group_id' => '',
        'department_id' => '', 'project_id' => '', 'maintenance_order_id' => '']]] + $form['values'], 'invoice' => $sent->id])->assertOk();
    expect(SupplierInvoice::find($sent->id)->status)->toBe('entered');
});

it('lets a teller see nothing', function () {
    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/supplier-bills')->assertForbidden();
});

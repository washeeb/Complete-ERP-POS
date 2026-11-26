<?php

use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Purchasing\SupplierService;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Invoices from suppliers and Consignment to pay in the new back office (release 5, part 5): the list (To enter by
// default), Mark entered, Turn back, Enter as bill on the current form, and Settle, through SupplierPortal and
// ConsignmentService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Ernest Chemists']);
    $this->send = fn (string $no, string $amount = '1200') => app(SupplierPortal::class)->submitInvoice($this->supplier, ['invoice_no' => $no, 'amount' => $amount,
        'invoice_date' => now()->toDateString()], null, null, 'Mr Mensah');
});

it('lists invoices to enter, turns one back and marks one entered', function () {
    $a = ($this->send)('EC-100');
    $b = ($this->send)('EC-101', '800');
    $this->actingAs($this->owner, 'web');
    $list = $this->getJson('/office-api/supplier-invoices')->assertOk()->json();
    expect($list['total'])->toBe(2)->and($list['waiting'])->toBe(2)->and($list['amount'])->toBe(200000)
        ->and(collect($list['rows'])->firstWhere('invoice_no', 'EC-100'))->toMatchArray(['supplier' => 'ERNEST CHEMISTS', 'amount' => 120000, 'sent_by' => 'Mr Mensah', 'status' => 'received'])
        ->and($list['rows'][0]['actions']['enter'])->toContain('/office/supplier-bills/new?invoice=');
    expect($this->getJson('/office-api/supplier-invoices?q=EC-101')->json('total'))->toBe(1);

    $this->postJson("/office-api/supplier-invoices/{$a->id}/action", ['action' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $r = $this->postJson("/office-api/supplier-invoices/{$a->id}/action", ['action' => 'reject', 'reason' => 'Prices differ from the order'])->assertOk()->json('row');
    expect($r)->toMatchArray(['status' => 'rejected', 'note' => 'Prices differ from the order'])->and($r['actions']['reject'])->toBeFalse();
    $this->postJson("/office-api/supplier-invoices/{$a->id}/action", ['action' => 'reject', 'reason' => 'Again'])->assertStatus(422);

    expect($this->getJson("/office-api/supplier-invoices/{$b->id}/bills")->assertOk()->json('bills'))->toBe([]);
    expect($this->postJson("/office-api/supplier-invoices/{$b->id}/action", ['action' => 'mark'])->assertOk()->json('row.status'))->toBe('entered');
    expect($this->getJson('/office-api/supplier-invoices')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/supplier-invoices?status=all')->json('total'))->toBe(2)
        ->and($this->getJson('/office-api/supplier-invoices?status=rejected')->json('rows.0.invoice_no'))->toBe('EC-100');
    $csv = $this->get('/office-api/export/supplier-invoices?format=csv&status=all');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('EC-100')->toContain('EC-101');
});

it('lists what is owed for consigned goods sold and settles it into a supplier bill', function () {
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/consignment')->assertOk()->json('rows'))->toBe([]);
    $product = makeProduct($this->owner, 'CONS', 'On consignment', retail: '110.00');
    DB::table('consignment_sales')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'branch_id' => $this->b['HQ']->id, 'sale_id' => (string) Str::uuid7(),
        'sale_line_id' => (string) Str::uuid7(), 'product_id' => $product->id, 'consignor_id' => $this->supplier->id, 'qty' => '2', 'net_minor' => 22000, 'share_minor' => 12232,
        'occurred_at' => now()->subDay(), 'created_at' => now()]);
    $owed = $this->getJson('/office-api/consignment')->json('rows');
    expect($owed)->toHaveCount(1)->and($owed[0])->toMatchArray(['supplier' => 'ERNEST CHEMISTS', 'branch' => 'HQ', 'qty' => '2', 'sold' => 22000, 'owed' => 12232]);

    $this->postJson('/office-api/consignment/settle', ['who' => $owed[0]['key']])->assertStatus(422)->assertJsonValidationErrors('statement');
    $after = $this->postJson('/office-api/consignment/settle', ['who' => $owed[0]['key'], 'statement' => 'ST-9'])->assertOk()->json();
    expect($after['rows'])->toBe([])->and(SupplierBill::where('number', $after['bill'])->value('total_minor'))->toBe(12232);
});

it('lets a teller see neither', function () {
    $teller = staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/supplier-invoices')->assertForbidden();
    $this->getJson('/office-api/consignment')->assertForbidden();
    expect(SupplierInvoice::count())->toBe(0);
});

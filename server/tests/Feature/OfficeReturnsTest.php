<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\OfficeReturns;
use App\Models\Account;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Returns in the new back office (release 7, part 4c): the list with the current page's filters and columns, the
// 30-day summary, the export and the return slip.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $this->item = makeProduct($this->owner, 'ITEM', 'Vitamin C', retail: '10.00')->fresh(['units']);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->item, $this->item->units->first(), '10', 'opening', '', 'x', '4.00');
    $this->gm = withMfa(staff($this->owner, 'gm', 5, 'general_manager', ScopeType::Tenant, $this->tenant->id));
    $this->clerk = withMfa(staff($this->owner, 'clerk', 4, 'accountant', ScopeType::Tenant, $this->tenant->id));

    // A posted sale of two, built directly (this test is about what comes back), then one returned through the back office.
    $unit = $this->item->units->firstWhere('is_base', true);
    $id = (string) Str::uuid7();
    (new Sale)->forceFill(['id' => $id, 'tenant_id' => $this->tenant->id, 'branch_id' => $this->hq->branch_id, 'warehouse_id' => $this->hq->id, 'receipt_no' => 'R-'.substr($id, -8),
        'cashier_id' => $this->owner->id, 'cashier_name' => 'Ama', 'price_list_id' => DB::table('price_lists')->where('is_default', true)->value('id'), 'prices_include_tax' => true,
        'currency_code' => 'GHS', 'net_minor' => 2000, 'tax_minor' => 0, 'total_minor' => 2000, 'paid_minor' => 2000, 'change_minor' => 0, 'tax_breakdown' => [], 'status' => 'posted',
        'payload_hash' => str_repeat('a', 64), 'occurred_at' => now(), 'received_at' => now(), 'kind' => 'sale'])->save();
    DB::table('sale_lines')->insert(['id' => (string) Str::uuid7(), 'sale_id' => $id, 'line_no' => 1, 'product_id' => $this->item->id, 'sku' => $this->item->sku, 'name' => $this->item->name,
        'product_uom_id' => $unit->id, 'uom_code' => 'PC', 'factor' => 1, 'qty' => 2, 'unit_price_minor' => 1000, 'line_total_minor' => 2000, 'net_minor' => 2000, 'tax_minor' => 0, 'tax_breakdown' => '[]']);
    DB::table('sale_payments')->insert(['id' => (string) Str::uuid7(), 'sale_id' => $id, 'method' => 'cash', 'amount_minor' => 2000, 'tendered_minor' => 2000, 'status' => 'approved']);
    $sale = Sale::with('lines', 'payments')->find($id);
    $returns = app(OfficeReturns::class);
    $returns->approve(actorFor($this->gm), $returns->submit(actorFor($this->clerk), $sale, ['lines' => [['line_id' => $sale->lines[0]->id, 'qty' => '1']], 'reason_code' => 'wrong_item',
        'refund_method' => 'money', 'account_id' => Account::where('system_key', 'bank')->sole()->id, 'reference' => 'MOMO-9']));
    $this->return = Sale::where('kind', 'return')->sole();
});

it('lists returns with their reason, items and refund, filters them, and exports them', function () {
    $this->actingAs($this->gm, 'web');
    $o = $this->getJson('/office-api/returns/options')->assertOk()->json();
    expect($o['summary'])->toStartWith('1 return(s) in the last 30 days, worth')->and(array_column($o['products'], 'id'))->toBe([$this->item->id])
        ->and(array_column($o['reasons'], 'id'))->toContain('wrong_item');

    $l = $this->getJson('/office-api/returns')->assertOk()->json();
    expect($l)->toMatchArray(['total' => 1, 'refunded' => 1000])->and($l['rows'][0])->toMatchArray(['receipt_no' => $this->return->receipt_no, 'approved_by' => $this->gm->name,
        'to' => 'Shelf', 'refund' => 1000, 'open' => "/office/sales/{$this->return->id}"])->and($l['rows'][0]['items'])->toContain('VITAMIN C');

    expect($this->getJson('/office-api/returns?reasons[]=wrong_item&approval=approved')->json('total'))->toBe(1);
    expect($this->getJson('/office-api/returns?reasons[]=damaged')->json('total'))->toBe(0);
    expect($this->getJson('/office-api/returns?approval=none')->json('total'))->toBe(0);
    expect($this->getJson("/office-api/returns?products[]={$this->item->id}&q={$this->return->receipt_no}")->json('total'))->toBe(1);

    $csv = $this->get('/office-api/export/returns?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Refunded as')->toContain($this->return->receipt_no);
});

it('makes the return slip, and keeps returns from a teller', function () {
    $this->actingAs($this->gm, 'web');
    $slip = $this->get("/office-api/returns/{$this->return->id}/slip");
    $slip->assertOk();
    expect($slip->headers->get('Content-Type'))->toContain('pdf');
    $sale = Sale::where('kind', 'sale')->sole();
    $this->get("/office-api/returns/{$sale->id}/slip")->assertNotFound(); // a sale is not a return

    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['BA']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/returns')->assertForbidden();
    $this->get('/office-api/export/returns?format=csv')->assertForbidden();
});

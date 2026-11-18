<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\CustomerOrderService;
use App\Domain\Sales\DeliveryService;
use App\Domain\Sales\ReceivablesService;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Invoice;
use App\Models\Warehouse;
use App\Security\Reconfirm;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;

// Customer invoices in the new back office (release 4, part 2): the list (search, filters, what is still owed), one
// invoice with what settled it, and Credit note through ReceivablesService (confirm it's you before writing off).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $milo->units->firstWhere('is_base', false);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $milo, $this->ctn, '40', 'opening', '');
    $this->shop = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['type' => 'business', 'name' => 'Kofi Provisions',
        'phone' => '024 555 1234', 'credit_limit_minor' => 5_000_000, 'payment_terms_days' => 30]);
    // An order of $n cartons, confirmed and delivered: its invoice.
    $this->invoice = function (string $n, string $ref = 'KP-PO-1') {
        $orders = app(CustomerOrderService::class);
        $q = $orders->saveQuotation(actorFor($this->owner), new CustomerOrder, ['customer_id' => $this->shop->id, 'warehouse_id' => $this->hq->id,
            'customer_ref' => $ref, 'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => $n]]]);

        return app(DeliveryService::class)->deliver(actorFor($this->owner), $orders->confirm(actorFor($this->owner), $q))['invoice'];
    };
});

it('lists invoices with what is still owed, filters them and exports the list', function () {
    $a = ($this->invoice)('2', 'KP-PO-7');
    $b = ($this->invoice)('1');
    app(ReceivablesService::class)->recordPayment(actorFor($this->owner), $this->shop, $this->b['HQ'], 'cash', $a->total_minor); // settles the oldest: $a
    DB::table('invoices')->where('id', $b->id)->update(['due_on' => now()->subDays(3)->toDateString()]); // past due

    $this->actingAs($this->owner, 'web');
    $list = $this->getJson('/office-api/invoices')->assertOk()->json();
    expect($list['total'])->toBe(2)->and($list['sum'])->toBe(99000)->and($list['open'])->toBe(33000)->and($list['overdue'])->toBe(33000);
    $rows = collect($list['rows'])->keyBy('number');
    expect($rows[$a->number])->toMatchArray(['open' => 0, 'overdue' => false, 'ref' => 'KP-PO-7'])
        ->and($rows[$b->number])->toMatchArray(['open' => 33000, 'overdue' => true, 'status' => 'posted']);

    expect($this->getJson('/office-api/invoices?q=KP-PO-7')->json('total'))->toBe(1)
        ->and($this->getJson('/office-api/invoices?q=kofi')->json('total'))->toBe(2)
        ->and($this->getJson('/office-api/invoices?owed=1')->json('rows.0.number'))->toBe($b->number)
        ->and($this->getJson('/office-api/invoices?overdue=1')->json('total'))->toBe(1)
        ->and($this->getJson('/office-api/invoices?min=500')->json('rows.0.number'))->toBe($a->number)
        ->and($this->getJson('/office-api/invoices?status=credited')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/invoices?sort=open_minor&dir=desc')->json('rows.0.number'))->toBe($b->number);

    $show = $this->getJson("/office-api/invoices/{$a->id}")->assertOk()->json();
    expect($show)->toMatchArray(['number' => $a->number, 'open' => 0, 'total' => 66000, 'ref' => 'KP-PO-7'])
        ->and($show['lines'][0])->toMatchArray(['sku' => 'MILO', 'unit_price' => 33000])
        ->and($show['settled'][0])->toMatchArray(['kind' => 'Payment', 'amount' => 66000])
        ->and($show['customer']['open'])->toBe("/office/customers/{$this->shop->id}")
        ->and($show['actions']['print'])->toBe("/print/invoice/{$a->id}")
        ->and($show['actions']['credit']['warehouses'])->not->toBeEmpty();

    $csv = $this->get('/office-api/export/invoices?format=csv&owed=1');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain($b->number)->not->toContain($a->number);
});

it('issues a credit note after confirming it is you, with or without goods coming back', function () {
    $a = ($this->invoice)('2');
    $b = ($this->invoice)('1');
    $this->actingAs($this->owner, 'web');

    // Writing off (no goods back) always asks to confirm it's you first: the answer says where.
    Reconfirm::$assumeConfirmed = false;
    $this->postJson("/office-api/invoices/{$b->id}/credit", ['reason' => 'Bad debt'])->assertStatus(428)->assertJsonPath('error', 'reconfirm')
        ->assertJsonStructure(['url']);
    expect(Invoice::find($b->id)->status)->toBe('posted');
    Reconfirm::$assumeConfirmed = true;

    $this->postJson("/office-api/invoices/{$b->id}/credit", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/office-api/invoices/{$b->id}/credit", ['reason' => 'Bad debt', 'warehouse_id' => (string) str()->uuid()])->assertStatus(422);
    $after = $this->postJson("/office-api/invoices/{$a->id}/credit", ['reason' => 'Damaged cartons', 'warehouse_id' => $this->hq->id])->assertOk()->json();
    expect($after)->toMatchArray(['status' => 'credited', 'open' => 0])->and($after['actions']['credit'])->toBeNull()
        ->and($after['credit_notes'][0])->toMatchArray(['reason' => 'Damaged cartons', 'goods_returned' => true, 'total' => 66000])
        ->and($after['settled'][0]['kind'])->toBe('Credit note');
    $this->postJson("/office-api/invoices/{$a->id}/credit", ['reason' => 'Again'])->assertStatus(422);
    expect($this->getJson('/office-api/invoices?status=credited')->json('total'))->toBe(1);
});

it('lets a teller see nothing and a branch rep only see, never credit', function () {
    $a = ($this->invoice)('1');
    $teller = staff($this->owner, 'ama', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/invoices')->assertForbidden();

    $rep = staff($this->owner, 'abena', 2, 'sales_rep', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($rep, 'web');
    expect($this->getJson('/office-api/invoices')->assertOk()->json('total'))->toBe(1)
        ->and($this->getJson("/office-api/invoices/{$a->id}")->assertOk()->json('actions.credit'))->toBeNull();
    $this->postJson("/office-api/invoices/{$a->id}/credit", ['reason' => 'Nope'])->assertForbidden();
});

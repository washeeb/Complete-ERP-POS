<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\CustomerOrderService;
use App\Domain\Sales\DeliveryService;
use App\Domain\Sales\ReceivablesService;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Invoice;
use App\Models\PriceList;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Quotes & orders, Payments received and Aged receivables in the new back office (part 15).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00'], wholesale: '13.00');
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->ctn, '40', 'opening', '');
    $this->shop = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['type' => 'business', 'name' => 'Kofi Provisions', 'phone' => '024 555 1234',
        'price_list_id' => PriceList::where('code', 'WHOLESALE')->value('id'), 'credit_limit_minor' => 100_000, 'payment_terms_days' => 30]); // GHS 1,000 credit
    withMfa($this->owner);
});

it('quotes, holds for credit, releases, delivers part and cancels the rest, through the services', function () {
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/customer-orders/customers?q=kofi')->assertOk()->json('results.0'))->toMatchArray(['id' => $this->shop->id, 'label' => 'KOFI PROVISIONS']);
    expect(collect($this->getJson('/office-api/customer-orders/units?q=milo')->json('results'))->pluck('id'))->toContain($this->ctn->id);
    expect($this->getJson("/office-api/customer-orders/available?unit={$this->ctn->id}&warehouse={$this->hq->id}")->json('available'))->toContain('40');
    $form = $this->getJson('/office-api/customer-orders/form')->assertOk()->json();
    expect($form['values']['warehouse_id'])->not->toBe('')->and($form['can_add_customer'])->toBeTrue();

    // A quotation priced from the customer's (wholesale) list when saved; then changed.
    $this->postJson('/office-api/customer-orders', ['customer_id' => $this->shop->id, 'warehouse_id' => $this->hq->id, 'lines' => []])->assertStatus(422);
    $id = $this->postJson('/office-api/customer-orders', ['customer_id' => $this->shop->id, 'warehouse_id' => $this->hq->id, 'customer_ref' => 'KP-88',
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '2']]])->assertOk()->json('id');
    $this->postJson("/office-api/customer-orders/{$id}", ['customer_id' => $this->shop->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '5']]])->assertOk();
    $o = $this->getJson("/office-api/customer-orders/{$id}")->assertOk()->json();
    expect($o)->toMatchArray(['status' => 'quotation', 'total' => 165000])->and($o['actions'])->toMatchArray(['edit' => true, 'confirm' => true, 'cancel' => 'Mark lost'])
        ->and($o['lines'][0]['ordered'])->toContain('5');

    // Over the credit limit: on hold; released with a reason; delivered in part (an invoice); the rest cancelled.
    $r = $this->postJson("/office-api/customer-orders/{$id}/action", ['action' => 'confirm'])->assertOk()->json();
    expect($r['held'])->toBeTrue()->and($r['order']['hold_reason'])->not->toBeNull()->and($r['order']['actions']['release'])->toBeFalse(); // not one's own order
    $this->postJson("/office-api/customer-orders/{$id}/action", ['action' => 'release', 'reason' => 'Mine'])->assertStatus(422);
    $gm = withMfa(staff($this->owner, 'kwesi', 5, 'general_manager'));
    $this->actingAs($gm, 'web');
    expect($this->getJson("/office-api/customer-orders/{$id}")->json('actions.release'))->toBeTrue();
    $this->postJson("/office-api/customer-orders/{$id}/action", ['action' => 'release'])->assertStatus(422);
    $this->postJson("/office-api/customer-orders/{$id}/action", ['action' => 'release', 'reason' => 'Paid cash on account'])->assertOk();
    $this->actingAs($this->owner, 'web');
    $deliver = $this->getJson("/office-api/customer-orders/{$id}")->json('actions.deliver');
    $line = $deliver['lines'][0]['id'];
    $done = $this->postJson("/office-api/customer-orders/{$id}/action", ['action' => 'deliver', 'qtys' => [$line => '2'], 'delivered_to' => 'Kofi'])->assertOk()->json();
    expect($done['message'])->toContain('Invoice')->and($done['order']['status'])->toBe('partially_delivered')->and(Invoice::count())->toBe(1)
        ->and($done['order']['actions']['invoices'])->toStartWith('/office/invoices?q=SO-'); // the rebuilt Invoices, searched
    $this->postJson("/office-api/customer-orders/{$id}/action", ['action' => 'cancel', 'reason' => 'Customer changed their mind'])->assertOk();
    expect(CustomerOrder::find($id)->status)->not->toBe('partially_delivered');

    // The list, its filters and export.
    $list = $this->getJson('/office-api/customer-orders?q=kofi')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0]['customer'])->toBe('KOFI PROVISIONS');
    expect($this->getJson('/office-api/customer-orders?status[]=quotation')->json('total'))->toBe(0);
    $this->get('/office-api/export/customer-orders?format=csv')->assertOk();
});

it('lists payments received and what customers owe, only for those who may see receivables', function () {
    $o = app(CustomerOrderService::class)->saveQuotation(actorFor($this->owner), new CustomerOrder, ['customer_id' => $this->shop->id,
        'warehouse_id' => $this->hq->id, 'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '2']]]);
    $o = app(CustomerOrderService::class)->confirm(actorFor($this->owner), $o);
    app(DeliveryService::class)->deliver(actorFor($this->owner), $o);
    app(ReceivablesService::class)->recordPayment(actorFor($this->owner), $this->shop, $this->branches['HQ'], 'momo', 20000, 'MM-77', provider: 'mtn');
    $this->actingAs($this->owner, 'web');

    $pay = $this->getJson('/office-api/customer-payments?q=MM-77')->assertOk()->json();
    expect($pay['total'])->toBe(1)->and($pay['sum'])->toBe(20000)->and($pay['rows'][0])->toMatchArray(['customer' => 'KOFI PROVISIONS', 'reference' => 'MM-77'])
        ->and($pay['rows'][0]['method'])->toContain('MTN')->and($pay['rows'][0]['print'])->toContain('/print/payment/');
    expect($this->getJson('/office-api/customer-payments?method[]=cash')->json('total'))->toBe(0);
    expect(collect($this->getJson('/office-api/customer-payments/options')->json('methods'))->pluck('id'))->toContain('momo');
    $this->get('/office-api/export/customer-payments?format=csv')->assertOk();

    $aging = $this->getJson('/office-api/aged-receivables')->assertOk()->json();
    expect($aging['rows'][0])->toMatchArray(['id' => $this->shop->id, 'current' => 66000 - 20000, 'total' => 66000 - 20000])
        ->and($aging['rows'][0]['open'])->toBe("/office/customers/{$this->shop->id}");

    $store = withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($store, 'web');
    $this->getJson('/office-api/customer-payments')->assertForbidden();
    $this->getJson('/office-api/aged-receivables')->assertForbidden();
});

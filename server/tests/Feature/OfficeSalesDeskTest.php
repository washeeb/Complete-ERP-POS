<?php

use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Messaging\PhonePush;
use App\Domain\Pos\TillApprovals;
use App\Domain\Sync\ReviewQueue;
use App\Models\Account;
use App\Models\OfficeReturn;
use App\Models\PushSubscription;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\StoredValue;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

// Till requests, Back-office returns, the Review queue and Gift cards & credit in the new back office (part 16).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    withMfa($this->owner);
});

/** A posted sale of two, built directly (these tests are about what comes after it). */
function deskSale($t): Sale
{
    $item = makeProduct($t->owner, 'ITEM', 'Vitamin C', retail: '10.00')->fresh(['units']);
    app(InventoryService::class)->adjust(actorFor($t->owner), $t->hq, $item, $item->units->first(), '10', 'opening', '', 'x', '4.00');
    $unit = $item->units->firstWhere('is_base', true);
    $id = (string) Str::uuid7();
    (new Sale)->forceFill(['id' => $id, 'tenant_id' => $t->tenant->id, 'branch_id' => $t->hq->branch_id, 'warehouse_id' => $t->hq->id, 'receipt_no' => 'R-'.substr($id, -8),
        'cashier_id' => $t->owner->id, 'cashier_name' => 'Ama', 'price_list_id' => DB::table('price_lists')->where('is_default', true)->value('id'), 'prices_include_tax' => true,
        'currency_code' => 'GHS', 'net_minor' => 2000, 'tax_minor' => 0, 'total_minor' => 2000, 'paid_minor' => 2000, 'change_minor' => 0, 'tax_breakdown' => [], 'status' => 'posted',
        'payload_hash' => str_repeat('a', 64), 'occurred_at' => now(), 'received_at' => now(), 'kind' => 'sale'])->save();
    DB::table('sale_lines')->insert(['id' => (string) Str::uuid7(), 'sale_id' => $id, 'line_no' => 1, 'product_id' => $item->id, 'sku' => $item->sku, 'name' => $item->name,
        'product_uom_id' => $unit->id, 'uom_code' => 'PC', 'factor' => 1, 'qty' => 2, 'unit_price_minor' => 1000, 'line_total_minor' => 2000, 'net_minor' => 2000, 'tax_minor' => 0, 'tax_breakdown' => '[]']);
    DB::table('sale_payments')->insert(['id' => (string) Str::uuid7(), 'sale_id' => $id, 'method' => 'cash', 'amount_minor' => 2000, 'tendered_minor' => 2000, 'status' => 'approved']);

    return Sale::with('lines', 'payments')->find($id);
}

it('lists till requests to answer, and turns on notifications for this device', function () {
    $till = Till::where('branch_id', $this->b['HQ']->id)->first();
    [$device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->b['HQ']->id, 'till_id' => $till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    $r = app(TillApprovals::class)->ask($device, $cashier, 'no_sale', 0, 0, 'Open the drawer without a sale', null, 'Change for a customer');
    $manager = withMfa(staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $this->b['HQ']->id));
    $elsewhere = withMfa(staff($this->owner, 'kofi', 4, 'branch_manager', ScopeType::Branch, $this->b['BA']->id));

    $this->actingAs($manager, 'web');
    $list = $this->getJson('/office-api/till-requests')->assertOk()->json();
    expect($list['show'])->toBe('pending')->and($list['rows'][0])->toMatchArray(['id' => $r->id, 'cashier' => 'Esi', 'can_decide' => true, 'reason' => 'Change for a customer'])
        ->and($list['push']['has_device'])->toBeFalse()->and($list['push']['key'])->not->toBe('');
    expect($this->getJson("/office-api/till-requests?id={$r->id}")->json('show'))->toBe('all');
    $this->postJson("/office-api/till-requests/{$r->id}/decide", ['yes' => false, 'note' => 'Use the float bag'])->assertOk();
    expect($this->getJson('/office-api/till-requests')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/till-requests?show=answered')->json('rows.0'))->toMatchArray(['status' => 'declined', 'can_decide' => false]);

    $this->postJson('/office-api/till-requests/push', ['endpoint' => 'http://not-https.example', 'public_key' => 'k', 'auth_token' => 'a'])->assertStatus(422);
    $this->postJson('/office-api/till-requests/push', ['endpoint' => 'https://push.example/abc', 'public_key' => 'k', 'auth_token' => 'a', 'device' => 'Phone'])->assertOk();
    expect(PushSubscription::where('user_id', $manager->id)->count())->toBe(1)->and($this->getJson('/office-api/till-requests')->json('push.has_device'))->toBeTrue();

    // A manager of another branch does not see this till's requests.
    $this->actingAs($elsewhere, 'web');
    expect($this->getJson('/office-api/till-requests?show=all')->json('total'))->toBe(0);
});

it('starts a return from the sale, then someone else approves it; only the branches the person works in are listed', function () {
    $sale = deskSale($this);
    $clerk = withMfa(staff($this->owner, 'clerk', 4, 'accountant'));
    $gm = withMfa(staff($this->owner, 'gm', 5, 'general_manager'));
    $this->actingAs($clerk, 'web');

    expect($this->getJson("/office-api/sales/{$sale->id}")->json('actions.return'))->toBeTrue();
    $form = $this->getJson("/office-api/sales/{$sale->id}/return")->assertOk()->json();
    expect($form['lines'][0])->toMatchArray(['name' => 'VITAMIN C', 'can' => '2 PC'])->and($form['warehouse_id'])->toBe($this->hq->id);
    $line = $form['lines'][0]['line_id'];
    $this->postJson("/office-api/sales/{$sale->id}/return", ['lines' => [['line_id' => $line, 'qty' => '1']], 'warehouse_id' => $this->hq->id, 'reason_code' => 'wrong_item',
        'refund_method' => 'money'])->assertStatus(422)->assertJsonValidationErrors(['account_id', 'reference']);
    $id = $this->postJson("/office-api/sales/{$sale->id}/return", ['lines' => [['line_id' => $line, 'qty' => '1', 'damaged' => false]], 'warehouse_id' => $this->hq->id,
        'reason_code' => 'wrong_item', 'refund_method' => 'money', 'account_id' => Account::where('system_key', 'bank')->value('id'), 'reference' => 'MOMO-9'])->assertOk()->json('id');

    $doc = $this->getJson("/office-api/office-returns/{$id}")->assertOk()->json();
    expect($doc)->toMatchArray(['status' => 'submitted', 'refund' => 1000, 'reference' => 'MOMO-9'])->and($doc['actions']['approve'])->toBeFalse() // never one's own
        ->and($doc['actions']['why_not'])->not->toBeNull()->and($doc['lines'][0])->toMatchArray(['qty' => '1 PC', 'condition' => 'Good']);
    expect($this->getJson('/office-api/office-returns?status=submitted')->json())->toMatchArray(['total' => 1, 'waiting' => 1]);

    $this->actingAs($gm, 'web');
    $this->postJson("/office-api/office-returns/{$id}/approve")->assertOk()->assertJsonPath('doc.status', 'posted');
    expect(OfficeReturn::find($id)->status)->toBe('posted')->and($this->getJson("/office-api/office-returns/{$id}")->json('actions.slip'))->toContain('/slip');

    // A clerk of Branch A only does not see HQ's returns.
    $a = withMfa(staff($this->owner, 'yaa', 4, 'branch_manager', ScopeType::Branch, $this->b['BA']->id));
    $this->actingAs($a, 'web');
    expect($this->getJson('/office-api/office-returns')->json('total'))->toBe(0);
    $this->getJson("/office-api/office-returns/{$id}")->assertNotFound();
});

it('resolves review items and sells gift cards with their history', function () {
    $item = app(ReviewQueue::class)->add(actorFor($this->owner), 'negative_stock', 'product', null, 'Milo went to -2 at HQ', [], $this->b['HQ']->id);
    $this->actingAs($this->owner, 'web');

    $q = $this->getJson('/office-api/review-queue')->assertOk()->json();
    expect($q['rows'][0])->toMatchArray(['id' => $item->id, 'status' => 'open', 'mild' => true, 'branch' => 'HQ']);
    $this->postJson("/office-api/review-queue/{$item->id}/resolve", ['resolution' => ''])->assertStatus(422);
    $this->postJson("/office-api/review-queue/{$item->id}/resolve", ['resolution' => 'Counted 3 left; adjustment posted.'])->assertOk();
    expect(ReviewItem::find($item->id)->status)->toBe('resolved')->and($this->getJson('/office-api/review-queue')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/review-queue?status=resolved')->json('rows.0.resolution'))->toBe('Counted 3 left; adjustment posted.');

    $this->postJson('/office-api/gift-cards', ['amount' => '50', 'paid_by' => 'barter'])->assertStatus(422);
    $code = $this->postJson('/office-api/gift-cards', ['amount' => '50', 'paid_by' => 'momo', 'reference' => 'MM-1'])->assertOk()->json('code');
    $cards = $this->getJson("/office-api/gift-cards?q={$code}")->assertOk()->json();
    expect($cards['total'])->toBe(1)->and($cards['rows'][0])->toMatchArray(['code' => $code, 'kind' => 'Gift card', 'balance' => 5000]);
    expect($this->getJson('/office-api/gift-cards/'.StoredValue::sole()->id.'/history')->json('rows.0.amount'))->toBe(5000);

    $store = withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($store, 'web');
    $this->getJson('/office-api/gift-cards')->assertForbidden();
    $this->getJson('/office-api/review-queue')->assertForbidden();
});

it('makes the notification key on any server, in the form the push library takes', function () {
    $k = PhonePush::newKeys();
    expect(strlen($k['publicKey']))->toBe(87)->and(strlen($k['privateKey']))->toBe(43);
    $v = VAPID::validate(['subject' => 'mailto:support@example.com', 'publicKey' => $k['publicKey'], 'privateKey' => $k['privateKey']]);
    expect(strlen($v['publicKey']))->toBe(65)->and(strlen($v['privateKey']))->toBe(32);
});

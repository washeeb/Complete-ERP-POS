<?php

use App\Domain\Channels\OnlineOrderService;
use App\Domain\Channels\WhatsappOrders;
use App\Domain\Customers\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Messaging\ProviderSetup;
use App\Domain\Support\AccessDenied;
use App\Filament\Resources\WhatsappOrders\Pages\ViewWhatsappOrder;
use App\Filament\Resources\WhatsappOrders\WhatsappOrderResource;
use App\Models\Customer;
use App\Models\MessageProvider;
use App\Models\SalesChannel;
use App\Models\Warehouse;
use App\Models\WhatsappOrder;
use App\Security\ScopeType;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->o = actorFor($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00');
    app(InventoryService::class)->adjust($this->o, $this->wh, $this->milo, $this->milo->units->first(), '40', 'opening', '', null, '10.00');
    app(ProviderSetup::class)->save($this->o, 'whatsapp', ['credentials' => ['phone_number_id' => '1234', 'access_token' => 'tok', 'app_secret' => 'shh'], 'is_active' => true]);
    $this->provider = MessageProvider::where('channel', 'whatsapp')->sole();
    app(Settings::class)->set($this->o, 'whatsapp.orders', true);
    Storage::fake('local');
    Http::fake([
        'graph.facebook.com/v20.0/1234/messages' => Http::response(['messages' => [['id' => 'wamid.out']]]),
        'graph.facebook.com/v20.0/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/media-1', 'mime_type' => 'image/jpeg', 'file_size' => 5]),
        'lookaside.fbsbx.com/*' => Http::response('JPEG!', 200, ['Content-Type' => 'image/jpeg']),
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** What Meta posts for one customer message, signed like Meta does. */
function metaPost($test, array $message, string $secret = 'shh', string $from = '233241112222')
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ama']]], 'messages' => [['from' => $from, 'timestamp' => (string) time()] + $message]]]]]]]);

    return $test->call('POST', '/api/v1/whatsapp/'.$test->provider->id, [], [], [], ['CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret)], $body);
}

it('answers Meta\'s address check only with the right token', function () {
    $token = $this->provider->credentials['verify_token'];
    expect(strlen($token))->toBe(32);
    $this->get('/api/v1/whatsapp/'.$this->provider->id.'?hub.mode=subscribe&hub.verify_token='.$token.'&hub.challenge=12345')->assertOk()->assertSee('12345');
    $this->get('/api/v1/whatsapp/'.$this->provider->id.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
});

it('keeps a list and a prescription photo from one customer as one entry, answers, and fetches the photo', function () {
    $ama = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Ama Owusu', 'phone' => '024 111 2222']);

    metaPost($this, ['id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => '2 Milo please']])->assertOk();
    metaPost($this, ['id' => 'wamid.2', 'type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg', 'caption' => 'my prescription']])->assertOk();
    metaPost($this, ['id' => 'wamid.2', 'type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg']])->assertOk(); // sent again by Meta

    $o = WhatsappOrder::sole();
    expect($o->number)->toStartWith('WA-')
        ->and($o->customer_id)->toBe($ama->id)
        ->and($o->body)->toContain('2 Milo please')->toContain('[Photo] my prescription')
        ->and($o->message_ids)->toBe(['wamid.1', 'wamid.2'])
        ->and($o->media)->toHaveCount(1)
        ->and($o->media[0]['path'])->not->toBeNull();
    Storage::disk('local')->assertExists($o->media[0]['path']);
    // One automatic reply, with the reference.
    Http::assertSentCount(3); // reply + media address + media file
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/1234/messages') && $r['type'] === 'text' && str_contains($r['text']['body'], $o->number));
});

it('refuses calls not signed with the app secret, and keeps nothing when WhatsApp orders are off', function () {
    metaPost($this, ['id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'hi']], secret: 'guess')->assertForbidden();
    expect(WhatsappOrder::count())->toBe(0);

    app(Settings::class)->set($this->o, 'whatsapp.orders', false);
    metaPost($this, ['id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'hi']])->assertOk();
    expect(WhatsappOrder::count())->toBe(0);
});

it('turns the message into an online order with stock held, tells the customer the total and when it is ready', function () {
    metaPost($this, ['id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => '2 Milo please']]);
    $o = WhatsappOrder::sole();
    $unit = $this->milo->units->firstWhere('is_base', true);

    $order = app(WhatsappOrders::class)->makeOrder($this->o, $o, ['branch_id' => $this->hq->id, 'fulfilment' => 'pickup', 'lines' => [['product_uom_id' => $unit->id, 'qty' => '2']]]);

    expect($order->number)->toBe($o->number)
        ->and($order->total_minor)->toBe(3000)
        ->and($order->status)->toBe('confirmed')
        ->and($order->customer_phone)->toBe('+233241112222')
        ->and(SalesChannel::find($order->channel_id)->type)->toBe('whatsapp')
        ->and($o->fresh()->status)->toBe('ordered')
        ->and(DB::table('stock_reservations')->where('source_id', $order->id)->exists())->toBeTrue();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/1234/messages') && str_contains($r['text']['body'] ?? '', 'is confirmed') && str_contains($r['text']['body'], '2 × MILO 400G'));

    app(OnlineOrderService::class)->markReady($this->o, $order);
    Http::assertSent(fn ($r) => str_contains($r['text']['body'] ?? '', 'is ready. You can pick it up at'));

    // A second order at the branch uses the same WhatsApp channel.
    expect(SalesChannel::where('type', 'whatsapp')->count())->toBe(1);
});

it('declines with a reason the customer is sent, and only for people who handle online orders', function () {
    metaPost($this, ['id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Do you have Tramadol?']]);
    $o = WhatsappOrder::sole();
    $cashier = staff($this->owner, 'kojo', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    expect(fn () => app(WhatsappOrders::class)->decline(actorFor($cashier), $o, 'no'))->toThrow(AccessDenied::class);

    app(WhatsappOrders::class)->decline($this->o, $o, 'a prescription is needed');
    expect($o->fresh()->status)->toBe('declined');
    Http::assertSent(fn ($r) => str_contains($r['text']['body'] ?? '', 'Sorry, we cannot fill your order '.$o->number.': a prescription is needed'));
});

it('shows the entry and its photo to staff, not to others', function () {
    metaPost($this, ['id' => 'wamid.2', 'type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg']]);
    $o = WhatsappOrder::sole();
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ViewWhatsappOrder::class, ['record' => $o->id])->assertOk()->assertSee($o->number)->assertSee('Photo from the customer');
    $this->get(WhatsappOrderResource::getUrl('index'))->assertOk();
    $this->get(route('whatsapp.file', ['order' => $o->id, 'index' => 0]))->assertOk();

    $this->flushSession();
    $cashier = withMfa(staff($this->owner, 'kojo', 3, 'cashier', ScopeType::Branch, $this->hq->id));
    $this->actingAs($cashier, 'web')->get(route('whatsapp.file', ['order' => $o->id, 'index' => 0]))->assertForbidden();
});

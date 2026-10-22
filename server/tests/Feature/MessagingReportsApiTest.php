<?php

use App\Domain\Access\AccessService;
use App\Domain\Api\ApiClients;
use App\Domain\Api\OpenApi;
use App\Domain\Api\WebhookDispatcher;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AccountingExport;
use App\Domain\Finance\AutoPoster;
use App\Domain\Imports\ImportService;
use App\Domain\Imports\SheetFile;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\NotificationRelay;
use App\Domain\Messaging\ProviderSetup;
use App\Domain\Messaging\ReceiptSender;
use App\Domain\Messaging\Templates;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Reports\ReportEngine;
use App\Domain\Reports\ReportService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Domain\Support\Outbox;
use App\Filament\Pages\AccountingExportPage;
use App\Filament\Pages\MessagingSetup;
use App\Filament\Pages\ReportBuilder;
use App\Filament\Resources\ApiClients\ApiClientResource;
use App\Filament\Resources\Messages\MessageResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\SavedReports\SavedReportResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Mail\PlainMessage;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageProvider;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SavedReport;
use App\Models\Till;
use App\Models\Warehouse;
use App\Models\WebhookDelivery;
use App\Security\ScopeType;
use App\Support\Exports\XlsxWriter;
use App\Support\People;
use App\Support\Qty;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->o = actorFor($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust($this->o, $this->wh, $this->milo, $this->milo->units->first(), '50', 'opening', '', null, '40.00');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->ama = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222', 'email' => 'ama@example.test']);
});

function p12Sale($t, string $qty = '2'): Sale
{
    $unit = $t->milo->units->first();
    $price = app(PriceResolver::class)->resolve($t->retail, $t->milo->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
    $calc = PromotionEngine::compute(['lines' => [['key' => '0', 'product_id' => $t->milo->id, 'category_id' => null, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
        'tax' => $t->milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]],
        'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => [['product_id' => $t->milo->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price,
            'line_total_minor' => $calc['lines'][0]['total']]], 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]])['sale'];
}

it('sends SMS through the provider the client chose, WhatsApp by approved template, and e-mail by SMTP, retrying when a provider is down', function () {
    $setup = app(ProviderSetup::class);
    $m = app(Messenger::class);
    expect(Message::count())->toBe(0)
        ->and($m->send($this->tenant->id, 'sms', '0241112222', 'Hello', null, 'R1', 'Test')->status)->toBe('not_configured');

    Http::fake(['smsc.hubtel.com/*' => Http::response(['MessageId' => 'h-1']), 'sms.arkesel.com/*' => Http::response(['status' => 'success', 'data' => [['id' => 'a-1']]]),
        'api.mnotify.com/*' => Http::response(['status' => 'success', 'code' => '2000', 'summary' => ['_id' => 'm-1']]), 'gateway.example.test/*' => Http::response(['ok' => true]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    expect(fn () => $setup->save($this->o, 'sms', ['provider' => 'hubtel', 'sender_id' => 'X', 'credentials' => ['client_id' => 'id', 'client_secret' => 's'], 'is_active' => true]))
        ->toThrow(DomainException::class, 'sender ID')
        ->and(fn () => $setup->save(actorFor($this->cashier), 'sms', ['provider' => 'hubtel']))->toThrow(AccessDenied::class);

    foreach (['hubtel' => ['client_id' => 'cid', 'client_secret' => 'csecret'], 'arkesel' => ['api_key' => 'ark-key'], 'mnotify' => ['api_key' => 'mn-key'],
        'webhook' => ['url' => 'https://gateway.example.test/send', 'secret' => 'hook']] as $provider => $cred) {
        $setup->save($this->o, 'sms', ['provider' => $provider, 'sender_id' => 'DemoShop', 'credentials' => $cred, 'is_active' => true]);
        $msg = $m->send($this->tenant->id, 'sms', '024 111 2222', "Via {$provider}", null, 'R1', 'Test');
        expect($msg->status)->toBe('sent')->and($msg->provider)->toBe($provider);
    }
    Http::assertSent(fn ($r) => str_contains($r->url(), 'hubtel') && $r['To'] === '233241112222' && $r['From'] === 'DemoShop' && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:csecret')));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'arkesel') && $r->hasHeader('api-key', 'ark-key') && $r['recipients'] === ['233241112222']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'mnotify') && str_contains($r->url(), 'key=mn-key') && $r['message'] === 'Via mnotify');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'gateway.example.test') && $r['to'] === '024 111 2222' && $r->hasHeader('X-Signature'));
    // Credentials are encrypted at rest and never in the audit log.
    expect(DB::table('message_providers')->value('credentials'))->not->toContain('gateway.example.test')
        ->and(DB::table('audit_events')->where('action', 'message_provider.saved')->pluck('changes')->implode(' '))->not->toContain('ark-key');

    // WhatsApp preferred over SMS for a customer message when its template is on; the template parameters go in order.
    $setup->save($this->o, 'whatsapp', ['credentials' => ['phone_number_id' => '1234', 'access_token' => 'tok'], 'is_active' => true]);
    $sent = $m->toCustomer($this->tenant->id, 'reservation_booked', '0241112222', 'ama@example.test', ['who' => 'Demo', 'party_size' => 4, 'when' => 'Fri 9 Oct, 19:00', 'number' => 'RES-1', 'call' => ''], 'RES-1', 'Test');
    expect($sent)->toHaveCount(1)->and($sent[0]->channel)->toBe('whatsapp')->and($sent[0]->status)->toBe('sent');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com/v20.0/1234/messages') && $r['template']['name'] === 'booking_confirmed'
        && $r['template']['components'][0]['parameters'][1]['text'] === '4' && $r->hasHeader('Authorization', 'Bearer tok'));

    // A provider that is down: retried with back-off, then sent.
    Http::fake(['flaky.example.test/*' => Http::sequence()->push(['error' => 'down'], 503)->push(['ok' => true])]);
    $setup->save($this->o, 'sms', ['provider' => 'webhook', 'credentials' => ['url' => 'https://flaky.example.test/send'], 'is_active' => true]);
    $retry = $m->send($this->tenant->id, 'sms', '0241112222', 'Retry me', null, 'R2', 'Test');
    expect($retry->status)->toBe('queued')->and($retry->next_attempt_at->gt(now()))->toBeTrue();
    $this->travel(2)->minutes();
    expect($m->deliverDue())->toBe(1)->and($retry->fresh()->status)->toBe('sent')->and($retry->fresh()->attempts)->toBe(2);

    // E-mail with an attachment.
    Mail::fake();
    Storage::fake('local');
    Storage::disk('local')->put('report-runs/x/report.pdf', '%PDF-1.4');
    $mail = $m->send($this->tenant->id, 'email', 'boss@example.test', 'Attached.', 'Weekly report', 'REP', 'Test', null, [], 'report-runs/x/report.pdf', 'report.pdf');
    expect($mail->status)->toBe('sent');
    Mail::assertSent(PlainMessage::class, fn ($e) => $e->hasTo('boss@example.test') && $e->subjectLine === 'Weekly report' && $e->attachmentName === 'report.pdf');
    expect(fn () => $m->send($this->tenant->id, 'email', 'not-an-address', 'x', 'x', null, 'Test'))->toThrow(DomainException::class);
});

it('sends a digital receipt from the till, tells approvers in the back office, and messages customers only when switched on', function () {
    Http::fake(['gateway.example.test/*' => Http::response(['ok' => true])]);
    app(ProviderSetup::class)->save($this->o, 'sms', ['provider' => 'webhook', 'credentials' => ['url' => 'https://gateway.example.test/send'], 'is_active' => true]);
    $sale = p12Sale($this);
    $msg = app(ReceiptSender::class)->send($this->device, $this->cashier, $sale->id, 'sms', '0241112222');
    expect($msg->status)->toBe('sent')->and($msg->body)->toContain($sale->receipt_no)->toContain('Thank you for shopping');
    $t = Templates::find($this->tenant->id, 'receipt', 'sms');
    app(Templates::class)->save($this->o, $t, ['is_active' => false]);
    expect(fn () => app(ReceiptSender::class)->send($this->device, $this->cashier, $sale->id, 'sms', '0241112222'))->toThrow(DomainException::class, 'switched off');

    // Approvals: the approver gets a bell notification, the person who submitted does not.
    app(NotificationRelay::class)->run(); // a new consumer starts from now (as the relay does on its first run)
    app(Settings::class)->set($this->o, 'stock.adjustment_approval_minor', 1000);
    $keeper = staff($this->owner, 'yaw', 4, 'warehouse_manager', ScopeType::Branch, $this->hq->id);
    $svc = app(StockAdjustmentService::class);
    $adj = $svc->create(actorFor($keeper), $this->wh, ['reason_code' => 'damaged']);
    $svc->addLine(actorFor($keeper), $adj, ['sku' => 'MILO', 'mode' => 'remove', 'qty' => '5']);
    $svc->submit(actorFor($keeper), $adj->fresh());
    app(NotificationRelay::class)->run();
    expect($this->owner->fresh()->notifications()->count())->toBe(1)
        ->and($this->owner->fresh()->notifications()->first()->data['title'])->toContain($adj->number)->toContain('waiting for approval')
        ->and($keeper->fresh()->notifications()->count())->toBe(0);

    // Invoice e-mails start switched off; once on, the customer gets one.
    Mail::fake();
    $publish = fn () => DB::transaction(fn () => app(Outbox::class)->publishRaw($this->o, 'invoice', (string) Str::uuid7(), 1, 'invoice.posted',
        ['snapshot' => ['row' => ['customer_id' => $this->ama->id, 'number' => 'INV-000123', 'total_minor' => 11600, 'currency_code' => 'GHS', 'due_on' => '2026-11-30']]]));
    $publish();
    app(NotificationRelay::class)->run();
    Mail::assertNothingSent();
    app(Templates::class)->save($this->o, Templates::find($this->tenant->id, 'invoice_issued', 'email'), ['is_active' => true]);
    $publish();
    app(NotificationRelay::class)->run();
    Mail::assertSent(PlainMessage::class, fn ($e) => $e->hasTo('ama@example.test') && str_contains($e->subjectLine, 'INV-000123') && str_contains($e->text, '116.00') && str_contains($e->text, '2026-11-30'));
});

it('serves the public API with scoped keys acting as a person, and sends signed webhooks', function () {
    $api = app(ApiClients::class);
    expect(fn () => $api->create(actorFor($this->cashier), ['name' => 'X', 'scopes' => ['products:read'], 'user_id' => $this->cashier->id]))->toThrow(AccessDenied::class);
    [$client, $key] = $api->create($this->o, ['name' => 'Website', 'scopes' => ['products:read', 'customers:read', 'customers:write', 'orders:write', 'webhooks:manage', 'sales:read'], 'user_id' => $this->owner->id]);
    $h = ['Authorization' => "Bearer {$key}", 'Accept' => 'application/json'];

    $this->getJson('/api/public/v1/products')->assertUnauthorized();
    $r = $this->withHeaders($h)->getJson('/api/public/v1/products?search=milo')->assertOk();
    expect($r->json('data.0.sku'))->toBe('MILO')->and($r->json('data.0.units.0.price_minor'))->toBe(5800)->and($r->json('data.0.currency'))->toBe('GHS');
    $this->withHeaders($h)->getJson('/api/public/v1/stock')->assertForbidden(); // no stock:read scope
    $this->withHeaders($h)->getJson('/api/public/v1/products/'.$this->milo->id)->assertOk()->assertJsonPath('data.name', 'MILO 400G');

    $c = $this->withHeaders($h)->postJson('/api/public/v1/customers', ['name' => 'Kofi Boateng', 'phone' => '0201234567'])->assertCreated();
    $this->withHeaders($h)->postJson('/api/public/v1/customers', ['name' => ''])->assertStatus(422);
    $o = $this->withHeaders($h)->postJson('/api/public/v1/orders', ['customer_id' => $c->json('data.id'), 'lines' => [['sku' => 'MILO', 'qty' => 3]], 'reference' => 'WEB-77'])->assertCreated();
    expect($o->json('data.status'))->toBe('confirmed')->and($o->json('data.total_minor'))->toBe(17400);
    $this->withHeaders($h)->postJson('/api/public/v1/orders', ['customer_id' => $c->json('data.id'), 'lines' => [['sku' => 'NOPE', 'qty' => 1]]])->assertStatus(422)->assertJsonPath('error', 'rule_violation');

    // Webhooks: registered by the client, signed with its secret, the same event id on retries.
    app(WebhookDispatcher::class)->run(); // the dispatcher starts from now
    $w = $this->withHeaders($h)->postJson('/api/public/v1/webhooks', ['url' => 'https://partner.example.test/hooks', 'events' => ['product.updated', 'sale.posted']])->assertCreated();
    $secret = $w->json('data.secret');
    Http::fake(['partner.example.test/*' => Http::sequence()->push('', 500)->push(['ok' => true])]);
    app(CatalogService::class)->saveProduct($this->o, $this->milo, ['name' => 'Milo 400 g tin']);
    app(WebhookDispatcher::class)->run();
    $d = WebhookDelivery::sole();
    expect($d->status)->toBe('pending')->and($d->attempts)->toBe(1)->and($d->payload['data']['name'])->toBe('MILO 400 G TIN');
    $this->travel(2)->minutes();
    app(WebhookDispatcher::class)->run();
    expect($d->fresh()->status)->toBe('sent');
    Http::assertSent(function ($req) use ($secret, $d) {
        [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $req->header('X-Webhook-Signature')[0]));

        return $req->header('X-Webhook-Delivery')[0] === $d->event_id && hash_equals(hash_hmac('sha256', "{$t}.".$req->body(), $secret), $v1);
    });

    // A branch-limited client acting as a branch manager sees only that branch; revoked keys stop at once.
    p12Sale($this);
    $bm = staff($this->owner, 'adwoa', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id);
    [, $key2] = $api->create($this->o, ['name' => 'Branch A tablet', 'scopes' => ['sales:read'], 'user_id' => $bm->id]);
    app('auth')->forgetGuards(); // the test client keeps the last key's login otherwise
    expect($this->withHeaders(['Authorization' => "Bearer {$key2}"])->getJson('/api/public/v1/sales')->assertOk()->json('data'))->toBe([]);
    app('auth')->forgetGuards();
    expect($this->withHeaders($h)->getJson('/api/public/v1/sales')->json('data.0.lines.0.sku'))->toBe('MILO');
    $api->revoke($this->o, $client, 'Website replaced');
    app('auth')->forgetGuards();
    $this->withHeaders($h)->getJson('/api/public/v1/products')->assertUnauthorized();
    expect(array_keys(OpenApi::document()['paths']))->toContain('/products', '/orders', '/webhooks/{id}');
    $this->getJson('/api/public/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');
});

it('builds reports with drill-down, keeps them within the person’s access, and e-mails scheduled ones as Excel', function () {
    p12Sale($this, '2');
    p12Sale($this, '3');
    $engine = app(ReportEngine::class);
    $r = $engine->run($this->owner, 'sales_lines', ['dimensions' => ['branch'], 'measures' => ['qty', 'net', 'receipts'], 'period' => 'today']);
    expect($r['rows'][0][0])->toBe('HQ')->and($r['rows'][0][1])->toBe('5')->and($r['rows'][0][3])->toBe('2')->and(end($r['rows'])[0])->toBe('Total');
    $detail = $engine->run($this->owner, 'sales_lines', ['dimensions' => ['branch'], 'measures' => ['qty'], 'period' => 'today'], $r['raw'][0]);
    expect($detail['rows'])->toHaveCount(2)->and($detail['links'][0])->toStartWith('/admin/sales/')->and($detail['headings'])->toContain('Receipt');

    $bm = staff($this->owner, 'adwoa', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id);
    expect($engine->run($bm, 'sales_lines', ['dimensions' => ['branch'], 'measures' => ['net']])['rows'])->toBe([])
        ->and(fn () => $engine->run($bm, 'journal_lines', ['measures' => ['debit']]))->toThrow(AccessDenied::class)
        ->and(fn () => $engine->run($this->owner, 'sales_lines', []))->toThrow(DomainException::class);

    // Scheduled: weekly on Monday at 07:00 as Excel to an e-mail address.
    Mail::fake();
    Storage::fake('local');
    $svc = app(ReportService::class);
    expect(fn () => $svc->save($this->o, new SavedReport, ['name' => 'Weekly sales', 'dataset' => 'sales_lines', 'definition' => ['dimensions' => ['product'], 'measures' => ['qty', 'net'], 'period' => 'last_7'],
        'schedule' => 'weekly', 'recipients' => []]))->toThrow(DomainException::class, 'recipient');
    $rep = $svc->save($this->o, new SavedReport, ['name' => 'Weekly sales', 'dataset' => 'sales_lines', 'definition' => ['dimensions' => ['product'], 'measures' => ['qty', 'net'], 'period' => 'last_7'],
        'schedule' => 'weekly', 'schedule_day' => 1, 'schedule_time' => '07:00', 'format' => 'xlsx', 'recipients' => [['type' => 'email', 'to' => 'boss@example.test'], ['type' => 'user', 'to' => $this->owner->id]]]);
    $tz = $this->tenant->timezone ?: 'UTC';
    expect($rep->next_run_at->setTimezone($tz)->dayOfWeekIso)->toBe(1)->and($rep->next_run_at->setTimezone($tz)->format('H:i'))->toBe('07:00')
        ->and(ReportService::nextRun(tap(new SavedReport, fn ($s) => $s->forceFill(['schedule' => 'monthly', 'schedule_day' => 5, 'schedule_time' => '06:30'])), $tz,
            CarbonImmutable::parse('2026-10-05 06:31', $tz))->setTimezone($tz)->format('Y-m-d H:i'))->toBe('2026-11-05 06:30');
    $rep->forceFill(['next_run_at' => now()->subMinute()])->save();
    expect($svc->runDue())->toBe(1)->and($rep->fresh()->last_status)->toStartWith('Sent')->and($rep->fresh()->next_run_at->isFuture())->toBeTrue();
    Mail::assertSent(PlainMessage::class, fn ($e) => $e->hasTo('boss@example.test') && str_ends_with((string) $e->attachmentName, '.xlsx'));
    $file = Storage::disk('local')->allFiles('report-runs')[0];
    $read = SheetFile::xlsx(Storage::disk('local')->path($file));
    expect($read['headings'])->toBe(['product', 'units', 'sales_before_tax'])->and($read['rows'][0]['values']['product'])->toBe('MILO MILO 400G')
        ->and($this->owner->fresh()->notifications()->count())->toBe(1);
});

it('exports the books for QuickBooks, Xero and Sage, balanced, for company-wide finance only', function () {
    p12Sale($this);
    app(AutoPoster::class)->run($this->tenant->id);
    $x = app(AccountingExport::class);
    $today = now()->toDateString();
    $qb = $x->sheet($this->tenant->id, 'quickbooks', $today, $today);
    $sum = fn (array $rows, int $col) => array_sum(array_map(fn ($r) => (float) ($r[$col] ?: 0), $rows));
    expect($qb['headings'][0])->toBe('Journal No')->and(round($sum($qb['rows'], 5), 2))->toBe(round($sum($qb['rows'], 6), 2))->and($sum($qb['rows'], 5))->toBeGreaterThan(0);
    $xero = $x->sheet($this->tenant->id, 'xero', $today, $today);
    expect(round($sum($xero['rows'], 5), 2))->toBe(0.0)->and($xero['headings'][3])->toBe('*AccountCode');
    $sage = $x->sheet($this->tenant->id, 'sage', $today, $today);
    $signed = array_sum(array_map(fn ($r) => ($r[0] === 'JD' ? 1 : -1) * (float) $r[7], $sage['rows']));
    expect(round($signed, 2))->toBe(0.0)->and(collect($sage['rows'])->pluck(0)->unique()->sort()->values()->all())->toBe(['JC', 'JD']);
    $bm = staff($this->owner, 'adwoa', 4, 'branch_manager', ScopeType::Branch, $this->hq->id);
    expect(fn () => $x->download(actorFor($bm), 'xero', $today, $today))->toThrow(AccessDenied::class);
});

it('writes and reads Excel files, and imports with the person’s own column names', function () {
    $bytes = XlsxWriter::bytes([['title' => 'Products', 'headings' => ['Item code', 'Name', 'Price (GHS)'], 'rows' => [['SUGAR-1', 'SUGAR 1 KG & MORE <B>', '22.00'], ['0244', 'Phone code kept as text', '1']]]]);
    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    file_put_contents($path, $bytes);
    $read = SheetFile::xlsx($path);
    expect($read['headings'])->toBe(['item_code', 'name', 'price_ghs'])->and($read['rows'][0]['values'])->toBe(['item_code' => 'SUGAR-1', 'name' => 'SUGAR 1 KG & MORE <B>', 'price_ghs' => '22.00'])
        ->and($read['rows'][1]['values']['item_code'])->toBe('0244')->and($read['rows'][0]['line'])->toBe(2);

    $import = app(ImportService::class)->run($this->o, 'products', $path, 'products.xlsx', ['create_missing' => true, 'industry' => 'shared'], null, true, ['sku' => 'item_code', 'price_retail' => 'price_ghs']);
    expect($import->errors)->toBe([])->and($import->status)->toBe('imported')->and(Product::where('sku', 'SUGAR-1')->value('name'))->toBe('SUGAR 1 KG & MORE <B>');
    @unlink($path);
});

it('shows the messaging, report, API and export screens to the right people', function () {
    $this->actingAs(withMfa($this->owner));
    foreach ([MessagingSetup::getUrl(), MessageTemplateResource::getUrl(), MessageResource::getUrl(), ApiClientResource::getUrl(), ReportBuilder::getUrl(),
        SavedReportResource::getUrl(), AccountingExportPage::getUrl(), '/admin'] as $url) {
        $this->get($url)->assertOk();
    }
    p12Sale($this);
    Livewire::test(ReportBuilder::class)->set('dataset', 'receipts')->set('dimensions', ['branch'])->set('measures', ['count', 'total'])->set('period', 'today')
        ->assertSee('HQ')->call('drillInto', 0)->assertSee('Back to the summary')->call('back');
    Livewire::test(MessagingSetup::class)->set('sms.provider', 'arkesel')->set('sms.sender_id', 'DemoShop')->set('sms.credentials.arkesel.api_key', 'k-1')->set('sms.is_active', true)
        ->call('saveSms');
    expect(MessageProvider::where('channel', 'sms')->sole()->provider)->toBe('arkesel');
    $this->flushSession();
    $this->actingAs(withMfa($this->cashier));
    foreach ([MessagingSetup::getUrl(), ApiClientResource::getUrl(), ReportBuilder::getUrl(), AccountingExportPage::getUrl()] as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('reprints a till receipt as a marked COPY, audited, with a print preview and straight-to-print', function () {
    $sale = p12Sale($this);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    $this->get(route('print.receipt', $sale))->assertOk()->assertSee('COPY')->assertSee('Not the original receipt')->assertSee($sale->receipt_no)
        ->assertSee('MILO 400G')->assertSee('TOTAL')->assertDontSee('window.print(), 300', false);
    $this->get(route('print.receipt', ['sale' => $sale, 'print' => 1]))->assertOk()->assertSee('window.print(), 300', false);
    expect(AuditEvent::where('action', 'sale.receipt_reprinted')->where('subject_id', $sale->id)->count())->toBe(2);
    $this->get(SaleResource::getUrl('view', ['record' => $sale]))->assertOk()->assertSee('Print preview (copy)')->assertSee('Print copy');

    // Someone without sales.view gets nothing.
    $teller = staff($this->owner, 'kofi', 2, 'teller', ScopeType::Till, $this->till->id);
    app('auth')->forgetGuards();
    $this->actingAs($teller, 'web')->get(route('print.receipt', $sale))->assertForbidden();
});

it('keeps the name used at the time on records and prints, hints today\'s name, and lists every rename', function () {
    $sale = p12Sale($this);
    $then = $sale->cashier_name;
    app(AccessService::class)->updateUser($this->o, $this->cashier, ['name' => 'Alberta Agyepong', 'username' => 'alberta'], 'Account handed over');
    expect(People::renamedTo($this->cashier->id, $then))->toBe('Alberta Agyepong')
        ->and(People::renamedTo($this->cashier->id, 'Alberta Agyepong'))->toBeNull()
        ->and(People::history($this->cashier->id))->toHaveCount(1)
        ->and(People::history($this->cashier->id)[0]['name'])->toBe([$then, 'Alberta Agyepong'])
        ->and(People::history($this->cashier->id)[0]['username'])->toBe(['efua', 'alberta']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ListSales::class)->assertSee($then)->assertDontSee('(as ')->assertSee('Account now named Alberta Agyepong');
    $this->get(route('print.receipt', $sale))->assertSee($then)->assertDontSee('Alberta Agyepong');
    Livewire::test(EditUser::class, ['record' => $this->cashier->id])
        ->assertActionVisible('nameHistory');
});

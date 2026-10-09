<?php

use App\Domain\Access\AccessService;
use App\Domain\Api\WebhookDispatcher;
use App\Domain\Devices\DeviceService;
use App\Domain\Fiscal\FiscalService;
use App\Domain\Imports\ImportService;
use App\Domain\Imports\ImportSources;
use App\Domain\Launch\GoLiveService;
use App\Domain\Launch\PilotReport;
use App\Domain\Launch\PracticeCompany;
use App\Domain\Launch\SupportReports;
use App\Domain\Messaging\Messenger;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\GoLive;
use App\Filament\Pages\ImportData;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Models\Account;
use App\Models\BackupRun;
use App\Models\Customer;
use App\Models\GoLivePlan;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupportReport;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->o = actorFor($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->imports = app(ImportService::class);
    $this->golive = app(GoLiveService::class);
});

/** Run an import file as $actor: rows[0] = headings as the old system wrote them; mapping comes from the source preset. */
function migrate($t, string $type, string $source, array $rows, bool $commit = true, array $options = [])
{
    $path = tempnam(sys_get_temp_dir(), 'mig').'.csv';
    $f = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($f, $r, escape: '');
    }
    fclose($f);
    $headings = array_map(fn ($h) => \App\Domain\Imports\CsvFile::key($h), $rows[0]);
    $mapping = ImportSources::suggest($source, $type, $headings, ImportService::importer($type)->headings());

    return $t->imports->run($t->o, $type, $path, 'old.csv', ['source' => $source, 'as_of' => now()->toDateString()] + $options, null, $commit, $mapping);
}

it('migrates books from QuickBooks: chart, items with stock, customers, vendors, balances — and the figures tie', function () {
    // Chart of accounts as QuickBooks exports it: no numbers, QuickBooks types.
    $chart = migrate($this, 'accounts', 'quickbooks', [['Account', 'Type', 'Detail type'],
        ['Ecobank current', 'Bank', 'Checking'], ['Owner capital', 'Equity', "Owner's equity"], ['Delivery van', 'Fixed Assets', 'Vehicles'], ['Generator fuel', 'Expenses', 'Utilities']]);
    expect($chart->status)->toBe('imported')->and($chart->rows_error)->toBe(0)
        ->and(Account::where('name', 'Ecobank current')->first())->type->toBe('asset')->code->toMatch('/^1\d{3}$/')
        ->and(Account::where('name', 'Owner capital')->value('type'))->toBe('equity');

    // Products and Services list: no SKU on one item (made from the name), a sub-item with its category, quantity and cost.
    $items = [['Product/Service Name', 'SKU', 'Type', 'Sales Description', 'Sales Price / Rate', 'Purchase Cost', 'Qty On Hand'],
        ['Drinks:Malta Guinness 330ml', 'MALT-330', 'Inventory', 'Malt drink', '12.00', '12.50', '10'],
        ['Gari 1kg', '', 'Inventory', '', '18.00', '5.00', '20']];
    $p = migrate($this, 'products', 'quickbooks', $items);
    expect($p->status)->toBe('imported')->and($p->rows_error)->toBe(0)
        ->and(Product::where('sku', 'MALT-330')->first())->name->toBe('Malta Guinness 330ml')
        ->and(Product::where('sku', 'MALT-330')->first()->category?->name)->toBe('Drinks')
        ->and(Product::where('sku', 'GARI-1KG')->exists())->toBeTrue();
    expect(migrate($this, 'prices', 'quickbooks', $items)->rows_error)->toBe(0);
    $stock = migrate($this, 'opening_stock', 'quickbooks', [array_merge(['warehouse'], $items[0]), ...array_map(fn ($r) => array_merge([$this->wh->code], $r), array_slice($items, 1))]);
    expect($stock->errors)->toBe([]);

    // Customer and vendor lists, then their open balances (Customer / Vendor Balance Summary: name + Total).
    migrate($this, 'customers', 'quickbooks', [['Customer', 'Phone', 'Email'], ['Kofi Provisions', '0244123456', ''], ['Esi Chop Bar', '0201112222', '']]);
    migrate($this, 'suppliers', 'quickbooks', [['Vendor', 'Phone'], ['Olam Ghana Ltd.', '0302123456']]);
    expect(Supplier::where('name', 'Olam Ghana Ltd.')->value('code'))->toBe('OLAM-GHANA-LTD');
    expect(migrate($this, 'customer_balances', 'quickbooks', [['Customer', 'Total'], ['Kofi Provisions', '1,200.00'], ['Esi Chop Bar', '0.00']])->rows_error)->toBe(0);
    expect(migrate($this, 'supplier_balances', 'quickbooks', [['Vendor', 'Total'], ['Olam Ghana Ltd.', '800.00']])->rows_error)->toBe(0);

    // Loyalty and gift cards from the old till system (other POS export names).
    $pts = migrate($this, 'loyalty_points', 'other_pos', [['Phone', 'Points balance'], ['0244123456', '350']]);
    expect($pts->rows_error)->toBe(0);
    expect(migrate($this, 'loyalty_points', 'other_pos', [['Phone', 'Points balance'], ['0244123456', '350']])->summary['unchanged'])->toBe(1); // once only
    expect(migrate($this, 'gift_cards', 'other_pos', [['Card number', 'Remaining balance'], ['GC-0001-2345', '200.00']])->rows_error)->toBe(0);

    // The old trial balance: control-account rows are skipped (they came in through their own ledgers above).
    $tb = [['Account', 'Debit', 'Credit'], ['Ecobank current', '10,000.00', ''], ['Accounts Receivable (A/R)', '1,200.00', ''], ['Owner capital', '', '10,421.50'], ['Generator fuel', '0', '']];
    Account::where('system_key', 'receivables')->update(['name' => 'Accounts Receivable (A/R)']);
    $ob = migrate($this, 'opening_balances', 'quickbooks', $tb);
    expect($ob->status)->toBe('imported')->and($ob->summary['skipped'])->toBe(1)->and($ob->summary['added'])->toBe(2);
    $again = migrate($this, 'opening_balances', 'quickbooks', $tb);
    expect($again->status)->toBe('failed')->and($again->errors[0]['message'])->toContain('already loaded');

    // Old system's figures on the go-live date; after posting, everything ties and "Opening balances (to clear)" is zero.
    $this->golive->savePlan($this->o, ['sources' => ['quickbooks', 'other_pos']]);
    $this->golive->saveOldTotals($this->o, ['products' => '2', 'stock_value' => '225.00', 'receivables' => '1200', 'payables' => '800', 'gift_cards' => '200', 'loyalty' => '3.50']);
    $r = $this->golive->reconciliation(GoLivePlan::first(), post: true);
    expect($r['problems'])->toBe([])->and($r['ok'])->toBeTrue()->and($r['opening_left'])->toBe(0)
        ->and(collect($r['rows'])->firstWhere('key', 'stock_value')['difference'])->toBe(0);
    $steps = collect($this->golive->steps(GoLivePlan::first()))->keyBy('key');
    expect($steps['products']['done'])->toBeTrue()->and($steps['opening_balances']['done'])->toBeTrue()->and($steps['reconcile']['done'])->toBeTrue()
        ->and($steps['training']['done'])->toBeFalse();

    // A wrong figure shows as a difference.
    $this->golive->saveOldTotals($this->o, ['receivables' => '1500']);
    expect($this->golive->reconciliation(GoLivePlan::first())['ok'])->toBeFalse();
});

it('plans the go-live, runs a parallel run against the old system, goes live with a backup, and can roll back', function () {
    expect(fn () => $this->golive->savePlan($this->o, ['sources' => ['fresh', 'excel']]))->toThrow(DomainException::class, 'Starting fresh')
        ->and(fn () => $this->golive->savePlan($this->o, ['pilot_mode' => 'one_shop', 'pilot_branch_ids' => [$this->hq->id, $this->branches['BA']->id]]))->toThrow(DomainException::class, 'one pilot branch');
    $plan = $this->golive->savePlan($this->o, ['sources' => ['fresh'], 'cutover_mode' => 'parallel', 'pilot_mode' => 'one_shop', 'pilot_branch_ids' => [$this->hq->id],
        'go_live_on' => now()->toDateString(), 'support_email' => 'support@synclytics.test']);
    $steps = collect($this->golive->steps($plan))->keyBy('key');
    expect($steps['opening_balances']['optional'])->toBeTrue()->and($steps->has('parallel'))->toBeTrue(); // fresh start: no balances to bring over

    $cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Branch, $this->hq->id);
    expect(fn () => $this->golive->savePlan(actorFor($cashier), ['cutover_mode' => 'big_bang']))->toThrow(AccessDenied::class);

    // Three days of the parallel run: today's sales here are worked out from the till.
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $device->forceFill(['status' => 'active'])->save();
    $seller = staff($this->owner, 'kojo', 2, 'cashier', ScopeType::Till, $till->id);
    $shift = app(ShiftService::class)->open($device, $seller, 0);
    $unit = $milo->units->first();
    $list = \App\Models\PriceList::where('code', 'RETAIL')->first();
    $price = app(PriceResolver::class)->resolve($list, $milo->id, $unit->id, Qty::toMilli('2'), now())->amount_minor;
    $calc = PromotionEngine::compute(['lines' => [['key' => '0', 'product_id' => $milo->id, 'category_id' => null, 'qty_milli' => Qty::toMilli('2'), 'unit_price_minor' => $price,
        'tax' => $milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]],
        'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    app(SaleService::class)->post($device, $seller, ['id' => (string) Str::uuid7(), 'device_seq' => 1, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $list->id,
        'shift_id' => $shift->id, 'lines' => [['product_id' => $milo->id, 'product_uom_id' => $unit->id, 'qty' => '2', 'unit_price_minor' => $price, 'line_total_minor' => $calc['lines'][0]['total']]],
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]]);

    $this->golive->startParallel($this->o);
    $today = $this->golive->recordParallelDay($this->o, $this->hq, now()->toDateString(), 11_000, 11_000, 1, null);
    expect($today->new_sales_minor)->toBe(11_600)->and($today->new_receipts)->toBe(1)->and($today->differenceMinor())->toBe(600);
    expect($this->golive->parallelClean(GoLivePlan::first()))->toBeFalse();
    $this->golive->recordParallelDay($this->o, $this->hq, now()->subDay()->toDateString(), 0, 0, 0, null);
    $this->golive->recordParallelDay($this->o, $this->hq, now()->subDays(2)->toDateString(), 0, 0, 0, null);
    expect($this->golive->parallelClean(GoLivePlan::first()))->toBeFalse(); // today differs and is not explained
    $this->golive->recordParallelDay($this->o, $this->hq, now()->toDateString(), 11_000, 11_000, 1, 'A Milo rung up only here (old till was off)');
    expect($this->golive->parallelClean(GoLivePlan::first()))->toBeTrue()
        ->and(fn () => $this->golive->recordParallelDay($this->o, $this->hq, now()->addDay()->toDateString(), 1, null, null, null))->toThrow(DomainException::class, 'not happened');

    // The pilot report shows the branch, the parallel difference and the explanation; it is e-mailed to support.
    Mail::fake();
    $report = app(PilotReport::class)->send(GoLivePlan::first());
    expect(implode("\n", $report['lines']))->toContain('HQ')->toContain('A Milo rung up only here')->and($report['sent_to'])->toBe('support@synclytics.test');
    Mail::assertSent(\App\Mail\PlainMessage::class, fn ($m) => $m->hasTo('support@synclytics.test') && str_contains($m->subjectLine, 'Pilot report'));
    expect(app(PilotReport::class)->due())->toBe([]); // sent today already

    // Going live needs today's backup, and a reason while required steps are open.
    expect(fn () => $this->golive->goLive($this->o))->toThrow(DomainException::class, 'go-live backup');
    $run = BackupRun::create(['file_name' => 'synclytics-hq-test.synbak', 'status' => 'ok', 'started_by' => 'Test', 'started_at' => now(), 'finished_at' => now()]);
    GoLivePlan::first()->forceFill(['snapshot_backup_id' => $run->id])->save();
    expect(fn () => $this->golive->goLive($this->o))->toThrow(DomainException::class, 'Not finished yet');
    $this->golive->markStep($this->o, 'training', 'done', 'Quick cards handed out');
    expect(fn () => $this->golive->markStep($this->o, 'reconcile', 'done'))->toThrow(DomainException::class, 'ticked by the system');
    $live = $this->golive->goLive($this->o, 'Owner decided: tills are tested, devices step ticked tomorrow');
    expect($live->status)->toBe('live')->and($live->live_at)->not->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'golive.live')->exists())->toBeTrue();
    $back = $this->golive->rollBack($this->o, 'Printer driver problem at HQ');
    expect($back->status)->toBe('rolled_back')->and($back->rollback_reason)->toContain('Printer');
});

it('makes a practice company that copies the shop, signs staff in with their own password, and never sends anything out', function () {
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(\App\Domain\Inventory\InventoryService::class)->adjust($this->o, $this->wh, $milo, $milo->units->first(), '30', 'opening', '', null, '40.00');
    $cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin($this->o, $cashier, '4826');
    $realProducts = Product::count();

    $r = app(PracticeCompany::class)->create($this->o, [$cashier->id]);
    $practice = $r['tenant'];
    expect($r['problems'])->toBe([]);
    expect($practice->is_practice)->toBeTrue()->and($practice->practice_of)->toBe($this->tenant->id)->and($practice->name)->toContain('PRACTICE')
        ->and($r['copied']['products'])->toBe($realProducts)->and($r['copied']['stock'])->toBeGreaterThan(0)->and($r['copied']['customers'])->toBe(10)
        ->and(collect($r['logins'])->pluck('email')->all())->toContain('efua+practice@demo.test')
        ->and(app(TenantContext::class)->id())->toBe($this->tenant->id); // back in the real company

    $copy = User::withoutGlobalScopes()->where('tenant_id', $practice->id)->where('username', 'efua')->first();
    expect($copy->password)->toBe($cashier->fresh()->password)->and($copy->pin_hash)->toBe($cashier->fresh()->pin_hash)
        ->and(DB::table('role_assignments')->where('user_id', $copy->id)->whereNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('branches')->where('tenant_id', $practice->id)->pluck('code')->sort()->values()->all())->toBe(collect($this->branches)->keys()->sort()->values()->all())
        ->and(DB::table('stock_levels')->where('tenant_id', $practice->id)->sum('on_hand'))->toEqual(30)
        ->and(Customer::count())->toBe(0); // the real company's customers are untouched (and none were copied)

    // Nothing leaves a practice company: no GRA, no messages, no webhooks.
    app(Settings::class)->set($this->o, 'evat.mode', 'realtime');
    DB::table('settings')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $practice->id, 'key' => 'evat.mode', 'value' => json_encode('realtime'), 'version' => 1, 'origin_node' => 'HQ', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(FiscalService::class)->mode($practice->id))->toBe('off')->and(app(FiscalService::class)->mode($this->tenant->id))->toBe('realtime');
    Mail::fake();
    $m = app(Messenger::class)->send($practice->id, 'email', 'kofi@example.test', 'Your receipt', 'Receipt', null, 'Test');
    expect($m->status)->toBe('practice');
    Mail::assertNothingSent();
    app(TenantContext::class)->set($practice->id);
    WebhookEndpoint::create(['tenant_id' => $practice->id, 'url' => 'https://hooks.example.test/x', 'secret' => 'x', 'events' => ['product.updated'], 'is_active' => true]);
    $practiceOwner = \App\Domain\Support\Actor::user(User::withoutGlobalScopes()->where('tenant_id', $practice->id)->where('username', $this->owner->username)->first(), 'web');
    $recorder = app(\App\Domain\Support\Recorder::class);
    $recorder->transaction(fn () => $recorder->save($practiceOwner, Product::where('sku', 'MILO')->first()->forceFill(['name' => 'Milo 400g tin']), 'product.updated'));
    app(WebhookDispatcher::class)->run();
    expect(WebhookDelivery::count())->toBe(0);
    app(TenantContext::class)->set($this->tenant->id);

    // Practice staff see the banner on every page; the go-live tools belong to the real company only.
    $this->actingAs(withMfa(User::withoutGlobalScopes()->where('tenant_id', $practice->id)->where('username', $this->owner->username)->first()));
    $this->get('/admin')->assertOk()->assertSee('PRACTICE COMPANY');
    $this->get(GoLive::getUrl())->assertForbidden();
    $this->flushSession();
    app(TenantContext::class)->set($this->tenant->id);

    // One practice company at a time; archiving stops its logins.
    expect(fn () => app(PracticeCompany::class)->create($this->o, []))->toThrow(DomainException::class, 'already is a practice company');
    app(PracticeCompany::class)->archive($this->o, $practice);
    expect(User::withoutGlobalScopes()->where('tenant_id', $practice->id)->where('is_active', true)->count())->toBe(0)
        ->and(PracticeCompany::of($this->tenant->id))->toBeNull();
});

it('takes problem reports from the back office and the till and e-mails them to support', function () {
    Mail::fake();
    $this->golive->savePlan($this->o, ['support_email' => 'help@synclytics.test']);
    $r = app(SupportReports::class)->report($this->o, 'back_office', 'Posting the stock count said something went wrong.', '/admin/stock-counts/1', ['browser' => 'Edge']);
    expect($r->number)->toStartWith('SUP-')->and($r->sent_at)->not->toBeNull()->and($r->context['server']['version'])->toBe(config('erp.version'));
    Mail::assertSent(\App\Mail\PlainMessage::class, fn ($m) => $m->hasTo('help@synclytics.test') && str_contains($m->text, 'stock count'));
    expect(fn () => app(SupportReports::class)->report($this->o, 'back_office', 'x'))->toThrow(DomainException::class, 'few words');

    // From a till: device + cashier signed in by PIN.
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$device, $code] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'Till 1', 'kind' => 'pos']);
    $cashier = staff($this->owner, 'esi', 2, 'cashier', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin($this->o, $cashier, '4826');
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['device_id' => $device->id, 'code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $login = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json();
    app('auth')->forgetGuards();
    $this->withToken($login['token'])->getJson('/api/v1/pos/bootstrap')->assertOk()->assertJsonPath('company.practice', false);
    $this->withToken($login['token'])->postJson('/api/v1/pos/problems', ['message' => 'The receipt printer prints blank paper.', 'screen' => 'Receipt', 'context' => ['app' => '0.25.0']])
        ->assertCreated()->assertJsonPath('sent', true);
    $till = SupportReport::where('source', 'till')->first();
    expect($till->user_name)->toBe($cashier->name)->and($till->device_id)->toBe($device->id)->and($till->context['app'])->toBe('0.25.0');

    // Closing needs support.view: the cashier cannot, the owner can.
    expect(fn () => app(SupportReports::class)->close(actorFor($cashier), $till, 'Fixed'))->toThrow(AccessDenied::class);
    expect(app(SupportReports::class)->close($this->o, $till, 'Paper was in upside down')->status)->toBe('closed');
});

it('shows the go-live page, problem reports and the import sources to the right people', function () {
    $this->actingAs(withMfa($this->owner));
    $this->get(GoLive::getUrl())->assertOk()->assertSee('Checklist')->assertSee('Practice company');
    $this->get(SupportReportResource::getUrl())->assertOk();
    $this->get(ImportData::getUrl().'?type=opening_balances&source=quickbooks')->assertOk()->assertSee('Balances as of')->assertSee('QuickBooks');
    $this->get('/admin')->assertOk()->assertSee('Report a problem');
    \Livewire\Livewire::test(\App\Livewire\ReportProblem::class)->set('message', 'hi')->call('send')->assertHasErrors('message')
        ->set('message', 'The dashboard shows yesterday twice.')->set('page', '/admin')->call('send')->assertHasNoErrors();
    expect(SupportReport::where('source', 'back_office')->value('page'))->toBe('/admin');
    $this->flushSession();
    $cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Branch, $this->hq->id);
    $this->actingAs(withMfa($cashier));
    $this->get(GoLive::getUrl())->assertForbidden();
    $this->get(SupportReportResource::getUrl())->assertForbidden();
});

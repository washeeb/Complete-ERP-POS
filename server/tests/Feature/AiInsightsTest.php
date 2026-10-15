<?php

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiReply;
use App\Domain\Ai\AiUnavailable;
use App\Domain\Ai\Copilot;
use App\Domain\Ai\InvoiceScanner;
use App\Domain\Ai\ProductWriter;
use App\Domain\Ai\Providers\OllamaProvider;
use App\Domain\Ai\Providers\OpenAiCompatibleProvider;
use App\Domain\Analytics\CashFlowForecaster;
use App\Domain\Analytics\CustomerInsights;
use App\Domain\Analytics\DemandForecaster;
use App\Domain\Analytics\GhanaHolidays;
use App\Domain\Analytics\InsightAlerts;
use App\Domain\Analytics\LossPrevention;
use App\Domain\Analytics\PricingAdvisor;
use App\Domain\Analytics\StockHealth;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\Copilot as CopilotPage;
use App\Filament\Pages\Insights;
use App\Filament\Resources\AiAlerts\AiAlertResource;
use App\Filament\Resources\AiRequests\AiRequestResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Models\AiAlert;
use App\Models\AiDocument;
use App\Models\AiRequest;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeAiProvider;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->o = actorFor($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->whA = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    $this->sugar = makeProduct($this->owner, 'SUGAR', 'Sugar 1kg', retail: '20.00');
    $inv = app(InventoryService::class);
    $inv->adjust($this->o, $this->wh, $this->milo, $this->milo->units->first(), '40', 'opening', '', null, '40.00');
    $inv->adjust($this->o, $this->wh, $this->sugar, $this->sugar->units->first(), '500', 'opening', '', null, '19.50');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->clones = 0;
    $this->ama = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
    AiGateway::fake(null);
});

afterEach(fn () => AiGateway::fake(null));

/** A real till sale now. */
function aiSale($t, array $items, array $extra = []): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => [$p, $qty]) {
        $unit = $p->units->first();
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => $p->category_id, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
            'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price];
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]] + $extra)['sale'];
}

/** Copy a sale (with lines and payments) to another time / cashier / customer — history for the analytics, without stock moves. */
function cloneSale($t, Sale $s, CarbonImmutable $at, array $over = [], int $sign = 1): string
{
    $id = (string) Str::uuid7();
    $n = ++$t->clones;
    $row = (array) DB::table('sales')->where('id', $s->id)->first();
    $money = fn ($v) => $v === null ? null : $sign * (int) $v;
    DB::table('sales')->insert(array_merge($row, ['id' => $id, 'device_seq' => 100000 + $n, 'receipt_no' => $row['receipt_no']."-C{$n}", 'occurred_at' => $at->utc(), 'received_at' => $at->utc(),
        'payload_hash' => hash('sha256', $id), 'net_minor' => $money($row['net_minor']), 'tax_minor' => $money($row['tax_minor']), 'total_minor' => $money($row['total_minor']),
        'paid_minor' => $money($row['paid_minor'])], $over));
    foreach (DB::table('sale_lines')->where('sale_id', $s->id)->get() as $l) {
        $l = (array) $l;
        DB::table('sale_lines')->insert(array_merge($l, ['id' => (string) Str::uuid7(), 'sale_id' => $id, 'qty' => $sign * (float) $l['qty'], 'net_minor' => $sign * $l['net_minor'],
            'tax_minor' => $sign * $l['tax_minor'], 'line_total_minor' => $sign * $l['line_total_minor']]));
    }
    foreach (DB::table('sale_payments')->where('sale_id', $s->id)->get() as $p) {
        DB::table('sale_payments')->insert(array_merge((array) $p, ['id' => (string) Str::uuid7(), 'sale_id' => $id, 'amount_minor' => $sign * $p->amount_minor, 'tendered_minor' => $sign * $p->tendered_minor]));
    }

    return $id;
}

it('forecasts demand from the weekday pattern, paydays and public holidays', function () {
    expect(GhanaHolidays::easter(2026)->toDateString())->toBe('2026-04-05')
        ->and(GhanaHolidays::is(CarbonImmutable::parse('2026-04-03')))->toBeTrue() // Good Friday
        ->and(GhanaHolidays::is(CarbonImmutable::parse('2026-12-04')))->toBeTrue() // Farmers' Day: first Friday of December
        ->and(GhanaHolidays::is(CarbonImmutable::parse('2026-03-06')))->toBeTrue()
        ->and(GhanaHolidays::is(CarbonImmutable::parse('2026-03-07')))->toBeFalse();

    // 12 weeks: Saturdays sell 20, other days 10; paydays (25th–3rd) sell half as much again.
    $from = CarbonImmutable::parse('2026-06-01');
    $days = [];
    for ($i = 0; $i < 84; $i++) {
        $d = $from->addDays($i);
        $days[$d->toDateString()] = ($d->isSaturday() ? 20 : 10) * (DemandForecaster::isPayday($d) ? 1.5 : 1);
    }
    $f = DemandForecaster::model($days, $from, 84);
    $sat = DemandForecaster::predict($f, CarbonImmutable::parse('2026-09-12'));
    $mon = DemandForecaster::predict($f, CarbonImmutable::parse('2026-09-14'));
    expect($f['weekday'][6])->toBeGreaterThan($f['weekday'][1] * 1.4)->and($f['payday'])->toBeGreaterThan(1.3)
        ->and($sat)->toBeGreaterThan($mon * 1.4)
        ->and(DemandForecaster::predict($f, CarbonImmutable::parse('2026-09-28')))->toBeGreaterThan($mon); // payday Monday

    // In the shop: 2 Milo a day for four weeks, 40 on hand → about 20 days of cover; a reorder is suggested.
    $sale = aiSale($this, [[$this->milo, '2']]);
    $today = CarbonImmutable::now($this->tenant->timezone ?: 'UTC')->startOfDay();
    for ($i = 1; $i <= 28; $i++) {
        cloneSale($this, $sale, $today->subDays($i)->addHours(10));
    }
    $milo = collect(app(DemandForecaster::class)->forWarehouse($this->wh->id))->firstWhere('sku', 'MILO');
    expect($milo['avg_daily'])->toBeGreaterThan(1.5)->toBeLessThan(2.5)->and($milo['on_hand'])->toBe(38.0)
        ->and($milo['days_cover'])->toBeGreaterThan(14)->toBeLessThan(26)->and($milo['suggested_order'])->toBeGreaterThanOrEqual(0.0)
        ->and($milo['forecast_28'])->toBeGreaterThan(40);
});

it('finds dead stock, overstock, stock that will expire unsold and transfers between branches', function () {
    $inv = app(InventoryService::class);
    $old = makeProduct($this->owner, 'OLD', 'Old stock', retail: '5.00');
    $inv->adjust($this->o, $this->wh, $old, $old->units->first(), '30', 'opening', '', null, '2.00');
    $yog = makeProduct($this->owner, 'YOG', 'Yoghurt', retail: '8.00');
    app(CatalogService::class)->saveProduct($this->o, $yog, ['track_batches' => true]);
    $yog = $yog->fresh();
    $inv->adjust($this->o, $this->wh, $yog, $yog->units->first(), '50', 'opening', '', null, '5.00', 'Y1', now()->addDays(10)->toDateString());

    $today = CarbonImmutable::now()->startOfDay();
    $sugar = aiSale($this, [[$this->sugar, '1']]); // 1 sugar a day against 499 on hand
    $yogSale = aiSale($this, [[$yog, '1']]);
    for ($i = 1; $i <= 28; $i++) {
        cloneSale($this, $sugar, $today->subDays($i)->addHours(9));
        cloneSale($this, $yogSale, $today->subDays($i)->addHours(11));
        // Branch A sells 3 sugar a day and has none.
        cloneSale($this, $sugar, $today->subDays($i)->addHours(12), ['warehouse_id' => $this->whA->id, 'branch_id' => $this->branches['BA']->id]);
        cloneSale($this, $sugar, $today->subDays($i)->addHours(13), ['warehouse_id' => $this->whA->id, 'branch_id' => $this->branches['BA']->id]);
        cloneSale($this, $sugar, $today->subDays($i)->addHours(14), ['warehouse_id' => $this->whA->id, 'branch_id' => $this->branches['BA']->id]);
    }
    $h = app(StockHealth::class)->report($this->tenant->id, [$this->wh->id, $this->whA->id]);
    expect(collect($h['dead'])->pluck('sku')->all())->toContain('OLD')->not->toContain('SUGAR')
        ->and(collect($h['dead'])->firstWhere('sku', 'OLD')['value_minor'])->toBe(6000)
        ->and(collect($h['overstock'])->firstWhere('sku', 'SUGAR')['days_cover'])->toBeGreaterThan(400)
        ->and(collect($h['expiry'])->firstWhere('sku', 'YOG')['at_risk'])->toBeGreaterThan(35.0) // ~49 on hand, ~10 sell before expiry
        ->and(collect($h['transfers'])->firstWhere('sku', 'SUGAR'))->toMatchArray(['from' => $this->wh->name, 'to' => $this->whA->name])
        ->and(collect($h['transfers'])->firstWhere('sku', 'SUGAR')['qty'])->toBeGreaterThanOrEqual(60.0);
});

it('scores cashiers against each other and raises loss-prevention, margin and stock alerts', function () {
    $sale = aiSale($this, [[$this->sugar, '1']]);
    $today = CarbonImmutable::now()->startOfDay();
    $cashiers = [];
    foreach (['kofi', 'abena', 'yaw', 'kwesi'] as $i => $name) {
        $cashiers[$name] = staff($this->owner, $name, 2, 'teller', ScopeType::Till, $this->till->id);
        for ($n = 0; $n < 25; $n++) {
            cloneSale($this, $sale, $today->subDays($n % 20 + 1)->addHours(8 + $i), ['cashier_id' => $cashiers[$name]->id, 'cashier_name' => $cashiers[$name]->name]);
        }
    }
    for ($n = 0; $n < 6; $n++) { // Kwesi refunds without receipts
        cloneSale($this, $sale, $today->subDays($n + 1)->addHours(15), ['cashier_id' => $cashiers['kwesi']->id, 'cashier_name' => 'Kwesi', 'kind' => 'return', 'original_sale_id' => null], -1);
    }
    cloneSale($this, $sale, $today->subDays(2)->addHours(15), ['cashier_id' => $cashiers['abena']->id, 'cashier_name' => 'Abena', 'kind' => 'return', 'original_sale_id' => $sale->id], -1);

    $rows = collect(app(LossPrevention::class)->cashiers($this->tenant->id, $today->subDays(30), $today->addDay()));
    expect($rows->first()['name'])->toBe('Kwesi')->and($rows->first()['risk'])->toBeGreaterThanOrEqual(50)
        ->and($rows->first()['flags'])->toContain('refunds without a receipt')->and($rows->firstWhere('name', 'Kofi')['risk'])->toBe(0)
        ->and($rows->firstWhere('name', 'Efua')['risk'])->toBeNull(); // 1 receipt: listed, not scored

    // Sugar: 20.00 incl. 21.9 % tax → about 16.41 before tax against 19.50 cost: sold below cost.
    $m = collect(app(PricingAdvisor::class)->margins($this->tenant->id))->firstWhere('sku', 'SUGAR');
    expect($m['below_cost'])->toBeTrue()->and($m['price_for_target_minor'])->toBeGreaterThan(2000);

    $open = app(InsightAlerts::class)->run($this->tenant->id);
    expect($open['loss'] ?? 0)->toBe(1)->and($open['margin'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and(AiAlert::where('kind', 'loss')->sole()->subject_id)->toBe($cashiers['kwesi']->id);
    // Dismissed alerts stay quiet; alerts whose cause has gone are resolved.
    $alert = AiAlert::where('kind', 'margin')->where('subject_id', $this->sugar->id)->sole();
    app(InsightAlerts::class)->handle($this->o, $alert, 'dismissed', 'Promotion price agreed');
    app(InsightAlerts::class)->run($this->tenant->id);
    expect(AiAlert::where('kind', 'margin')->where('subject_id', $this->sugar->id)->where('status', 'open')->exists())->toBeFalse()
        ->and(fn () => app(InsightAlerts::class)->handle($this->o, $alert->fresh(), 'resolved'))->toThrow(DomainException::class);
});

it('segments customers, spots regulars who stopped coming and products bought together', function () {
    $today = CarbonImmutable::now()->startOfDay();
    $kojo = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Kojo Mensah', 'phone' => '0243334444']);
    $both = aiSale($this, [[$this->milo, '1'], [$this->sugar, '1']], ['customer_id' => $this->ama->id]);
    for ($i = 1; $i <= 6; $i++) {
        cloneSale($this, $both, $today->subDays($i * 7)->addHours(10), ['customer_id' => $this->ama->id]);
    }
    foreach ([200, 190, 180, 170] as $d) { // Kojo came every 10 days, then stopped
        cloneSale($this, $both, $today->subDays($d)->addHours(10), ['customer_id' => $kojo->id]);
    }
    $ci = app(CustomerInsights::class);
    expect(collect($ci->churnRisk($this->tenant->id))->pluck('name')->all())->toBe(['Kojo Mensah'])
        ->and(collect($ci->rfm($this->tenant->id))->firstWhere('name', 'Ama Owusu')['segment'])->toBeIn(['champions', 'loyal', 'promising'])
        ->and(collect($ci->rfm($this->tenant->id))->firstWhere('name', 'Kojo Mensah')['segment'])->toBeIn(['at_risk', 'hibernating'])
        ->and(collect([$ci->baskets($this->tenant->id)[0]['a'], $ci->baskets($this->tenant->id)[0]['b']])->sort()->values()->all())->toBe(['Milo 400g', 'Sugar 1kg'])
        ->and($ci->baskets($this->tenant->id)[0]['together'])->toBeGreaterThanOrEqual(7)
        ->and($ci->nextBestOffer($this->tenant->id, $this->ama->id))->toBeNull(); // she already buys both

    $f = app(CashFlowForecaster::class)->forecast($this->tenant->id);
    expect($f['weeks'])->toHaveCount(8)->and($f['weeks'][0]['till_minor'])->toBeGreaterThan(0)
        ->and($f['weeks'][1]['opening_minor'])->toBe($f['weeks'][0]['closing_minor']);
});

it('keeps AI off by default, respects the budget, and talks to Ollama and Claude in their own formats', function () {
    $gw = app(AiGateway::class);
    $settings = app(Settings::class);
    expect(fn () => $gw->provider($this->tenant->id))->toThrow(AiUnavailable::class, 'off')
        ->and($gw->unavailableReason($this->tenant->id))->toContain('off');

    $settings->set($this->o, 'ai.provider', 'claude');
    config(['ai.anthropic_key' => '']);
    expect(fn () => $gw->provider($this->tenant->id))->toThrow(AiUnavailable::class, 'ANTHROPIC_API_KEY');
    config(['ai.anthropic_key' => 'test-key']);
    expect(fn () => $gw->provider($this->tenant->id))->toThrow(AiUnavailable::class, 'budget is 0');
    $settings->set($this->o, 'ai.monthly_cap_usd', 1);
    expect($gw->provider($this->tenant->id)->isCloud())->toBeTrue()
        ->and($gw->provider($this->tenant->id, personal: true))->toBeInstanceOf(OllamaProvider::class); // personal data stays local

    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hello']], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]])]);
    $reply = $gw->chat($this->o, 'copilot', $gw->provider($this->tenant->id), 'sys', [['role' => 'user', 'content' => 'Hi']]);
    expect($reply->text)->toBe('Hello')->and(AiRequest::sole()->cost_micro_usd)->toBe(4500) // 1000 × 3 + 100 × 15
        ->and($gw->spentThisMonth($this->tenant->id))->toBe(4500);
    DB::table('ai_requests')->update(['cost_micro_usd' => 1_000_000]);
    expect(fn () => $gw->provider($this->tenant->id))->toThrow(AiUnavailable::class, 'used up');
    expect(fn () => $settings->set($this->o, 'ai.provider', 'chatgpt'))->toThrow(DomainException::class);

    // Ollama: tool calls come back as objects.
    $settings->set($this->o, 'ai.provider', 'ollama');
    Http::fake(['127.0.0.1:11434/*' => Http::response(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'cash_position', 'arguments' => []]]]],
        'prompt_eval_count' => 50, 'eval_count' => 5])]);
    $p = $gw->provider($this->tenant->id);
    $r = $p->chat('sys', [['role' => 'user', 'content' => 'cash?']], [['name' => 'cash_position', 'description' => 'x', 'parameters' => ['type' => 'object', 'properties' => (object) []]]]);
    expect($p->isCloud())->toBeFalse()->and($r->toolCalls[0]['name'])->toBe('cash_position')->and($p->costMicroUsd(50, 5))->toBe(0);
    Http::assertSent(fn ($req) => str_contains($req->url(), '/api/chat') && $req['tools'][0]['function']['name'] === 'cash_position');
});

it('answers questions through read-only reports within the person’s access, masking names for cloud models', function () {
    $sale = aiSale($this, [[$this->milo, '2']], ['customer_id' => $this->ama->id]);
    app(AutoPoster::class)->run($this->tenant->id);
    DB::table('customer_balances')->insert(['customer_id' => $this->ama->id, 'tenant_id' => $this->tenant->id, 'balance_minor' => 12345, 'updated_at' => now()]);
    app(Settings::class)->set($this->o, 'ai.personal_data_local', false);

    $fake = new FakeAiProvider([
        new AiReply('', [['id' => 't1', 'name' => 'sales_summary', 'arguments' => ['group_by' => 'branch']], ['id' => 't2', 'name' => 'customer_balances', 'arguments' => []]]),
        new AiReply('HQ sold the most. [Customer 1] owes the most.'),
    ], cloud: true);
    AiGateway::fake($fake);
    $r = app(Copilot::class)->ask($this->o, 'Which branch sold most, and who owes us? Call Ama on 0241112222');
    expect($r['answer'])->toBe('HQ sold the most. Ama Owusu owes the most.')->and($r['tables'])->toHaveCount(2)
        ->and($r['tables'][0]['rows'][0][0])->toContain('HQ')->and($r['tables'][1]['rows'][0][0])->toBe('Ama Owusu')
        ->and($fake->sent())->not->toContain('Ama Owusu')->not->toContain('0241112222')->toContain('[Customer 1]')->toContain('[phone]')
        ->and(collect($fake->calls[0]['tools'])->pluck('name')->all())->toContain('sales_summary', 'cash_position')
        ->and(AiRequest::count())->toBe(2);

    // A branch manager sees their branch only, and no company-wide money.
    $bm = staff($this->owner, 'adwoa', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id);
    $fake = new FakeAiProvider([new AiReply('', [['id' => 't1', 'name' => 'sales_summary', 'arguments' => ['group_by' => 'branch', 'branch' => 'HQ']]]), new AiReply('No access to HQ.')]);
    AiGateway::fake($fake);
    $r = app(Copilot::class)->ask(actorFor($bm), 'How did HQ do?');
    expect($r['tables'])->toBe([])->and($fake->calls[1]['messages'][2]['content'])->toContain('do not have access')
        ->and(collect($fake->calls[0]['tools'])->pluck('name')->all())->not->toContain('cash_position')->not->toContain('customer_balances');

    // Personal data kept local: with a cloud model, the conversation moves to the local model for customer details.
    app(Settings::class)->set($this->o, 'ai.personal_data_local', true);
    AiGateway::fake(new FakeAiProvider([new AiReply('', [['id' => 't1', 'name' => 'customer_balances', 'arguments' => []]])], cloud: true));
    $r = app(Copilot::class)->ask($this->o, 'Who owes us?');
    expect($r['notice'])->toContain('kept on your server')->and($r['tables'][0]['rows'][0][0])->toBe('Ama Owusu');

    // Without AI: quick questions still answer; the copilot is for managers.
    AiGateway::fake(null);
    expect(fn () => app(Copilot::class)->ask($this->o, 'Sales?'))->toThrow(AiUnavailable::class)
        ->and(app(Copilot::class)->quick($this->o, 'sales_month_branch')['rows'][0][0])->toContain('HQ')
        ->and(fn () => app(Copilot::class)->quick(actorFor($this->cashier), 'cash'))->toThrow(AccessDenied::class);
});

it('reads a supplier invoice into a bill form for a person to check, and drafts shop descriptions', function () {
    Storage::fake('local');
    $supplier = app(SupplierService::class)->save($this->o, new Supplier, ['code' => 'NESTLE', 'name' => 'Nestlé Ghana Ltd', 'payment_terms_days' => 30, 'tax_no' => 'C0001234567']);
    $path = InvoiceScanner::store(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='), 'png');
    $fake = new FakeAiProvider([new AiReply("```json\n".json_encode(['supplier_name' => 'NESTLE GHANA LIMITED', 'supplier_tax_no' => 'C000 1234 567', 'invoice_number' => 'INV-889',
        'invoice_date' => now()->subDay()->toDateString(), 'due_date' => null, 'lines' => [['description' => 'Delivery charge', 'qty' => 1, 'unit_price' => 150, 'line_total' => 150]]])."\n```")]);
    AiGateway::fake($fake);
    $doc = app(InvoiceScanner::class)->scan($this->o, $this->hq, $path, 'invoice.png');
    expect($doc->status)->toBe('read')->and($doc->form['supplier_id'])->toBe($supplier->id)->and($doc->form['supplier_invoice_no'])->toBe('INV-889')
        ->and($doc->form['expense_lines'][0])->toMatchArray(['description' => 'Delivery charge', 'amount' => '150.00'])
        ->and(SupplierBill::count())->toBe(0) // nothing entered until a person presses Create
        ->and($fake->calls[0]['images'][0]['mime'])->toBe('image/png')
        ->and(fn () => app(InvoiceScanner::class)->scan(actorFor($this->cashier), $this->hq, $path, 'x.png'))->toThrow(AccessDenied::class);

    AiGateway::fake(new FakeAiProvider([new AiReply('"Rich chocolate malt drink in a 400 g tin, made to mix with milk or water."')]));
    $text = app(ProductWriter::class)->draft($this->o, $this->milo);
    expect($text)->toBe('Rich chocolate malt drink in a 400 g tin, made to mix with milk or water.')->and($this->milo->fresh()->description)->toBeNull();
});

it('shows the insight and AI screens to managers only', function () {
    app(InsightAlerts::class)->run($this->tenant->id);
    $this->actingAs(withMfa($this->owner));
    foreach (array_keys(Insights::TABS) as $tab) {
        $this->get(Insights::getUrl(['tab' => $tab]))->assertOk();
    }
    foreach ([AiAlertResource::getUrl(), CopilotPage::getUrl(), AiRequestResource::getUrl(), SupplierBillResource::getUrl(), ProductResource::getUrl('edit', ['record' => $this->milo])] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(CopilotPage::getUrl())->assertSee('switch on a local model');
    $doc = AiDocument::create(['tenant_id' => $this->tenant->id, 'kind' => 'supplier_invoice', 'file_path' => 'ai-documents/x.png', 'file_name' => 'x.png', 'status' => 'read',
        'created_by_name' => 'Owner', 'form' => ['supplier_invoice_no' => 'INV-889', 'branch_id' => $this->hq->id, 'bill_date' => now()->toDateString(), 'stock_lines' => [],
            'expense_lines' => [['account_id' => null, 'description' => 'Delivery charge', 'amount' => '150.00', 'tax_group_id' => null]]]]);
    $this->get(SupplierBillResource::getUrl('create', ['scan' => $doc->id]))->assertOk()->assertSee('Filled in from the scanned invoice')->assertSee('INV-889');
    Livewire::test(CopilotPage::class)->call('quick', 'sales_month_branch')->assertSee('Sales')->call('ask')->assertOk();
    $this->flushSession();
    $this->actingAs(withMfa($this->cashier));
    foreach ([Insights::getUrl(), CopilotPage::getUrl(), AiAlertResource::getUrl(), AiRequestResource::getUrl()] as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('uses another cloud model (Gemini, Groq, OpenRouter …) in the OpenAI format, with the key from the server settings', function () {
    $gw = app(AiGateway::class);
    $settings = app(Settings::class);
    $settings->set($this->o, 'ai.provider', 'cloud');
    config(['ai.cloud_key' => '']);
    expect(fn () => $gw->provider($this->tenant->id))->toThrow(AiUnavailable::class, 'AI_CLOUD_API_KEY');
    config(['ai.cloud_key' => 'test-key']);

    // Google Gemini by default, its usual model; no monthly budget needed (free plans).
    $p = $gw->provider($this->tenant->id);
    expect($p)->toBeInstanceOf(OpenAiCompatibleProvider::class)->and($p->name())->toBe('gemini')->and($p->model())->toBe('gemini-2.5-flash')->and($p->isCloud())->toBeTrue()
        ->and($gw->provider($this->tenant->id, personal: true))->toBeInstanceOf(OllamaProvider::class); // personal data still stays local

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => null,
        'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'cash_position', 'arguments' => '{"branch":"HQ"}']]]]]],
        'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 6]])]);
    $r = $p->chat('sys', [['role' => 'user', 'content' => 'cash?'],
        ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c0', 'name' => 'sales_summary', 'arguments' => []]]],
        ['role' => 'tool', 'tool_call_id' => 'c0', 'name' => 'sales_summary', 'content' => '{}']],
        [['name' => 'cash_position', 'description' => 'x', 'parameters' => ['type' => 'object', 'properties' => (object) []]]]);
    expect($r->toolCalls[0])->toBe(['id' => 'call_1', 'name' => 'cash_position', 'arguments' => ['branch' => 'HQ']])->and($r->tokensIn)->toBe(40)->and($p->costMicroUsd(40, 6))->toBe(0);
    Http::assertSent(fn ($req) => $req->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
        && $req->hasHeader('Authorization', 'Bearer test-key') && $req['model'] === 'gemini-2.5-flash'
        && $req['tools'][0]['function']['name'] === 'cash_position' && $req['messages'][2]['tool_calls'][0]['function']['arguments'] === '{}'
        && $req['messages'][3]['tool_call_id'] === 'c0' && $req['messages'][3]['content'] === '{}' && $req['messages'][1]['content'] === 'cash?');

    // An invoice photo goes with the question as an image part; JSON answers are asked for in the OpenAI way.
    $p->chat('sys', [['role' => 'user', 'content' => 'Read this']], images: [['mime' => 'image/png', 'data' => 'AAA']], json: true);
    Http::assertSent(fn ($req) => ($req['messages'][1]['content'][1]['image_url']['url'] ?? null) === 'data:image/png;base64,AAA'
        && $req['messages'][1]['content'][0]['text'] === 'Read this' && $req['response_format'] === ['type' => 'json_object']);

    // Another service and a named model; the free-plan limit and a refused key are explained.
    $settings->set($this->o, 'ai.cloud_service', 'groq');
    $settings->set($this->o, 'ai.cloud_model', 'llama-3.1-8b-instant');
    $p = $gw->provider($this->tenant->id);
    expect($p->name())->toBe('groq')->and($p->model())->toBe('llama-3.1-8b-instant');
    Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'rate limit']], 429)]);
    expect(fn () => $p->chat('sys', [['role' => 'user', 'content' => 'hi']]))->toThrow(AiUnavailable::class, 'limit is reached');

    // Any other OpenAI-compatible service needs its address.
    $settings->set($this->o, 'ai.cloud_service', 'custom');
    expect(fn () => $gw->provider($this->tenant->id))->toThrow(AiUnavailable::class, 'service address');
    $settings->set($this->o, 'ai.cloud_url', 'https://llm.example.com/v1');
    expect($gw->provider($this->tenant->id)->name())->toBe('custom');
});

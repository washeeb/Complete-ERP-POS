<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Inventory\InventoryService;
use App\Domain\Launch\TestTradingReset;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\DomainException;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Device;
use App\Models\GoLivePlan;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany('OheneBa Ventures');
    $branches = setUpBranches($this->owner);
    $this->o = actorFor($this->owner);
    $this->wh = Warehouse::where('branch_id', $branches['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust($this->o, $this->wh, $milo, $milo->units->first(), '50', 'opening', '', null, '40.00');
    app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);

    // Test trading at the till: a shift and a sale, then the books posted.
    $till = Till::where('branch_id', $branches['HQ']->id)->first();
    [$device] = app(DeviceService::class)->register($this->o, ['branch_id' => $branches['HQ']->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $device->forceFill(['status' => 'active'])->save();
    $cashier = staff($this->owner, 'janet', 2, 'teller', ScopeType::Till, $till->id);
    $shift = app(ShiftService::class)->open($device, $cashier, 0);
    $retail = PriceList::where('code', 'RETAIL')->first();
    $unit = $milo->units->first();
    $price = app(PriceResolver::class)->resolve($retail, $milo->id, $unit->id, Qty::toMilli('2'), now())->amount_minor;
    $calc = PromotionEngine::compute(['lines' => [['key' => '0', 'product_id' => $milo->id, 'category_id' => null, 'qty_milli' => 2000, 'unit_price_minor' => $price,
        'tax' => $milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]],
        'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    app(SaleService::class)->post($device, $cashier, ['id' => (string) Str::uuid7(), 'device_seq' => 1, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $retail->id,
        'shift_id' => $shift->id, 'lines' => [['product_id' => $milo->id, 'product_uom_id' => $unit->id, 'qty' => '2', 'unit_price_minor' => $price, 'line_total_minor' => $calc['lines'][0]['total']]],
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]]);
    app(ShiftService::class)->close($device, $cashier, 0);
    app(AutoPoster::class)->run($this->tenant->id);
    DB::table('document_sequences')->insert(['tenant_id' => $this->tenant->id, 'node' => 'HQ', 'doc_type' => 'INV', 'last_value' => 7]);
});

it('shows the test trading without changing anything, then clears it and keeps products, customers, staff and devices', function () {
    $counts = app(TestTradingReset::class)->counts($this->tenant->id);
    expect($counts)->toHaveKeys(['sales', 'sale_lines', 'shifts', 'stock_movements', 'stock_levels', 'journal_entries', 'journal_lines']);

    Artisan::call('erp:clear-test-trading');
    expect(DB::table('sales')->count())->toBe(1);
    expect(Artisan::call('erp:clear-test-trading', ['--apply' => true, '--confirm' => 'Wrong name', '--skip-backup' => true]))->toBe(1);
    expect(DB::table('sales')->count())->toBe(1);

    $code = Artisan::call('erp:clear-test-trading', ['--apply' => true, '--confirm' => Tenant::where('is_practice', false)->orderBy('created_at')->value('name'), '--skip-backup' => true]);
    expect($code)->toBe(0, Artisan::output());
    foreach (['sales', 'sale_lines', 'sale_payments', 'shifts', 'stock_movements', 'stock_levels', 'journal_entries', 'journal_lines', 'gl_postings'] as $t) {
        expect(DB::table($t)->count())->toBe(0, $t);
    }
    expect(Product::where('sku', 'MILO')->exists())->toBeTrue()->and(Customer::where('name', 'Ama Owusu')->exists())->toBeTrue()
        ->and(User::where('username', 'janet')->exists())->toBeTrue()->and(Device::count())->toBe(1)
        ->and(DB::table('document_sequences')->where('doc_type', 'INV')->exists())->toBeFalse()          // invoice numbers start again
        ->and(DB::table('document_sequences')->where('doc_type', 'CUS')->exists())->toBeTrue()           // customer numbers keep counting
        ->and(AuditEvent::where('action', 'company.test_trading_cleared')->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'sale.posted')->orWhere('action', 'shift.opened')->exists())->toBeTrue(); // the audit log keeps the test period

    // The append-only guards are back on.
    expect(fn () => DB::transaction(fn () => DB::table('audit_events')->limit(1)->delete()))->toThrow(QueryException::class);
    $milo = Product::where('sku', 'MILO')->first();
    app(InventoryService::class)->adjust($this->o, $this->wh, $milo, $milo->units->first(), '30', 'opening', '', null, '40.00'); // the opening stock can be loaded afresh
    expect(DB::table('stock_movements')->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->delete()))->toThrow(QueryException::class);
});

it('refuses once the company has gone live', function () {
    GoLivePlan::withoutGlobalScopes()->updateOrInsert(['tenant_id' => $this->tenant->id], ['id' => (string) Str::uuid7(), 'status' => 'live', 'live_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => app(TestTradingReset::class)->clear(actorFor($this->owner)))->toThrow(DomainException::class, 'gone live');
    expect(DB::table('sales')->count())->toBe(1);
});

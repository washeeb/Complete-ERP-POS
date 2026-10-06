<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\CustomerService;
use App\Domain\Customers\Loyalty;
use App\Domain\Customers\StoredValues;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AuditVerifier;
use App\Domain\Support\DomainException;
use App\Domain\Sync\NodeSetup;
use App\Domain\Sync\SyncEngine;
use App\Domain\Sync\SyncFile;
use App\Domain\Sync\SyncServer;
use App\Domain\Sync\SyncSigner;
use App\Domain\Sync\Transport\SyncTransport;
use App\Models\Customer;
use App\Models\Device;
use App\Models\GoodsReceipt;
use App\Models\OutboxEvent;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReviewItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockLevel;
use App\Models\StoredValue;
use App\Models\Supplier;
use App\Models\SyncInboxEvent;
use App\Models\SyncPeer;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\AccessCatalogSync;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec §4.3 between nodes: HQ runs on the main test database, branch server "B01" on a second
 * PostgreSQL database. They exchange events through SyncServer exactly as over HTTP (the HTTP
 * layer itself is tested at the end of this file).
 */
final class Nodes
{
    public static bool $migrated = false;

    public static ?string $hqPeerId = null;   // HQ's record of the branch

    public static ?string $branchPeerId = null; // branch's record of HQ

    public static string $tenantId = '';

    /** Run $fn as node HQ or B01 (own database, node id, role, request-scoped services). */
    public static function on(string $node, callable $fn): mixed
    {
        $prev = [DB::getDefaultConnection(), config('erp.node.id'), config('erp.node.role')];
        DB::setDefaultConnection($node === 'HQ' ? 'pgsql' : 'node_b');
        config(['erp.node.id' => $node === 'HQ' ? 'TEST' : 'B01', 'erp.node.role' => $node === 'HQ' ? 'hq' : 'branch']);
        app()->forgetScopedInstances();
        app(TenantContext::class)->set(self::$tenantId ?: null);
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($prev[0]);
            config(['erp.node.id' => $prev[1], 'erp.node.role' => $prev[2]]);
            app()->forgetScopedInstances();
            app(TenantContext::class)->set(self::$tenantId ?: null);
        }
    }
}

/** The branch's view of HQ: calls the HQ SyncServer inside the HQ database context. */
final class InProcessTransport implements SyncTransport
{
    public int $pushCalls = 0;

    public function hello(SyncPeer $peer): array
    {
        return Nodes::on('HQ', fn () => app(SyncServer::class)->hello(SyncPeer::withoutGlobalScopes()->find(Nodes::$hqPeerId)));
    }

    public function push(SyncPeer $peer, array $events): array
    {
        $this->pushCalls++;
        $events = json_decode(json_encode($events), true); // as if it crossed the wire

        return Nodes::on('HQ', fn () => app(SyncServer::class)->receive(SyncPeer::withoutGlobalScopes()->find(Nodes::$hqPeerId), $events));
    }

    public function pull(SyncPeer $peer, int $after, int $limit): array
    {
        $r = Nodes::on('HQ', fn () => app(SyncServer::class)->serve(SyncPeer::withoutGlobalScopes()->find(Nodes::$hqPeerId), $after, $limit));

        return json_decode(json_encode($r), true);
    }
}

function branchSync(): array
{
    return Nodes::on('B01', fn () => app(SyncEngine::class)->run(SyncPeer::withoutGlobalScopes()->find(Nodes::$branchPeerId)));
}

beforeEach(function () {
    if (! Nodes::$migrated) {
        Artisan::call('migrate:fresh', ['--database' => 'node_b', '--force' => true]);
        Nodes::$migrated = true;
    }
    DB::connection('node_b')->beginTransaction();
    app()->instance(SyncTransport::class, $this->transport = new InProcessTransport);

    // --- HQ: company, branches, catalog, stock at branch A's warehouse --------------------------
    [$this->tenant, $this->owner] = installCompany();
    Nodes::$tenantId = $this->tenant->id;
    $this->branches = setUpBranches($this->owner);
    $this->whA = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $this->tillA = Till::where('branch_id', $this->branches['BA']->id)->orderBy('code')->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00', carton: ['12', '672.00']);
    $this->bread = makeProduct($this->owner, 'BREAD', 'Bread', retail: '25.00', tax: 'EXEMPT');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->whA, $this->milo, $this->milo->units->firstWhere('is_base', true), '10', 'opening', '');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->whA, $this->bread, $this->bread->units->first(), '1', 'opening', '');
    $this->cashier = staff($this->owner, 'kofi', 2, 'cashier', ScopeType::Branch, $this->branches['BA']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '482613');
    $this->it = staff($this->owner, 'itadmin', 4, 'it_admin');

    // --- pair the branch server with HQ ------------------------------------------------------------
    $pair = app(NodeSetup::class)->addBranchNode(actorFor($this->owner), 'B01', 'Branch A server');
    Nodes::$hqPeerId = $pair['peer']->id;
    Nodes::on('B01', function () use ($pair) {
        app(AccessCatalogSync::class)->syncPermissions(); // what `migrate --seed` does on a branch server
        Nodes::$branchPeerId = app(NodeSetup::class)->joinHq('http://hq.test', $pair['hq_node_id'], $pair['tenant_id'], $pair['secret'])->id;
    });
    $this->first = branchSync();
});

afterEach(function () {
    DB::connection('node_b')->rollBack();
    Nodes::$tenantId = '';
});

/** A sale made on the branch server's own till (online to the branch, HQ may be unreachable). */
function branchSale(callable $items): Sale
{
    return Nodes::on('B01', function () use ($items) {
        $owner = User::where('username', 'like', 'owner%')->first();
        $till = Till::whereHas('branch', fn ($q) => $q->where('code', 'BA'))->orderBy('code')->first();
        $device = Device::first() ?? app(DeviceService::class)->register(actorFor($owner),
            ['branch_id' => $till->branch_id, 'till_id' => $till->id, 'name' => 'Branch till', 'kind' => 'pos'])[0];
        $device->forceFill(['status' => 'active'])->save();
        $cashier = User::where('username', 'kofi')->first();
        $shift = app(ShiftService::class)->current($device) ?? app(ShiftService::class)->open($device, $cashier, 0);
        $list = PriceList::where('code', 'RETAIL')->first();
        $lines = [];
        $tot = ['net_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0];
        foreach ($items() as [$sku, $qty]) {
            $p = Product::with('units', 'taxGroup.rates')->where('sku', $sku)->first();
            $unit = $p->units->firstWhere('is_base', true);
            $price = app(PriceResolver::class)->resolve($list, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
            $t = TaxCalculator::line(Qty::divRound(Qty::toMilli($qty) * $price, 1000), $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->all(), true);
            $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price, 'line_total_minor' => $t['gross']];
            $tot['net_minor'] += $t['net'];
            $tot['tax_minor'] += $t['tax'];
            $tot['total_minor'] += $t['gross'];
        }
        $seq = (int) Sale::where('device_id', $device->id)->max('device_seq') + 1;

        return app(SaleService::class)->post($device, $cashier, [
            'id' => (string) Str::uuid7(), 'device_seq' => $seq, 'occurred_at' => now()->toIso8601String(),
            'price_list_id' => $list->id, 'shift_id' => $shift->id, 'lines' => $lines, 'totals' => $tot,
            'payments' => [['method' => 'cash', 'amount_minor' => $tot['total_minor'], 'tendered_minor' => $tot['total_minor']]],
        ])['sale'];
    });
}

function hqOnHand(Product $p, Warehouse $wh): string
{
    return (string) StockLevel::where('warehouse_id', $wh->id)->where('product_id', $p->id)->value('on_hand');
}

it('gives a new branch server everything it needs in one full sync', function () {
    $failures = Nodes::on('B01', fn () => SyncInboxEvent::where('status', 'failed')->get(['aggregate_type', 'event_type', 'last_error'])->toArray());
    expect($this->first['error'])->toBeNull()->and($failures)->toBe([]);

    Nodes::on('B01', function () {
        expect(Product::where('sku', 'MILO')->first()->units)->toHaveCount(2)
            ->and(Product::where('sku', 'MILO')->first()->prices)->toHaveCount(2)
            ->and(User::where('username', 'kofi')->first()->pin_hash)->not->toBeNull()   // can sign in on a branch till
            ->and(User::where('username', 'kofi')->first()->getAttributes()['app_authentication_secret'])->toBeNull() // node-local secret never travels
            ->and(Role::where('key', 'cashier')->first()->permissionKeys())->toContain('pos.access')
            ->and((string) StockLevel::where('warehouse_id', $this->whA->id)->where('product_id', $this->milo->id)->value('on_hand'))->toBe('10.000')
            ->and(app(StockLedger::class)->check())->toBe([])
            ->and(app(AuditVerifier::class)->verify(Nodes::$tenantId)['ok'])->toBeTrue();
    });
});

it('brings a branch sale, its shift, device and stock movements to HQ', function () {
    $sale = branchSale(fn () => [['MILO', '3'], ['BREAD', '1']]);
    $r = branchSync();

    expect($r['pushed'])->toBeGreaterThan(3)->and($r['error'])->toBeNull()
        ->and(Sale::find($sale->id)?->receipt_no)->toBe($sale->receipt_no)
        ->and(Sale::find($sale->id)->lines)->toHaveCount(2)
        ->and(hqOnHand($this->milo, $this->whA))->toBe('7.000')
        ->and(hqOnHand($this->bread, $this->whA))->toBe('0.000')
        ->and(app(StockLedger::class)->check())->toBe([]);
});

it('sends HQ price changes down to the branch at the next sync', function () {
    $retail = PriceList::where('code', 'RETAIL')->first();
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->milo, ['prices' => [
        ['price_list_id' => $retail->id, 'uom_id' => $this->milo->units->firstWhere('is_base', true)->uom_id, 'min_qty' => '1', 'amount' => '61.50'],
    ]]);

    branchSync();

    Nodes::on('B01', fn () => expect(Product::where('sku', 'MILO')->first()->prices->pluck('amount_minor')->all())->toBe([6150]));
});

it('enforces HQ wins: master data cannot be edited on a branch server', function () {
    Nodes::on('B01', function () {
        $owner = User::where('username', 'like', 'owner%')->first();
        app(CatalogService::class)->saveProduct(actorFor($owner), Product::where('sku', 'MILO')->first(), ['name' => 'Renamed at branch']);
    });
})->throws(DomainException::class, 'managed at head office');

it('stages manual groups for preview, and applies them only when accepted', function () {
    Nodes::on('B01', fn () => SyncPeer::find(Nodes::$branchPeerId)->forceFill(['groups' => ['catalog' => 'manual'] + SyncPeer::find(Nodes::$branchPeerId)->groups])->save());
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->milo, ['name' => 'Milo 400g (new pack)']);

    $r = branchSync();

    expect($r['staged'])->toBeGreaterThan(0);
    Nodes::on('B01', function () {
        expect(Product::where('sku', 'MILO')->value('name'))->toBe('Milo 400g');
        $peer = SyncPeer::find(Nodes::$branchPeerId);
        $staged = $peer->inbox()->where('status', 'staged')->pluck('id')->all();
        app(SyncEngine::class)->decide($peer, $staged, true, null);
        expect(Product::where('sku', 'MILO')->value('name'))->toBe('Milo 400g (new pack)');
    });
});

it('is idempotent: re-sending everything changes nothing (duplicate replay)', function () {
    branchSale(fn () => [['MILO', '1']]);
    branchSync();
    // Branch forgets HQ acknowledged anything and pushes the lot again; HQ re-serves everything too.
    Nodes::on('B01', fn () => SyncPeer::find(Nodes::$branchPeerId)->forceFill(['last_pushed_seq' => 0])->save());
    Nodes::on('B01', fn () => app(SyncEngine::class)->resetForFullResync(SyncPeer::find(Nodes::$branchPeerId)));

    branchSync();

    expect(Sale::count())->toBe(1)->and(hqOnHand($this->milo, $this->whA))->toBe('9.000');
    Nodes::on('B01', fn () => expect((string) StockLevel::where('warehouse_id', $this->whA->id)->where('product_id', $this->milo->id)->value('on_hand'))->toBe('9.000')
        ->and(app(StockLedger::class)->check())->toBe([]));
});

it('flags negative stock when HQ and the branch both took the last unit', function () {
    // HQ writes off the last loaf while the branch sells it.
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->whA, $this->bread, $this->bread->units->first(), '-1', 'adjustment', 'damaged');
    branchSale(fn () => [['BREAD', '1']]);

    branchSync();

    expect(hqOnHand($this->bread, $this->whA))->toBe('-1.000')
        ->and(ReviewItem::where('type', 'negative_stock')->exists())->toBeTrue();
    Nodes::on('B01', fn () => expect((string) StockLevel::where('warehouse_id', $this->whA->id)->where('product_id', $this->bread->id)->value('on_hand'))->toBe('-1.000'));
});

it('keeps going when one event cannot be applied, and applies it once the cause is fixed (partial failure)', function () {
    // HQ runs newer code with a permission the branch server does not know yet.
    DB::table('permissions')->insert(['key' => 'future.feature', 'module' => 'core', 'label' => 'Future', 'min_level' => 1, 'is_technical' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('permission_role')->insert(['role_id' => Role::where('key', 'owner')->value('id'), 'permission_key' => 'future.feature']);
    app(Authorizer::class)->forget();
    $role = Role::where('key', 'supervisor')->first();
    app(AccessService::class)->saveRole(actorFor($this->owner), $role, [], [...$role->permissionKeys(), 'future.feature']);
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->bread, ['name' => 'Sliced bread']);

    $r = branchSync();

    expect($r['failed'])->toBe(1);
    Nodes::on('B01', fn () => expect(Product::where('sku', 'BREAD')->value('name'))->toBe('Sliced bread')
        ->and(SyncInboxEvent::where('status', 'failed')->first()->last_error)->toContain('Update this node'));

    // The branch server is upgraded (the permission now exists there) → the next sync applies it.
    Nodes::on('B01', fn () => DB::table('permissions')->insert(['key' => 'future.feature', 'module' => 'core', 'label' => 'Future', 'min_level' => 1, 'is_technical' => false, 'created_at' => now(), 'updated_at' => now()]));
    branchSync();
    Nodes::on('B01', fn () => expect(SyncInboxEvent::where('status', 'failed')->count())->toBe(0)
        ->and(ReviewItem::where('type', 'sync_failed')->value('status'))->toBe('resolved')
        ->and(Role::where('key', 'supervisor')->first()->permissionKeys())->toContain('future.feature'));
});

it('moves branch sales to HQ on a USB file when there is no link', function () {
    $sale = branchSale(fn () => [['MILO', '2']]);
    $file = Nodes::on('B01', fn () => app(SyncFile::class)->export(SyncPeer::find(Nodes::$branchPeerId)));

    $r = app(SyncFile::class)->import($file);

    expect($r['applied'])->toBeGreaterThan(0)->and(Sale::find($sale->id))->not->toBeNull()
        ->and(hqOnHand($this->milo, $this->whA))->toBe('8.000');
    // Altered file is refused.
    $tampered = json_decode(gzdecode($file), true);
    $tampered['events'][0]['payload']['snapshot']['row']['name'] = 'evil';
    expect(fn () => app(SyncFile::class)->import(gzencode(json_encode($tampered))))->toThrow(DomainException::class, 'signature');
});

it('refuses HTTP sync calls without the right signature, and serves them with it', function () {
    $peer = SyncPeer::find(Nodes::$hqPeerId);
    $secret = $peer->secret;
    $time = time();
    $path = '/api/v1/sync/pull';
    $headers = fn ($sig) => ['HTTP_X_SYNC_NODE' => 'B01', 'HTTP_X_SYNC_TENANT' => $peer->tenant_id, 'HTTP_X_SYNC_TIME' => (string) $time,
        'HTTP_X_SYNC_SIGNATURE' => $sig, 'HTTP_ACCEPT' => 'application/json'];

    $this->call('GET', $path.'?after=0&limit=5', [], [], [], $headers('nope'))->assertUnauthorized();
    $ok = $this->call('GET', $path.'?after=0&limit=5', [], [], [], $headers(SyncSigner::sign($secret, $time, 'GET', $path, '')))
        ->assertOk()->json();
    expect($ok['events'])->toHaveCount(5)->and($ok['has_more'])->toBeTrue();
});

it('receives an HQ purchase order on the branch server and brings the GRN, order status and cost back to HQ', function () {
    $ctn = $this->milo->units->firstWhere('is_base', false);
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'Nestlé Ghana']);
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($this->owner), new PurchaseOrder,
        ['supplier_id' => $supplier->id, 'warehouse_id' => $this->whA->id, 'lines' => [['product_uom_id' => $ctn->id, 'qty' => '2', 'unit_cost' => '600.00']]]);
    app(PurchaseOrderService::class)->submit(actorFor($this->owner), $po);
    $approver = staff($this->owner, 'gm', 5, 'general_manager');
    app(PurchaseOrderService::class)->approve(actorFor($approver), $po->fresh());
    staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['BA']->id);
    branchSync(); // the order, supplier and the storekeeper reach the branch server

    $grnId = Nodes::on('B01', function () use ($po) {
        $branchPo = PurchaseOrder::with('lines')->findOrFail($po->id);
        $keeper = User::where('username', 'yaw')->first();

        return app(GoodsReceiptService::class)->receive(actorFor($keeper), $branchPo,
            ['lines' => [['purchase_order_line_id' => $branchPo->lines[0]->id, 'qty' => '2']]])->id;
    });
    $r = branchSync();

    $grn = GoodsReceipt::find($grnId);
    expect($r['error'])->toBeNull()
        ->and($grn?->status)->toBe('posted')->and($grn->number)->toStartWith('GRN-B01-')->and($grn->lines)->toHaveCount(1)
        ->and($po->fresh()->status)->toBe('received')
        ->and(hqOnHand($this->milo, $this->whA))->toBe('34.000') // 10 + 2 × 12
        ->and((string) StockLevel::where('warehouse_id', $this->whA->id)->where('product_id', $this->milo->id)->value('avg_cost_minor'))->toBe('5000.0000')
        ->and(app(StockLedger::class)->check())->toBe([]);
});

it('relays a branch server’s stock and warehouse events to the other branch servers, never back to the sender', function () {
    branchSale(fn () => [['MILO', '1']]);
    branchSync();

    $relayed = OutboxEvent::where('origin_node', 'TEST')->whereRaw("payload->>'relayed_from' = 'B01'")->get();
    expect($relayed->pluck('aggregate_type')->unique()->values()->all())->toBe(['stock_movement_batch']) // sales themselves go up only
        ->and(collect(app(SyncServer::class)->serve(SyncPeer::find(Nodes::$hqPeerId), 0, 1000)['events'])->pluck('id'))->not->toContain($relayed->first()->id);

    $other = app(NodeSetup::class)->addBranchNode(actorFor($this->owner), 'B02', 'Branch B server')['peer'];
    expect(collect(app(SyncServer::class)->serve($other, 0, 1000)['events'])->pluck('id'))->toContain($relayed->first()->id);
});

it('merges customer edits field by field between servers, and logs a real conflict', function () {
    $svc = app(CustomerService::class);
    $c = $svc->save(actorFor($this->owner), new Customer, ['name' => 'Kofi Provisions', 'phone' => '0245551234', 'credit_limit_minor' => 500_000, 'payment_terms_days' => 30]);
    branchSync();

    // Branch changes the phone and the notes; HQ changes the email and the notes at the same time.
    Nodes::on('B01', function () use ($c) {
        $owner = User::where('username', 'like', 'owner%')->first();
        app(CustomerService::class)->save(actorFor($owner), Customer::find($c->id), ['phone' => '0209998888', 'notes' => 'Delivers Tuesdays']);
        expect(fn () => app(CustomerService::class)->setCredit(actorFor($owner), Customer::find($c->id), 9_000_000, 30, false))
            ->toThrow(DomainException::class, 'head office');
    });
    $this->travel(1)->seconds();
    $svc->save(actorFor($this->owner), $c->fresh(), ['email' => 'kofi@provisions.test', 'notes' => 'Prefers Monday']);
    branchSync();

    $hq = Customer::find($c->id);
    $branch = Nodes::on('B01', fn () => Customer::find($c->id));
    expect($hq->phone)->toBe('+233209998888')->and($hq->email)->toBe('kofi@provisions.test')->and($hq->notes)->toBe('Prefers Monday')
        ->and($branch->phone)->toBe('+233209998888')->and($branch->email)->toBe('kofi@provisions.test')->and($branch->notes)->toBe('Prefers Monday')
        ->and($branch->credit_limit_minor)->toBe(500_000)
        ->and(ReviewItem::where('type', 'customer_conflict')->where('summary', 'like', '%notes%Delivers Tuesdays%')->exists())->toBeTrue()
        ->and(ReviewItem::where('type', 'customer_conflict')->where('summary', 'like', '%phone%')->exists())->toBeFalse();
});

it('brings a branch credit sale to the customer account at HQ', function () {
    $c = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Stores', 'credit_limit_minor' => 500_000, 'payment_terms_days' => 14]);
    branchSync();
    $sale = Nodes::on('B01', function () use ($c) {
        $owner = User::where('username', 'like', 'owner%')->first();
        DB::transaction(fn () => app(CustomerLedger::class)->post(actorFor($owner), Customer::find($c->id), 'pos_sale', 5800, 'GHS',
            'sale', (string) Str::uuid7(), 'BA-T01A-000009', now()->addDays(14)));

        return true;
    });
    branchSync();

    expect((int) DB::table('customer_balances')->where('customer_id', $c->id)->value('balance_minor'))->toBe(5800)
        ->and(app(CustomerLedger::class)->check())->toBe([]);
});

it('carries loyalty points and gift card balances between servers', function () {
    $c = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
    $card = app(StoredValues::class)->sellCard(actorFor($this->owner), 5000, 'cash', null);
    branchSync();

    Nodes::on('B01', function () use ($c, $card) {
        $owner = User::where('username', 'like', 'owner%')->first();
        DB::transaction(function () use ($owner, $c, $card) {
            app(Loyalty::class)->post(actorFor($owner), Customer::find($c->id), 'earn', 120, 'sale', (string) Str::uuid7(), 'BA-T01A-000001');
            app(StoredValues::class)->redeem(actorFor($owner), StoredValue::find($card->id), 2000, 'sale', (string) Str::uuid7(), 'BA-T01A-000001', false);
        });
    });
    branchSync();

    expect(app(Loyalty::class)->balance($c->id))->toBe(120)->and($card->balanceMinor())->toBe(3000)
        ->and(app(Loyalty::class)->check())->toBe([])->and(app(StoredValues::class)->check())->toBe([]);
});

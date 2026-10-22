<?php

use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Warehouse;
use App\Security\RoleCatalog;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Owner request 2026-10-22 ("I keep seeing 403 Forbidden"): for every role, signed in for the company or for a branch,
 * each link the back office shows (side menu, dashboard) must open — no 403 and no error. Links the person may not use
 * are not shown. Slow (every role × every page): run with CRAWL=1.
 */
dataset('roles', fn () => collect(array_keys(RoleCatalog::ROLES))->reject(fn ($k) => $k === 'superadmin')
    ->flatMap(fn ($k) => $k === 'owner' ? [[$k, 'tenant']] : [[$k, 'tenant'], [$k, 'branch']])->all());

it('opens every link the back office shows', function (string $key, string $scope) {
    [, $owner] = installCompany();
    $branches = setUpBranches($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $level = RoleCatalog::ROLES[$key][1];
    $type = $scope === 'branch' ? ScopeType::Branch : ScopeType::Tenant;
    $user = withMfa(staff($owner, 'crawler', max(1, $level), $key, $type, $scope === 'branch' ? $branches['HQ']->id : null));

    $bad = [];
    // Each page in its own savepoint: a query that fails inside a page is reported, and does not spoil the next page.
    $visit = function (string $path) use (&$bad) {
        DB::beginTransaction();
        $r = $this->get($path);
        if ($r->isRedirect()) {
            $r = $this->get($r->headers->get('Location'));
        }
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $bad[] = "{$path} → a database error inside the page";
        }
        DB::rollBack();
        if ($r->status() === 403 || $r->status() >= 500) {
            $bad[] = "{$path} → {$r->status()}";
        }

        return $r;
    };
    $this->actingAs($user, 'web');
    $home = $visit('/admin');
    preg_match_all('#href="(?:https?://[^"/]+)?(/admin/[^"\#?]*)"#', $home->getContent(), $m);
    foreach (array_unique($m[1]) as $path) {
        if (! str_contains($path, '/logout') && $path !== '/admin/') {
            $visit($path);
        }
    }
    expect($bad)->toBe([]);
})->with('roles')->skip(fn () => ! getenv('CRAWL'), 'run with CRAWL=1 (slow)');

/**
 * One level deeper, with sample data: from every page the menu shows, each kind of link on it (a row, New, Print, a
 * report) — one of each address pattern — must open too. Representative roles, company-wide and at a branch.
 */
dataset('deep roles', [['owner', 'tenant'], ['general_manager', 'branch'], ['branch_manager', 'branch'], ['supervisor', 'branch'], ['storekeeper', 'branch'],
    ['stock_controller', 'tenant'], ['purchasing_officer', 'branch'], ['accountant', 'tenant'], ['auditor', 'branch'], ['pharmacist', 'branch'], ['finance_manager', 'tenant']]);

it('opens the rows, buttons and print links of every list', function (string $key, string $scope) {
    [, $owner] = installCompany();
    $branches = setUpBranches($owner);
    foreach (['erp:demo-catalog', 'erp:demo-purchasing', 'erp:demo-sales'] as $cmd) {
        expect(Artisan::call($cmd, ['--as' => $owner->email]))->toBe(0, Artisan::output());
    }
    expect(DB::table('products')->count())->toBeGreaterThan(10)->and(DB::table('suppliers')->count())->toBeGreaterThan(0)->and(DB::table('customers')->count())->toBeGreaterThan(0);
    // A few documents, so their screens and print links are opened too.
    $hq = Warehouse::where('branch_id', $branches['HQ']->id)->first();
    $supplier = Supplier::first();
    $unit = SupplierProduct::where('supplier_id', $supplier->id)->value('product_uom_id');
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($owner), new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $hq->id,
        'lines' => [['product_uom_id' => $unit, 'qty' => '5', 'unit_cost' => '10.00']]]);
    app(PurchaseOrderService::class)->submit(actorFor($owner), $po);
    $adj = app(StockAdjustmentService::class)->create(actorFor($owner), $hq, ['reason_code' => 'found']);
    app(StockAdjustmentService::class)->addLine(actorFor($owner), $adj, ['product_id' => Product::where('track_batches', false)->value('id'), 'mode' => 'add', 'qty' => '2']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $type = $scope === 'branch' ? ScopeType::Branch : ScopeType::Tenant;
    $user = $key === 'owner' ? withMfa($owner)
        : withMfa(staff($owner, 'crawler', max(1, RoleCatalog::ROLES[$key][1]), $key, $type, $scope === 'branch' ? $branches['HQ']->id : null));

    $bad = [];
    $seen = [];
    $links = fn (string $html) => preg_match_all('#href="(?:https?://[^"/]+)?(/(?:admin|print)/[^"\#]*)"#', $html, $m) ? array_unique(array_map('html_entity_decode', $m[1])) : [];
    $visit = function (string $path) use (&$bad, &$seen) {
        $pattern = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '{id}', strtok($path, '?'));
        if (isset($seen[$pattern]) || str_contains($path, '/logout') || $path === '/admin/') {
            return null;
        }
        $seen[$pattern] = true;
        DB::beginTransaction();
        $r = $this->get($path);
        if ($r->isRedirect()) {
            $r = $this->get($r->headers->get('Location'));
        }
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $bad[] = "{$pattern} → a database error inside the page";
        }
        DB::rollBack();
        if ($r->status() === 403 || $r->status() >= 500) {
            $bad[] = "{$pattern} → {$r->status()}";
        }

        return $r->status() === 200 ? $r->getContent() : null;
    };
    $this->actingAs($user, 'web');
    foreach ($links($visit('/admin') ?? '') as $page) {
        $html = $visit($page);
        foreach ($html ? $links($html) : [] as $deeper) {
            $visit($deeper);
        }
    }
    expect($bad)->toBe([]);
})->with('deep roles')->skip(fn () => ! getenv('CRAWL'), 'run with CRAWL=1 (slow)');

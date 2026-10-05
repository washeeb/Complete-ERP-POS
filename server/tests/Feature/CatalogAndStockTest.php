<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\AuditEvent;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
});

it('gives a new company starter units, an unverified Ghana tax set-up and two price lists', function () {
    $std = TaxGroup::where('code', 'STD')->first();

    expect(Uom::pluck('code')->all())->toContain('PC', 'CTN', 'KG')
        ->and($std->rates->pluck('rate_bp')->sum())->toBe(2000)
        ->and($std->isVerified())->toBeFalse()
        ->and(PriceList::where('is_default', true)->value('code'))->toBe('RETAIL');
});

it('saves a product with units, barcodes and tiered prices in one audited change', function () {
    $p = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00', carton: ['24', '1000.00'], wholesale: '42.50');

    expect($p->units)->toHaveCount(2)
        ->and($p->barcodes)->toHaveCount(2)
        ->and($p->barcodes->pluck('product_uom_id')->unique())->toHaveCount(2) // one per unit: piece and carton
        ->and($p->prices)->toHaveCount(4)
        ->and(AuditEvent::where('action', 'product.created')->where('subject_id', $p->id)->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'product.details_changed')->where('subject_id', $p->id)->exists())->toBeTrue();
});

it('resolves quantity tiers and scheduled prices', function () {
    $p = makeProduct($this->owner, 'SUGAR-1KG', 'Sugar 1kg', retail: '20.00');
    $retail = PriceList::where('code', 'RETAIL')->first();
    $pcs = $p->units->firstWhere('is_base', true);
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $p, ['prices' => [
        ['price_list_id' => $retail->id, 'uom_id' => $pcs->uom_id, 'min_qty' => '1', 'amount' => '20.00'],
        ['price_list_id' => $retail->id, 'uom_id' => $pcs->uom_id, 'min_qty' => '10', 'amount' => '18.50'],
        ['price_list_id' => $retail->id, 'uom_id' => $pcs->uom_id, 'min_qty' => '1', 'amount' => '15.00',
            'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(3)->toDateTimeString()],
    ]]);
    $r = app(PriceResolver::class);

    expect($r->resolve($retail, $p->id, $pcs->id, 1000, now())->amount_minor)->toBe(2000)
        ->and($r->resolve($retail, $p->id, $pcs->id, 12000, now())->amount_minor)->toBe(1850)
        ->and($r->resolve($retail, $p->id, $pcs->id, 1000, now()->addDays(2))->amount_minor)->toBe(1500)  // promo
        ->and($r->resolve($retail, $p->id, $pcs->id, 12000, now()->addDays(2))->amount_minor)->toBe(1850) // tier still wins at 12
        ->and($r->resolve($retail, $p->id, $pcs->id, 1000, now()->addDays(4))->amount_minor)->toBe(2000);
});

it('refuses duplicate barcodes across products and price edits without pricing rights', function () {
    makeProduct($this->owner, 'A1', 'Item A', retail: '1.00', baseBarcode: '111222333');
    expect(fn () => makeProduct($this->owner, 'B1', 'Item B', retail: '1.00', baseBarcode: '111222333'))
        ->toThrow(DomainException::class, 'already belongs to Item A');

    $wm = staff($this->owner, 'wm', 4, 'warehouse_manager');
    $p = Product::where('sku', 'A1')->first();
    expect(fn () => app(CatalogService::class)->saveProduct(actorFor($wm), $p, ['prices' => []]))->toThrow(AccessDenied::class);
    app(CatalogService::class)->saveProduct(actorFor($wm), $p, ['name' => 'Item A (renamed)']);
    expect($p->fresh()->name)->toBe('Item A (renamed)');
});

it('keeps stock as a ledger: opening, adjustments, carton conversion, projection equals ledger', function () {
    $p = makeProduct($this->owner, 'OIL-1L', 'Cooking oil 1L', retail: '30.00', carton: ['12', '340.00']);
    $inv = app(InventoryService::class);
    $pc = $p->units->firstWhere('is_base', true);
    $ctn = $p->units->firstWhere('is_base', false);

    $inv->adjust(actorFor($this->owner), $this->wh, $p, $ctn, '5', 'opening', '');           // 60 pieces
    $inv->adjust(actorFor($this->owner), $this->wh, $p, $pc, '-3', 'adjustment', 'damaged', 'Leaking');

    expect((string) StockLevel::where('warehouse_id', $this->wh->id)->where('product_id', $p->id)->value('on_hand'))->toBe('57.000')
        ->and(app(StockLedger::class)->check($this->tenant->id))->toBe([]);
    expect(fn () => $inv->adjust(actorFor($this->owner), $this->wh, $p, $pc, '10', 'opening', ''))
        ->toThrow(DomainException::class, 'already has stock history');
    expect(fn () => $inv->adjust(actorFor($this->owner), $this->wh, $p, $pc, '-100', 'adjustment', 'theft'))
        ->toThrow(DomainException::class, 'Not enough');
});

it('refuses editing or deleting stock movements at the database', function () {
    $p = makeProduct($this->owner, 'X1', 'X', retail: '1.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $p, $p->units->first(), '5', 'opening', '');

    DB::table('stock_movements')->update(['qty_base' => '500']);
})->throws(Exception::class, 'append-only');

it('limits stock adjustments to the warehouses a person is scoped to', function () {
    $p = makeProduct($this->owner, 'Y1', 'Y', retail: '1.00');
    $other = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $mgr = staff($this->owner, 'bm', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id);

    app(InventoryService::class)->adjust(actorFor($mgr), $other, $p, $p->units->first(), '5', 'opening', '');
    expect(fn () => app(InventoryService::class)->adjust(actorFor($mgr), $this->wh, $p, $p->units->first(), '5', 'opening', ''))
        ->toThrow(AccessDenied::class);
});

it('respects unit decimal places', function () {
    $p = makeProduct($this->owner, 'Z1', 'Z', retail: '1.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $p, $p->units->first(), '1.5', 'opening', '');
})->throws(DomainException::class, 'decimal place');

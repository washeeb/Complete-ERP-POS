<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Support\AccessDenied;
use App\Filament\Resources\PriceLists\PriceListResource;
use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\Industry;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\Plain;
use Filament\Facades\Filament;

/** Owner 2026-11-09: a price manager for the supermarket branch could not price; they price their own industry. */
beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Supermarket Bogre', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
    ]);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    $this->gm = staff($this->owner, 'smgm', 5, 'general_manager', ScopeType::Branch, $this->b['G1']->id);
    $this->product = fn (string $name, string $industry) => app(CatalogService::class)->saveProduct(actorFor($this->owner), new Product, [
        'name' => $name, 'base_uom_id' => Uom::where('code', 'PC')->value('id'), 'tax_group_id' => TaxGroup::where('code', 'STD')->value('id'),
        'track_stock' => true, 'is_active' => true, 'industry_id' => $industry]);
});

it('lets a branch price manager price their own industry, not the other one', function () {
    expect(app(Authorizer::class)->industryIds($this->gm->fresh(), 'pricing.manage'))->toBe([$this->grocery->id]);

    $rice = ($this->product)('Rice 5kg', $this->grocery->id)->fresh(['units']);
    $drug = ($this->product)('Paracetamol', $this->pharmacy->id)->fresh(['units']);
    $retail = PriceList::where('code', 'RETAIL')->first();
    $prices = fn (Product $p) => ['prices' => [['price_list_id' => $retail->id, 'uom_id' => $p->base_uom_id, 'min_qty' => '1', 'amount' => '90']]];

    app(CatalogService::class)->saveProduct(actorFor($this->gm), $rice, $prices($rice));
    expect(fn () => app(CatalogService::class)->saveProduct(actorFor($this->gm), $drug, $prices($drug)))->toThrow(AccessDenied::class);
});

it('lets them keep their industry\'s price lists, while company lists and tax stay company-wide', function () {
    $mine = app(CatalogService::class)->savePriceList(actorFor($this->owner), new PriceList, ['name' => 'Supermarket promo', 'industry_id' => $this->grocery->id]);
    app(CatalogService::class)->savePriceList(actorFor($this->gm), $mine, ['name' => 'Supermarket weekend']);
    expect(mb_strtolower($mine->fresh()->name))->toBe('supermarket weekend');

    expect(fn () => app(CatalogService::class)->savePriceList(actorFor($this->gm), new PriceList, ['name' => 'For everyone']))->toThrow(AccessDenied::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->gm), 'web');
    expect(PriceListResource::canViewAny())->toBeTrue()->and(PriceListResource::canEdit($mine))->toBeTrue()
        ->and(PriceListResource::canEdit(PriceList::where('code', 'RETAIL')->first()))->toBeFalse()
        ->and(TaxRateResource::canCreate())->toBeFalse();
});

it('shows role limits in plain words', function () {
    expect(Plain::limits(['po_approve_minor' => 2_000_000, 'discount_bp' => 2500, 'expense_approve_minor' => 50_000]))
        ->toContain('Approves orders up to GH₵ 20,000')->toContain('Gives discounts up to 25%')->toContain('Approves expenses up to GH₵ 500')
        ->not->toContain('_minor')
        ->and(Plain::limits(['po_approve_minor' => 999_999_999_999]))->toBe('Approves orders up to any amount')
        ->and(Plain::limits([]))->toBe('—')
        ->and(Plain::action('stock_cost_run.updated'))->toBe('Stock cost run · updated')
        ->and(Plain::field('price_list_id'))->toBe('Price list')
        ->and(Plain::value('is_active', true))->toBe('Yes');
});

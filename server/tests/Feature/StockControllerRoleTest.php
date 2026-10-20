<?php

use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\PriceLists\PriceListResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Security\ScopeType;
use Filament\Facades\Filament;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('gives a stock controller products and stock, and nothing at the top level', function () {
    $role = Role::where('key', 'stock_controller')->sole();
    expect($role->name)->toBe('Stock Controller')->and($role->security_level)->toBe(3)
        ->and($role->permissions()->pluck('key')->all())->toContain('catalog.manage', 'stock.adjust', 'stock.count')
        ->not->toContain('pricing.manage', 'stock.adjust.approve', 'stock.count.approve', 'pos.access', 'sales.view', 'finance.view');

    // Products belong to the whole company, so the role is given company-wide.
    $this->actingAs(withMfa(staff($this->owner, 'ama', 3, 'stock_controller', ScopeType::Tenant, $this->tenant->id)));
    foreach ([ProductResource::getUrl('index'), ProductResource::getUrl('create'), StockLevelResource::getUrl('index'),
        StockAdjustmentResource::getUrl('index'), StockAdjustmentResource::getUrl('create')] as $url) {
        expect($this->get($url)->status())->toBe(200, $url);
    }
    // Product pages without the Prices section (prices need a manager).
    $this->get(ProductResource::getUrl('create'))->assertDontSee('Price list');
    // Prices can be seen (read-only), not changed.
    expect(PriceListResource::canCreate())->toBeFalse();
    foreach ([SaleResource::getUrl('index'), JournalEntryResource::getUrl('index'),
        CustomerResource::getUrl('index'), UserResource::getUrl('index'), ManageSettings::getUrl()] as $url) {
        expect($this->get($url)->status())->toBe(403, $url);
    }
});

it('does not open the catalogue to a stock controller given one branch only (products belong to the whole company)', function () {
    $this->actingAs(withMfa(staff($this->owner, 'kofi', 3, 'stock_controller', ScopeType::Branch, $this->branches['HQ']->id)));
    $this->get(ProductResource::getUrl('create'))->assertForbidden();
});

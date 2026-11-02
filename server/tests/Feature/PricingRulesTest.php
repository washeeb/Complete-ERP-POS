<?php

use App\Domain\Pricing\PricingRules;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\LandedCostService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\PricesToReview;
use App\Filament\Resources\PricingRules\PricingRuleResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\PriceSuggestion;
use App\Models\PricingRule;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    // Shelf prices typed by hand before any rule: 12.00 a piece, 330.00 a carton of 24.
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '12.00', carton: ['24', '330.00']);
    $this->pc = $this->milo->units->firstWhere('is_base', true);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Nestlé Ghana']);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    $this->rules = app(PricingRules::class);
});

/** Ten cartons delivered at $cartonCost each. */
function deliver($t, string $cartonCost)
{
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($t->buyer), new PurchaseOrder, ['supplier_id' => $t->supplier->id, 'warehouse_id' => $t->hq->id,
        'lines' => [['product_uom_id' => $t->ctn->id, 'qty' => '10', 'unit_cost' => $cartonCost]]]);
    app(PurchaseOrderService::class)->submit(actorFor($t->buyer), $po);
    $po = app(PurchaseOrderService::class)->approve(actorFor($t->manager), $po->fresh());

    return app(GoodsReceiptService::class)->receive(actorFor($t->keeper), $po, ['lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '10']]]);
}

function shelf($t, $unit): ?int
{
    return Price::where('price_list_id', $t->retail->id)->where('product_uom_id', $unit->id)->where('min_qty', 1)->whereNull('starts_at')->value('amount_minor');
}

function companyRule($t, array $d = []): PricingRule
{
    return app(PricingRules::class)->saveRule(actorFor($t->owner), new PricingRule, $d + ['scope' => 'company', 'price_list_id' => $t->retail->id, 'method' => 'multiply', 'value' => '1.40']);
}

it('sets the shelf price at cost × 1.40 when a delivery raises it, for every sellable unit', function () {
    companyRule($this);
    deliver($this, '240.00'); // 10.00 a piece

    expect(shelf($this, $this->pc))->toBe(1400)->and(shelf($this, $this->ctn))->toBe(33600)
        ->and(PriceSuggestion::count())->toBe(0);
});

it('asks a manager before lowering a price, who approves or keeps the current one', function () {
    companyRule($this);
    deliver($this, '240.00');
    deliver($this, '192.00'); // 8.00 a piece: 11.20 and 268.80

    expect(shelf($this, $this->pc))->toBe(1400)
        ->and(PriceSuggestion::where('status', 'pending')->orderBy('suggested_minor')->pluck('suggested_minor')->all())->toBe([1120, 26880]);

    [$piece, $carton] = PriceSuggestion::where('status', 'pending')->orderBy('suggested_minor')->get()->all();
    expect($this->rules->approve(actorFor($this->owner), [$piece]))->toBe(1)
        ->and($this->rules->dismiss(actorFor($this->owner), [$carton]))->toBe(1)
        ->and(shelf($this, $this->pc))->toBe(1120)->and(shelf($this, $this->ctn))->toBe(33600)
        ->and(PriceSuggestion::where('status', 'pending')->count())->toBe(0);

    // The same delivery seen again (e.g. the hourly run) suggests the carton once more, without a second message.
    $this->rules->follow(actorFor($this->owner), [$this->milo->id], 'again');
    $this->rules->follow(actorFor($this->owner), [$this->milo->id], 'again');
    expect(PriceSuggestion::where('status', 'pending')->count())->toBe(1);
});

it('follows the rule choice: always at once, or always for approval', function () {
    companyRule($this, ['on_change' => 'approve']);
    deliver($this, '240.00');
    expect(shelf($this, $this->pc))->toBe(1200)->and(PriceSuggestion::where('status', 'pending')->count())->toBe(2);

    $rule = PricingRule::first();
    $this->rules->saveRule(actorFor($this->owner), $rule, $rule->only(['scope', 'price_list_id', 'method']) + ['value' => '1.40', 'on_change' => 'auto']);
    deliver($this, '192.00');
    expect(shelf($this, $this->pc))->toBe(1120)->and(PriceSuggestion::where('status', 'pending')->count())->toBe(0);
});

it('includes the freight spread onto the delivery in the cost', function () {
    companyRule($this);
    $grn = deliver($this, '240.00');
    app(LandedCostService::class)->post(actorFor($this->owner), [$grn->id], [['kind' => 'freight', 'description' => 'Freight', 'amount_minor' => 24000]]);

    // 2400.00 + 240.00 over 240 pieces = 11.00 each: × 1.40 = 15.40
    expect($this->rules->latestCost($this->milo->fresh()))->toMatchArray(['cost' => 1100.0])
        ->and(shelf($this, $this->pc))->toBe(1540);
});

it('uses the most specific rule; manual keeps a product out; branch servers leave prices alone', function () {
    $drinks = Category::create(['name' => 'Drinks', 'is_active' => true]);
    $hot = Category::create(['name' => 'Hot drinks', 'parent_id' => $drinks->id, 'is_active' => true]);
    $this->milo->forceFill(['category_id' => $hot->id])->save();
    $milo = $this->milo->fresh();
    companyRule($this);
    $cat = $this->rules->saveRule(actorFor($this->owner), new PricingRule, ['scope' => 'category', 'category_id' => $drinks->id, 'price_list_id' => $this->retail->id, 'method' => 'markup', 'value' => '25']);
    expect($this->rules->ruleFor($milo, $this->retail)->id)->toBe($cat->id);

    $own = $this->rules->saveRule(actorFor($this->owner), new PricingRule, ['scope' => 'product', 'product_id' => $milo->id, 'price_list_id' => $this->retail->id, 'method' => 'manual']);
    expect($this->rules->ruleFor($milo, $this->retail)->id)->toBe($own->id);
    deliver($this, '240.00');
    expect(shelf($this, $this->pc))->toBe(1200);

    $own->forceFill(['is_active' => false])->save();
    config(['erp.node.role' => 'branch']);
    expect($this->rules->follow(actorFor($this->owner), [$milo->id], 'test'))->toBe(['applied' => 0, 'suggested' => 0]);
    config(['erp.node.role' => 'standalone']);
    $this->rules->follow(actorFor($this->owner), [$milo->id], 'test');
    expect(shelf($this, $this->pc))->toBe(1250); // markup 25 % on 10.00
});

it('works out each method, before or after tax, and rounds', function () {
    $milo = $this->milo->fresh(); // standard rated: 20 % in all
    $rule = fn (array $a) => (new PricingRule)->forceFill($a + ['method' => 'multiply', 'value_bp' => 14000, 'tax_mode' => 'inclusive', 'round_minor' => 10, 'round_mode' => 'nearest']);

    expect($this->rules->priceFor($rule([]), $milo, 1003, $this->retail))->toBe(1400) // 14.042 → 14.00
        ->and($this->rules->priceFor($rule(['round_mode' => 'up']), $milo, 1003, $this->retail))->toBe(1410)
        ->and($this->rules->priceFor($rule(['round_minor' => 100, 'round_mode' => 'up']), $milo, 1003, $this->retail))->toBe(1500)
        ->and($this->rules->priceFor($rule(['tax_mode' => 'exclusive']), $milo, 1000, $this->retail))->toBe(1680) // 14.00 + 20 %
        ->and($this->rules->priceFor($rule(['method' => 'markup', 'value_bp' => 2500]), $milo, 1000, $this->retail))->toBe(1250)
        ->and($this->rules->priceFor($rule(['method' => 'margin', 'value_bp' => 2000]), $milo, 1000, $this->retail))->toBe(1250)
        ->and($this->rules->priceFor($rule(['method' => 'plus', 'amount_minor' => 300]), $milo, 1000, $this->retail))->toBe(1300)
        ->and($this->rules->priceFor($rule(['method' => 'manual']), $milo, 1000, $this->retail))->toBeNull();

    $net = PriceList::create(['code' => 'NET', 'name' => 'Before tax', 'currency_code' => $this->retail->currency_code, 'prices_include_tax' => false, 'is_active' => true]);
    expect($this->rules->priceFor($rule([]), $milo, 1000, $net))->toBe(1170); // 14.00 shelf ÷ 1.20 = 11.67 → 11.70
});

it('checks the rule and who sets it', function () {
    expect(fn () => companyRule($this, ['value' => '0.5']))->toThrow(DomainException::class, 'at least 1')
        ->and(fn () => companyRule($this, ['method' => 'margin', 'value' => '99']))->toThrow(DomainException::class, 'below 95')
        ->and(fn () => companyRule($this, ['method' => 'plus', 'amount' => '0']))->toThrow(DomainException::class, 'above zero');
    companyRule($this);
    expect(fn () => companyRule($this))->toThrow(DomainException::class, 'already a rule')
        ->and(fn () => $this->rules->saveRule(actorFor($this->manager), new PricingRule, ['scope' => 'company', 'price_list_id' => $this->retail->id, 'method' => 'multiply', 'value' => '1.5']))->toThrow(AccessDenied::class)
        ->and(fn () => $this->rules->followAll(actorFor($this->keeper)))->toThrow(AccessDenied::class);
});

it('shows the rules, the prices to review and the rule on the product, to price setters only', function () {
    companyRule($this);
    deliver($this, '240.00');
    deliver($this, '192.00');

    $this->actingAs(withMfa($this->owner), 'web')->get(PricingRuleResource::getUrl())->assertOk()->assertSee('Cost × 1.40')->assertSee('Apply the rules to all products now');
    $this->actingAs(withMfa($this->owner), 'web')->get(PricesToReview::getUrl())->assertOk()->assertSee('MILO 400G')->assertSee('11.20');
    $this->actingAs(withMfa($this->owner), 'web')->get(ProductResource::getUrl('edit', ['record' => $this->milo]))->assertOk()->assertSee('Cost × 1.40');
});

it('keeps the pricing pages from people who do not set prices', function () {
    $this->actingAs(withMfa($this->keeper), 'web')->get(PricesToReview::getUrl())->assertForbidden();
});

it('lets the hourly run catch deliveries that reached head office by sync', function () {
    deliver($this, '240.00'); // posted before any rule: price untouched
    expect(shelf($this, $this->pc))->toBe(1200);
    companyRule($this);

    $this->artisan('erp:pricing-rules')->assertSuccessful();
    expect(shelf($this, $this->pc))->toBe(1400);
});

<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Devices\DeviceService;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\IndustryService;
use App\Domain\Org\OrgService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\GroceryMarkdowns;
use App\Filament\Pages\ManageModules;
use App\Filament\Pages\ScaleItems;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Support\Erp;
use App\Filament\Widgets\IndustryHighlights;
use App\Filament\Widgets\IndustryOverview;
use App\Livewire\IndustrySwitcher;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\Product;
use App\Models\Role;
use App\Models\TaxGroup;
use App\Models\Till;
use App\Models\Uom;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\IndustryContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    // Head office (company-level), two pharmacies and a grocery.
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
        ['code' => 'P2', 'name' => 'Pharmacy Tema', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Grocery Spintex', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
    ]);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
});

it('makes each branch a branch of one industry, keeps head office company-level, and refuses a second industry', function () {
    expect($this->b['P1']->fresh()->industry_id)->toBe($this->pharmacy->id)
        ->and($this->b['P2']->fresh()->industry_id)->toBe($this->pharmacy->id)
        ->and($this->b['G1']->fresh()->industry_id)->toBe($this->grocery->id)
        ->and($this->b['HQ']->fresh()->industry_id)->toBeNull()
        ->and(Industry::count())->toBe(2);

    $modules = app(ModuleService::class);
    expect(fn () => $modules->setStatus(actorFor($this->owner), $this->b['P1'], 'grocery', 'enabled'))->toThrow(DomainException::class, 'one industry');
    $modules->setStatus(actorFor($this->owner), $this->b['G1'], 'distribution', 'enabled'); // an add-on sits alongside its industry
    expect($modules->isEnabled($this->b['G1'], 'distribution'))->toBeTrue();

    // Moving a branch to another industry while it runs this one's module is refused; head office stays company-level.
    expect(fn () => app(IndustryService::class)->assign(actorFor($this->owner), $this->b['P1']->fresh(), $this->grocery))->toThrow(DomainException::class, 'Switch that module off');
    expect(fn () => app(IndustryService::class)->save(actorFor($this->owner), $this->pharmacy, ['code' => 'PH', 'name' => 'Pharmacy', 'module_key' => 'grocery']))
        ->toThrow(DomainException::class, 'cannot change');
});

it('gives an industry manager all branches of the industry and nothing else', function () {
    $mgr = staff($this->owner, 'abena', 4);
    app(AccessService::class)->grant(actorFor($this->owner), $mgr, Role::where('key', 'branch_manager')->firstOrFail(), ScopeType::Industry, $this->pharmacy->id);
    app(Authorizer::class)->forget();
    $authz = app(Authorizer::class);

    expect($authz->branchIds($mgr, 'stock.view'))->toEqualCanonicalizing([$this->b['P1']->id, $this->b['P2']->id])
        ->and($authz->allows($mgr, 'stock.transfer', $this->b['P2']))->toBeTrue()
        ->and($authz->allows($mgr, 'stock.transfer', $this->b['G1']))->toBeFalse()
        ->and($authz->allows($mgr, 'pos.access', Till::where('branch_id', $this->b['P1']->id)->first()))->toBeTrue();

    // A pharmacist at Pharmacy Osu sees that branch only, not the sister pharmacy.
    $pharmacist = staff($this->owner, 'kwesi', 4, 'pharmacist', ScopeType::Branch, $this->b['P1']->id);
    app(Authorizer::class)->forget();
    expect(app(Authorizer::class)->branchIds($pharmacist, 'stock.view'))->toBe([$this->b['P1']->id])
        ->and(IndustryContext::options($pharmacist)->keys()->all())->toBe([$this->pharmacy->id]);
});

it('switches the whole back office to one industry: branches, catalogue and dashboard', function () {
    $drug = makeProduct($this->owner, 'PARA', 'Paracetamol 500mg', retail: '5.00');
    $rice = makeProduct($this->owner, 'RICE', 'Rice 5kg', retail: '120.00');
    $water = makeProduct($this->owner, 'WATER', 'Bottled water', retail: '3.00'); // shared by both
    $drug->forceFill(['industry_id' => $this->pharmacy->id])->saveQuietly();
    $rice->forceFill(['industry_id' => $this->grocery->id])->saveQuietly();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    expect(IndustryContext::options($this->owner)->count())->toBe(2)->and(Erp::branchIds('sales.view'))->toBeNull();
    $this->get('/admin')->assertOk();
    Livewire::test(IndustryOverview::class)->assertSee('Pharmacy')->assertSee('Grocery');

    Livewire::test(IndustrySwitcher::class)->set('industry', $this->pharmacy->id)->assertRedirect();
    expect(IndustryContext::selected())->toBe($this->pharmacy->id)
        ->and(Erp::branchIds('sales.view'))->toEqualCanonicalizing([$this->b['P1']->id, $this->b['P2']->id]);
    Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$drug, $water])->assertCanNotSeeTableRecords([$rice]);
    Livewire::test(IndustryHighlights::class)->assertSee('Prescriptions today')->assertSee('Batches expiring in 90 days');

    // Back to the whole company.
    Livewire::test(IndustrySwitcher::class)->set('industry', '');
    expect(IndustryContext::selected())->toBeNull();
    Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$drug, $rice, $water]);
});

it('gives a pharmacy till only the pharmacy catalogue (and shared items)', function () {
    $drug = makeProduct($this->owner, 'PARA', 'Paracetamol 500mg', retail: '5.00');
    $rice = makeProduct($this->owner, 'RICE', 'Rice 5kg', retail: '120.00');
    $drug->forceFill(['industry_id' => $this->pharmacy->id])->saveQuietly();
    $rice->forceFill(['industry_id' => $this->grocery->id])->saveQuietly();
    $till = Till::where('branch_id', $this->b['P1']->id)->first();
    [, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->b['P1']->id, 'till_id' => $till->id, 'name' => 'T', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
    $teller = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $teller, '482613');
    $token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '482613'])->json('token');
    app('auth')->forgetGuards();

    $skus = collect($this->withToken($token)->getJson('/api/v1/pos/catalog')->assertOk()->json('products'))->pluck('sku');
    expect($skus)->toContain('PARA')->not->toContain('RICE');
});

it('moves a branch between company-level and an industry through the branch screen rules', function () {
    $hq = $this->b['HQ'];
    app(OrgService::class)->updateBranch(actorFor($this->owner), $hq, ['name' => $hq->name, 'industry_id' => $this->grocery->id]);
    expect($hq->fresh()->industry_id)->toBe($this->grocery->id);
    app(OrgService::class)->updateBranch(actorFor($this->owner), $hq->fresh(), ['name' => $hq->name, 'industry_id' => null]);
    expect($hq->fresh()->industry_id)->toBeNull();
});

it('shows only industry modules the licence covers, and only installed industries in the switcher', function () {
    $restaurant = app(IndustryService::class)->save(actorFor($this->owner), new Industry, ['code' => 'REST', 'name' => 'Restaurants', 'module_key' => 'restaurant']);
    expect(IndustryContext::options($this->owner)->keys()->all())->not->toContain($restaurant->id); // licensed, not installed

    activateTestLicense(['modules' => ['pharmacy']]); // grocery not purchased (or dropped on a downgrade)
    LicenseManager::forget();

    expect(array_keys(ModuleService::licensed()))->toBe(['pharmacy'])
        ->and(ModuleService::enabledAnywhere('grocery'))->toBeFalse()
        ->and(ModuleService::enabledAnywhere('pharmacy'))->toBeTrue()
        ->and(Industry::licensed()->pluck('id')->all())->toBe([$this->pharmacy->id])
        ->and(IndustryContext::options($this->owner)->keys()->all())->toBe([$this->pharmacy->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ManageModules::class)->assertSee('Pharmacy')->assertDontSee('Fashion')->assertDontSee('Restaurant');
});

it('shows only the chosen industry\'s module screens, and refuses a second industry on a branch with a message', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    expect(PrescriptionResource::canViewAny())->toBeTrue(); // all industries: pharmacy is installed

    IndustryContext::set($this->grocery->id);
    expect(PrescriptionResource::canViewAny())->toBeFalse()
        ->and(ModuleService::enabledAnywhere('pharmacy'))->toBeFalse()
        ->and(ModuleService::enabledAnywhere('grocery'))->toBeTrue();
    $this->get('/admin')->assertOk()->assertDontSee('Prescriptions');

    IndustryContext::set($this->pharmacy->id);
    expect(PrescriptionResource::canViewAny())->toBeTrue();
    IndustryContext::set(null);

    // Switching grocery on at a pharmacy branch: "Not saved" with the reason, no error page.
    Livewire::test(ManageModules::class)
        ->set('data.branch_id', $this->b['P1']->id)->set('data.modules.grocery', 'enabled')
        ->call('save')->assertNotified('Not saved')->assertOk();
    expect(app(ModuleService::class)->isEnabled($this->b['P1'], 'grocery'))->toBeFalse();
});

it('gives every new product its SKU from its industry, and never changes it', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    IndustryContext::set($this->pharmacy->id);
    $pc = Uom::where('code', 'PC')->value('id');
    $std = TaxGroup::where('code', 'STD')->value('id');
    foreach (['Amoxicillin 500mg', 'Ibuprofen 200mg'] as $name) {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => $name, 'base_uom_id' => $pc, 'tax_group_id' => $std, 'industry_id' => $this->pharmacy->id])->call('create')->assertHasNoFormErrors();
    }
    $code = $this->pharmacy->code;
    $amox = Product::where('name', 'Amoxicillin 500mg')->first();
    expect($amox->sku)->toBe("{$code}-000001")
        ->and(Product::where('name', 'Ibuprofen 200mg')->value('sku'))->toBe("{$code}-000002");

    app(CatalogService::class)->saveProduct(actorFor($this->owner), $amox, ['sku' => 'CHANGED', 'name' => 'Amoxicillin 500mg caps']);
    expect($amox->fresh()->sku)->toBe("{$code}-000001")->and($amox->fresh()->name)->toBe('Amoxicillin 500mg caps');
});

it('gives grocery its own menu: markdowns at grocery stores and the scale items', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    $mince = makeProduct($this->owner, 'MINCE', 'Minced beef', retail: '85.00');
    $mince->forceFill(['plu' => '123', 'industry_id' => $this->grocery->id])->saveQuietly();

    IndustryContext::set($this->grocery->id);
    expect(GroceryMarkdowns::canAccess())->toBeTrue()->and(ScaleItems::canAccess())->toBeTrue();
    $this->get('/admin')->assertOk()->assertSee('Markdowns')->assertSee('Scale items');
    $this->get(ScaleItems::getUrl())->assertOk()->assertSee('00123')->assertSee('Minced beef')->assertSee('85.00');
    $this->get(GroceryMarkdowns::getUrl())->assertOk()->assertSee('Grocery Spintex')->assertDontSee('Pharmacy Osu')->assertDontSee('All batches on hand');

    IndustryContext::set($this->pharmacy->id);
    expect(GroceryMarkdowns::canAccess())->toBeFalse()->and(ScaleItems::canAccess())->toBeFalse();
});

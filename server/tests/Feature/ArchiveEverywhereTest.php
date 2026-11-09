<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Org\ArchiveRules;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\ArchiveUi;
use App\Models\Category;
use App\Models\Concerns\Archivable;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Role;
use App\Models\Till;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** Owner 2026-11-09: everything that cannot be deleted for record keeping can be archived, so it does not get in the way. */
beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->archive = app(ArchiveService::class);
});

it('gives every kind of record the archive columns and behaviour', function () {
    foreach (array_keys(ArchiveRules::TYPES) as $class) {
        $table = (new $class)->getTable();
        expect(Schema::hasColumns($table, ['archived_at', 'archived_by', 'archive_reason']))->toBeTrue("{$table} has no archive columns")
            ->and(in_array(Archivable::class, class_uses_recursive($class), true))->toBeTrue("{$class} is not Archivable");
    }
});

it('archives a product only without stock, keeps it off for sale, and restoring leaves the choice to the person', function () {
    $p = makeProduct($this->owner, 'RICE', 'Rice 5kg', '90');
    $store = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    app(InventoryService::class)->adjust($this->a, $store, $p, $p->units->firstWhere('is_base', true), '3', 'opening', '', 'Loaded', '50');
    expect($this->archive->blockers($p))->toContain('It still has stock. Sell, transfer or count it to zero first.');

    $other = makeProduct($this->owner, 'OIL', 'Oil 1L', '40');
    $this->archive->archive($this->a, $other, 'Created twice');
    expect($other->fresh()->is_active)->toBeFalse()->and($other->fresh()->archived_at)->not->toBeNull();

    // A form or import that sends "for sale" cannot bring an archived product back on sale.
    app(CatalogService::class)->saveProduct($this->a, $other->fresh(), ['name' => 'Oil 1L', 'is_active' => true]);
    expect($other->fresh()->is_active)->toBeFalse();

    $this->archive->restore($this->a, $other->fresh());
    expect($other->fresh()->archived_at)->toBeNull()->and($other->fresh()->is_active)->toBeFalse(); // switched on by a person
});

it('archives a customer only with a settled account, and restores them as in use', function () {
    $c = app(CustomerService::class)->save($this->a, new Customer, ['name' => 'Ama', 'phone' => '0241234567']);
    $this->archive->archive($this->a, $c, 'Duplicate');
    expect($c->fresh()->is_active)->toBeFalse();
    $this->archive->restore($this->a, $c->fresh());
    expect($c->fresh()->is_active)->toBeTrue()->and($c->fresh()->archived_at)->toBeNull();
});

it('archives a device only once it is revoked', function () {
    $till = Till::where('branch_id', $this->branches['BA']->id)->first();
    $d = Device::forceCreate(['tenant_id' => $this->tenant->id, 'branch_id' => $till->branch_id, 'till_id' => $till->id, 'name' => 'Old till PC',
        'kind' => 'pos', 'status' => 'active', 'created_by' => $this->owner->id]);
    expect($this->archive->blockers($d)[0])->toContain('revoke it');
    app(DeviceService::class)->revoke($this->a, $d, 'Replaced');
    $this->archive->archive($this->a, $d->fresh(), 'Replaced by the new PC');
    expect($d->fresh()->archived_at)->not->toBeNull();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ListDevices::class)->assertCanNotSeeTableRecords([$d])->filterTable('archived', true)->assertCanSeeTableRecords([$d]);
});

it('never archives yourself or the only owner, and signs an archived person out', function () {
    expect($this->archive->blockers($this->owner))->not->toBeEmpty();
    $kofi = staff($this->owner, 'kofi', 2, 'teller');
    $kofi->createToken('pos', ['pos']);
    $this->archive->archive($this->a, $kofi, 'Left the company');
    expect($kofi->fresh()->is_active)->toBeFalse()->and($kofi->tokens()->count())->toBe(0);
});

it('refuses a category with products in use and a standard role', function () {
    $p = makeProduct($this->owner, 'MILO', 'Milo 400g', '45');
    $cat = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Drinks', 'is_active' => true]);
    $p->forceFill(['category_id' => $cat->id])->save();
    expect($this->archive->blockers($cat)[0])->toContain('1 product(s) are in it');
    expect($this->archive->blockers(Role::where('key', 'teller')->first()))->toContain('It is one of the standard roles.');
});

it('needs the permission for that kind of record', function () {
    $c = app(CustomerService::class)->save($this->a, new Customer, ['name' => 'Yaw', 'phone' => '0247654321']);
    $cashier = staff($this->owner, 'efua', 2, 'teller');
    expect(fn () => $this->archive->archive(actorFor($cashier), $c, 'Not needed'))->toThrow(AccessDenied::class);
});

it('lists only those in use unless asked, with Archive and Restore on the row', function () {
    $p = makeProduct($this->owner, 'SALT', 'Salt 500g', '5');
    $c = app(CustomerService::class)->save($this->a, new Customer, ['name' => 'Esi', 'phone' => '0201112223']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');

    Livewire::test(ListProducts::class)->callTableAction('archive', $p, data: ['reason' => 'Typed by mistake'])->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$p])->filterTable('archived', true)->assertCanSeeTableRecords([$p]);
    Livewire::test(ListCustomers::class)->callTableAction('archive', $c, data: ['reason' => 'Moved away'])
        ->filterTable('archived', true)->callTableAction('restore', $c);
    expect($c->fresh()->archived_at)->toBeNull();
    Livewire::test(ListUsers::class)->assertSuccessful();
    expect(fn () => $this->archive->archive($this->a, $p->fresh(), 'Again'))->toThrow(DomainException::class, 'already archived');
});

it('gives the Archive dialog its own Archive button, not a copy of the row button', function () {
    // Owner 2026-11-09: pressing Archive in the dialog did nothing; the dialog's button was the row action itself.
    $c = app(CustomerService::class)->save($this->a, new Customer, ['name' => 'Kwesi', 'phone' => '0203334445']);
    $this->actingAs(withMfa($this->owner), 'web');
    $action = ArchiveUi::archive()->record($c);
    $submit = $action->getModalSubmitAction();
    expect($submit->getName())->not->toBe('archive')->and($submit->getLabel())->toBe('Archive')->and($submit->isDisabled())->toBeFalse();
});

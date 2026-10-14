<?php

use App\Domain\Devices\DeviceService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Device;
use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\Till;
use App\Security\RoleCatalog;
use App\Support\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    // A pharmacy and a grocery shop: no restaurant, services or fashion anywhere.
    $this->b = setUpBranches($this->owner, [
        ['code' => 'PH', 'name' => 'Pharmacy', 'is_hq' => true, 'tills' => 2, 'modules' => ['pharmacy']],
        ['code' => 'RT', 'name' => 'Retail', 'is_hq' => false, 'tills' => 2, 'modules' => ['grocery']],
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
});

it('offers only the device kinds the branch can use, never the unbuilt kiosk', function () {
    expect(array_keys(Device::kindsFor($this->b['PH']->id)))->toBe(['pos', 'handheld']);
    expect(fn () => app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->b['PH']->id, 'name' => 'Kitchen', 'kind' => 'kds']))
        ->toThrow(DomainException::class, 'Restaurant module');
    expect(fn () => app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->b['PH']->id, 'name' => 'Kiosk', 'kind' => 'kiosk']))
        ->toThrow(DomainException::class);

    [$till] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->b['PH']->id,
        'till_id' => Till::where('branch_id', $this->b['PH']->id)->value('id'), 'name' => 'Counter', 'kind' => 'pos']);
    expect($till->kind)->toBe('pos');
});

it('leaves out roles, settings and messages of industries not switched on', function () {
    $hidden = RoleCatalog::hiddenKeys();
    expect($hidden)->toContain('waiter', 'kitchen_staff', 'tailor', 'service_staff')->not->toContain('pharmacist');
    Livewire::test(ListRoles::class)->assertCanSeeTableRecords(Role::where('key', 'pharmacist')->get())
        ->assertCanNotSeeTableRecords(Role::where('key', 'waiter')->get());

    // Settings: no Restaurant section, and saving leaves its values alone.
    app(Settings::class)->set(actorFor($this->owner), 'restaurant.tips_enabled', true);
    Livewire::test(ManageSettings::class)->assertDontSee('Service-charge product')->assertSee('Pharmacy')
        ->call('save')->assertHasNoErrors();
    expect(app(Settings::class)->get('restaurant.tips_enabled', $this->tenant->id))->toBeTrue();

    Livewire::test(ListMessageTemplates::class)
        ->assertCanNotSeeTableRecords(MessageTemplate::where('key', 'like', 'reservation%')->get())
        ->assertCanNotSeeTableRecords(MessageTemplate::where('key', 'like', 'appointment%')->get())
        ->assertCanSeeTableRecords(MessageTemplate::where('key', 'receipt')->get());
});

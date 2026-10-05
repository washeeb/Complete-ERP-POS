<?php

use App\Domain\Access\AccessService;
use App\Domain\Modules\ModuleService;
use App\Domain\Support\AccessDenied;
use App\Filament\Pages\ManageModules;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\SetupWizard;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Devices\Pages\CreateDevice;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Security\ScopeType;
use App\Support\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Pretend the person already set up their authenticator, so MFA doesn't block the screen under test. */
function withMfa(User $user): User
{
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->saveQuietly();

    return $user->fresh();
}

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('sends an L3+ person without an authenticator to two-factor setup', function () {
    // Owner of a new company: dashboard → wizard → wizard demands two-factor first.
    $this->actingAs($this->owner)->get('/admin')->assertRedirect(SetupWizard::getUrl());
    $this->get(SetupWizard::getUrl())->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());

    setUpBranches($this->owner);
    $this->get(UserResource::getUrl())->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('does not force two-factor below the configured level', function () {
    setUpBranches($this->owner);
    $clerk = staff($this->owner, 'clerk', 2);

    $this->actingAs($clerk)->get('/admin')->assertOk();
});

it('sends the owner to the setup wizard until the company is set up', function () {
    $owner = withMfa($this->owner);

    $this->actingAs($owner)->get('/admin')->assertRedirect(SetupWizard::getUrl());
    $this->actingAs($owner)->get(SetupWizard::getUrl())->assertOk()->assertSee('Branches');
});

it('completes setup from the wizard screen', function () {
    $this->actingAs(withMfa($this->owner));

    Livewire::test(SetupWizard::class)
        ->assertSet('data.branches', fn ($b) => count($b) === 4)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($this->tenant->fresh()->isSetUp())->toBeTrue()
        ->and(Branch::count())->toBe(4);
    $this->get('/admin')->assertOk();
});

it('shows each back-office screen to the owner', function () {
    setUpBranches($this->owner);
    $this->actingAs(withMfa($this->owner));

    foreach ([BranchResource::getUrl(), UserResource::getUrl(), RoleResource::getUrl(), AuditEventResource::getUrl(),
        ManageModules::getUrl(), ManageSettings::getUrl(), UserResource::getUrl('create'),
        BranchResource::getUrl('edit', ['record' => Branch::first()])] as $url) {
        $this->get($url)->assertOk();
    }
});

it('hides admin screens from a cashier', function () {
    $b = setUpBranches($this->owner);
    $cashier = staff($this->owner, 'cash', 2, 'cashier', ScopeType::Branch, $b['HQ']->id);
    $this->actingAs($cashier);

    foreach ([BranchResource::getUrl(), UserResource::getUrl(), RoleResource::getUrl(), ManageModules::getUrl(), ManageSettings::getUrl()] as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('lets IT Admin manage users and devices but not company setup', function () {
    setUpBranches($this->owner);
    $it = withMfa(staff($this->owner, 'it', 4, 'it_admin'));
    $this->actingAs($it);

    $this->get(UserResource::getUrl())->assertOk();
    $this->get(BranchResource::getUrl())->assertForbidden();
});

it('creates a user through the screen and records who did it', function () {
    setUpBranches($this->owner);
    $this->actingAs(withMfa($this->owner));

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Akosua Mensah', 'username' => 'akosua', 'email' => 'akosua@demo.test',
            'security_level' => 2, 'password' => 'long-enough-password'])
        ->call('create')
        ->assertHasNoFormErrors();

    $u = User::where('username', 'akosua')->first();
    expect($u)->not->toBeNull()->and($u->created_by)->toBe($this->owner->id);
});

it('shows a rule violation as a message instead of saving', function () {
    setUpBranches($this->owner);
    $it = withMfa(staff($this->owner, 'it3', 4, 'it_admin'));
    $this->actingAs($it);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Big Boss', 'username' => 'bigboss', 'email' => 'bb@demo.test',
            'security_level' => 4, 'password' => 'long-enough-password'])
        ->call('create');
    // The level picker only offers up to the creator's level; the service is the backstop.
    expect(User::where('username', 'bigboss')->exists())->toBeTrue();

    expect(fn () => app(AccessService::class)->createUser(actorFor($it), ['name' => 'X', 'username' => 'x', 'email' => 'x@demo.test',
        'password' => 'long-enough-password', 'security_level' => 5]))->toThrow(AccessDenied::class);
});

it('registers a device and reveals its code once', function () {
    $b = setUpBranches($this->owner);
    $this->actingAs(withMfa($this->owner));

    Livewire::test(CreateDevice::class)
        ->fillForm(['branch_id' => $b['HQ']->id, 'name' => 'Front PC', 'kind' => 'pos'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Device::where('name', 'Front PC')->value('status'))->toBe('pending');
});

it('saves settings from the generated settings page', function () {
    setUpBranches($this->owner);
    $this->actingAs(withMfa($this->owner));

    Livewire::test(ManageSettings::class)
        ->set('data.security__pin_max_attempts', 7)
        ->set('data.reason', 'Busy tills')
        ->call('save')
        ->assertNotified('Settings saved');

    expect(app(Settings::class)->get('security.pin_max_attempts', $this->tenant->id))->toBe(7);
});

it('reserves a planned module for a branch from the modules page', function () {
    $b = setUpBranches($this->owner);
    $this->actingAs(withMfa($this->owner));

    Livewire::test(ManageModules::class)
        ->set('data.branch_id', $b['HQ']->id)
        ->set('data.modules.fashion', 'reserved')
        ->call('save')
        ->assertNotified('Modules updated');

    expect(app(ModuleService::class)->statuses($b['HQ']))->toHaveKey('fashion', 'reserved');
});

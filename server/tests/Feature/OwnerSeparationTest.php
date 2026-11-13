<?php

use App\Domain\Access\AccessService;
use App\Domain\Access\OwnerSeparation;
use App\Domain\Access\SupportAccount;
use App\Domain\Org\OrgService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Security\AccessCatalogSync;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\Settings;
use Livewire\Livewire;

// Owner decision 2026-11-13: the Owner runs the business; IT Admin and SyncLytics support do the technical side, "to make
// sure the system stays safe even when anyone gets access to owners PC or credentials".

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner); // setup finished, so pages open
    $this->authz = app(Authorizer::class);
    $this->support = fn () => app(SupportAccount::class)->ensure($this->tenant, 'support@synclytics.test', 'SyncLytics Support', 'a-long-support-password')[0];
});

it('keeps the owner technical until someone else can do that work, then moves it, keeping the view of users', function () {
    expect($this->authz->allows($this->owner, 'admin.users.manage'))->toBeTrue()
        ->and(OwnerSeparation::ownerStillTechnical($this->tenant->id))->toBeTrue();
    $this->actingAs(withMfa($this->owner), 'web')->get(UserResource::getUrl())->assertOk()->assertSee('no IT administrator is set up');

    $support = ($this->support)();
    $owner = $this->owner->fresh();
    $this->authz->forget($owner);
    foreach (['admin.users.manage', 'admin.roles.manage', 'admin.devices.manage', 'admin.settings.manage', 'admin.modules.manage', 'sync.manage', 'remote.control', 'api.manage'] as $p) {
        expect($this->authz->allows($owner, $p))->toBeFalse($p);
    }
    expect($this->authz->allows($owner, 'admin.users.view'))->toBeTrue()
        ->and($this->authz->allows($owner, 'settings.business'))->toBeTrue()
        ->and($this->authz->allows($owner, 'purchasing.approve'))->toBeTrue()
        ->and($this->authz->allows($support, 'admin.users.manage'))->toBeTrue()
        ->and(OwnerSeparation::ownerStillTechnical($this->tenant->id))->toBeFalse()
        ->and(AuditEvent::where('action', 'role.permissions_changed')->where('subject_id', Role::where('key', 'owner')->value('id'))->exists())->toBeTrue();

    // The owner sees users and their access, but cannot add or change them.
    $this->actingAs(withMfa($owner), 'web');
    expect(UserResource::canViewAny())->toBeTrue()->and(UserResource::canCreate())->toBeFalse();
    $this->get(UserResource::getUrl())->assertOk()->assertDontSee('no IT administrator is set up');
});

it('moves it to an IT Admin too, and new technical permissions skip the owner afterwards', function () {
    staff($this->owner, 'kofi', 4, 'it_admin');
    $owner = $this->owner->fresh();
    $this->authz->forget($owner);
    expect($this->authz->allows($owner, 'admin.users.manage'))->toBeFalse();

    app(AccessCatalogSync::class)->syncRoles($this->tenant->id, ['admin.users.manage', 'sync.manage']); // as if newly introduced
    $this->authz->forget($owner);
    expect($this->authz->allows($owner, 'admin.users.manage'))->toBeFalse()->and($this->authz->allows($owner, 'sync.manage'))->toBeFalse();
});

it('keeps owner and technical access apart: the role, the accounts, and the last IT administrator', function () {
    $it = staff($this->owner, 'kofi', 4, 'it_admin');
    // The only IT administrator (no support account yet) cannot be revoked: somebody must be able to add users.
    expect(fn () => app(AccessService::class)->revoke(actorFor($it), RoleAssignment::where('user_id', $it->id)->first(), 'Leaving'))
        ->toThrow(DomainException::class, 'last IT administrator');
    $support = ($this->support)();
    $svc = app(AccessService::class);
    $ownerRole = Role::where('key', 'owner')->first();

    // Even SyncLytics support cannot put technical permissions back on the Owner role.
    expect(fn () => $svc->saveRole(actorFor($support), $ownerRole, [], [...$ownerRole->permissionKeys(), 'admin.users.manage']))
        ->toThrow(DomainException::class, 'The Owner role runs the business');
    // The owner's account never also gets IT Admin; an IT account never also becomes an owner.
    expect(fn () => $svc->grant(actorFor($support), $this->owner, Role::where('key', 'it_admin')->first(), ScopeType::Tenant, $this->tenant->id))
        ->toThrow(DomainException::class, 'is an owner')
        ->and(fn () => $svc->grant(actorFor($support), $it->fresh(), $ownerRole, ScopeType::Tenant, $this->tenant->id))
        ->toThrow(DomainException::class);

    // With the support account there, the IT Admin can go.
    $svc->revoke(actorFor($support), RoleAssignment::where('user_id', $it->id)->first(), 'Left the company');
    expect(RoleAssignment::where('user_id', $it->id)->first()->revoked_at)->not->toBeNull();
});

it('splits settings: the owner changes business ones, IT technical ones, support only its own access', function () {
    $it = withMfa(staff($this->owner, 'kofi', 4, 'it_admin'));
    $support = ($this->support)();
    $owner = withMfa($this->owner->fresh());
    $settings = app(Settings::class);

    expect(Settings::mayChange('receipt.footer', $owner))->toBeTrue()
        ->and(Settings::mayChange('security.mfa_from_level', $owner))->toBeFalse()
        ->and(Settings::mayChange('security.mfa_from_level', $it))->toBeTrue()
        ->and(Settings::mayChange('receipt.footer', $it))->toBeFalse()
        ->and(Settings::mayChange('security.support_account', $it))->toBeFalse()
        ->and(Settings::mayChange('remote.consent', $owner))->toBeFalse()
        ->and(Settings::mayChange('security.support_account', $support))->toBeTrue();

    // On the page the owner saves a business setting; a technical one sent anyway stays as it was.
    $this->actingAs($owner, 'web');
    Livewire::test(ManageSettings::class)->assertSuccessful()
        ->set('data.receipt__footer', 'Thank you for shopping')->set('data.security__mfa_from_level', 7)->call('save');
    expect($settings->get('receipt.footer', $this->tenant->id))->toBe('Thank you for shopping')
        ->and($settings->get('security.mfa_from_level', $this->tenant->id))->toBe(3);

    // A branch's own value follows the same rule: the remote-connection consent is support's alone.
    $branch = Branch::first();
    expect(fn () => app(OrgService::class)->updateBranch(actorFor($owner), $branch, ['settings' => ['remote.consent' => 'ask']]))
        ->toThrow(DomainException::class, 'changed by SyncLytics support');
    app(OrgService::class)->updateBranch(actorFor($support), $branch, ['settings' => ['remote.consent' => 'ask']]);
    expect($branch->fresh()->settings['remote.consent'])->toBe('ask');

    $this->actingAs($it, 'web');
    Livewire::test(ManageSettings::class)->assertSuccessful()->set('data.security__mfa_from_level', 4)->set('data.receipt__footer', 'Changed by IT')->call('save');
    expect($settings->get('security.mfa_from_level', $this->tenant->id))->toBe(4)
        ->and($settings->get('receipt.footer', $this->tenant->id))->toBe('Thank you for shopping');
});

<?php

use App\Domain\Access\AccessService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Users\Pages\EditUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->owner = withMfa($this->owner);
});

it('resets the owner\'s authenticator on the server, with an audit row', function () {
    $this->artisan('erp:mfa-reset', ['--email' => $this->owner->email, '--reason' => 'Deleted the app; checked by phone'])
        ->expectsConfirmation('Reset the two-step sign-in of Ama Owner (L'.$this->owner->security_level.', Demo Ltd)?', 'yes')
        ->assertSuccessful();

    expect($this->owner->fresh()->app_authentication_secret)->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'user.mfa_reset')->where('subject_id', $this->owner->id)->exists())->toBeTrue();
});

it('lets someone who manages users reset someone below them, never themselves or someone above', function () {
    $cashier = withMfa(staff($this->owner, 'cash', 2)); // before IT Admin: after that the owner no longer adds users
    $manager = withMfa(staff($this->owner, 'mgr', 4, 'it_admin')); // manages users
    $access = app(AccessService::class);

    $access->resetMfa(actorFor($manager), $cashier, 'New phone');
    expect($cashier->fresh()->app_authentication_secret)->toBeNull()
        ->and(fn () => $access->resetMfa(actorFor($manager), $manager, 'Mine'))->toThrow(DomainException::class)
        ->and(fn () => $access->resetMfa(actorFor($manager), $this->owner, 'Above me'))->toThrow(AccessDenied::class)
        ->and($this->owner->fresh()->app_authentication_secret)->not->toBeNull();

    // On the user's page too (IT looks after users; the owner sees them but no longer resets sign-ins).
    $cashier = withMfa($cashier->fresh());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($manager, 'web');
    Livewire::test(EditUser::class, ['record' => $cashier->id])
        ->callAction('resetMfa', ['reason' => 'Lost phone'])->assertHasNoActionErrors();
    expect($cashier->fresh()->app_authentication_secret)->toBeNull();
});

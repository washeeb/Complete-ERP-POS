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
    $manager = withMfa(staff($this->owner, 'mgr', 4, 'it_admin')); // manages users
    $cashier = withMfa(staff($this->owner, 'cash', 2));
    $access = app(AccessService::class);

    $access->resetMfa(actorFor($manager), $cashier, 'New phone');
    expect($cashier->fresh()->app_authentication_secret)->toBeNull()
        ->and(fn () => $access->resetMfa(actorFor($manager), $manager, 'Mine'))->toThrow(DomainException::class)
        ->and(fn () => $access->resetMfa(actorFor($manager), $this->owner, 'Above me'))->toThrow(AccessDenied::class)
        ->and($this->owner->fresh()->app_authentication_secret)->not->toBeNull();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->owner, 'web');
    Livewire::test(EditUser::class, ['record' => $manager->id])
        ->callAction('resetMfa', ['reason' => 'Lost phone'])->assertHasNoActionErrors();
    expect($manager->fresh()->app_authentication_secret)->toBeNull();
});

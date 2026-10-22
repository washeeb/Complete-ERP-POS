<?php

use App\Domain\Access\AccessService;
use App\Domain\Access\SupportAccount;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Auth\Login;
use App\Filament\Resources\Users\UserResource;
use App\Mail\PlainMessage;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Security\ScopeType;
use App\Security\SecurityLevel;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->clerk = staff($this->owner, 'Esi.Clerk', 2);
});

it('signs in with the username or the e-mail address', function () {
    $this->get('/admin/login')->assertOk()->assertSee('E-mail or username');
    Livewire::test(Login::class)->fillForm(['email' => 'ESI.CLERK', 'password' => 'correct-horse-battery'])->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($this->clerk);
    auth()->logout();

    Livewire::test(Login::class)->fillForm(['email' => 'esi.clerk@demo.test', 'password' => 'correct-horse-battery'])->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($this->clerk);
    auth()->logout();

    Livewire::test(Login::class)->fillForm(['email' => 'esi.clerk', 'password' => 'wrong-password-here'])->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
});

it('asks for a code sent by e-mail when the administrator chose it, and the code works once', function () {
    Mail::fake();
    app(AccessService::class)->updateUser(actorFor($this->owner), $this->clerk, ['signin_method' => 'code']);
    expect($this->clerk->fresh()->requiresMfa())->toBeTrue();

    $login = Livewire::test(Login::class)->fillForm(['email' => 'esi.clerk', 'password' => 'correct-horse-battery'])->call('authenticate');
    $this->assertGuest();
    $code = null;
    Mail::assertSent(PlainMessage::class, function (PlainMessage $m) use (&$code) {
        preg_match('/\b(\d{6})\b/', $m->text, $x);
        $code = $x[1] ?? null;

        return $m->hasTo('esi.clerk@demo.test');
    });
    expect($code)->not->toBeNull()
        ->and(AuditEvent::where('action', 'auth.code_sent')->exists())->toBeTrue()
        ->and(DB::table('messages')->where('body', 'like', "%{$code}%")->exists())->toBeFalse(); // the code is never logged

    $login->set('data.multiFactor.signin_code.code', $code === '000000' ? '111111' : '000000')->call('authenticate')->assertHasErrors();
    $this->assertGuest();
    $login->set('data.multiFactor.signin_code.code', $code)->call('authenticate')->assertHasNoErrors();
    $this->assertAuthenticatedAs($this->clerk);
});

it('lets the administrator allow the password only, for staff up to supervisors', function () {
    app(Settings::class)->set(actorFor($this->owner), 'security.mfa_from_level', 2);
    expect($this->clerk->fresh()->requiresMfa())->toBeTrue();
    app(AccessService::class)->updateUser(actorFor($this->owner), $this->clerk, ['signin_method' => 'password']);
    expect($this->clerk->fresh()->requiresMfa())->toBeFalse();
    $this->actingAs($this->clerk->fresh())->get('/admin')->assertOk();

    $manager = staff($this->owner, 'kojo', 4);
    expect(fn () => app(AccessService::class)->updateUser(actorFor($this->owner), $manager, ['signin_method' => 'password']))
        ->toThrow(DomainException::class, 'Password only is for staff up to level L3');
    expect(fn () => app(AccessService::class)->updateUser(actorFor($this->owner), $this->clerk, ['phone' => '12']))
        ->toThrow(DomainException::class, 'phone number');
});

it('adds the SyncLytics support account on the server only, above the owner, always with the app, and tells the owner', function () {
    $this->artisan('erp:support-account', ['--email' => 'support@synclytics.test'])
        ->expectsQuestion('Password for the support account (12+ characters)', 'a-long-support-password')
        ->assertSuccessful();
    $support = User::where('email', 'support@synclytics.test')->sole();
    expect($support->security_level)->toBe(SecurityLevel::Platform->value)->and($support->isSupport())->toBeTrue()
        ->and($support->username)->toBe(SupportAccount::USERNAME)->and($support->requiresMfa())->toBeTrue()
        ->and($support->hasPermission('admin.users.manage'))->toBeTrue()
        ->and($this->owner->notifications()->where('data->title', 'SyncLytics support account added')->exists())->toBeTrue();

    // Nobody in the company can give L7, edit the account or pick the Superadmin role.
    expect(fn () => app(AccessService::class)->updateUser(actorFor($this->owner), $support, ['signin_method' => 'password']))->toThrow(AccessDenied::class)
        ->and(fn () => app(AccessService::class)->grant(actorFor($this->owner), $this->clerk, Role::where('key', 'superadmin')->sole(), ScopeType::Tenant, $this->tenant->id))
        ->toThrow(DomainException::class);
    $this->actingAs(withMfa($this->owner))->get(UserResource::getUrl())->assertOk()->assertSee('SyncLytics support (Superadmin)');
    expect(UserResource::canEdit($support))->toBeFalse();

    // Each sign-in is told to the owner.
    auth()->logout();
    event(new Illuminate\Auth\Events\Login('web', $support, false));
    expect($this->owner->notifications()->where('data->title', 'SyncLytics support signed in')->exists())->toBeTrue();
});

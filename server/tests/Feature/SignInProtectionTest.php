<?php

use App\Domain\Access\AccessService;
use App\Domain\Access\SignInGuard;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\SignOutWhenIdle;
use App\Mail\PlainMessage;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// Owner decision 2026-11-13: IT and support always sign in with the authenticator app or a passkey and are signed out after
// 15 idle minutes; the owner and IT are told of a sign-in on a new computer, with "This wasn't me".

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    $this->cashier = staff($this->owner, 'ama', 2);
    $this->it = staff($this->owner, 'kofi', 4, 'it_admin');
    $this->owner = $this->owner->fresh();
    Mail::fake();
    $this->signIn = function ($user, string $computer) {
        request()->attributes->set('ses_computer', $computer);
        event(new Login('web', $user, false));
    };
});

it('always asks the owner and IT for a second step, whatever the company rule', function () {
    app(Settings::class)->set(actorFor(supportAccount($this->owner)), 'security.mfa_from_level', 7);
    expect($this->owner->requiresMfa())->toBeTrue()->and($this->it->requiresMfa())->toBeTrue()->and($this->cashier->fresh()->requiresMfa())->toBeFalse();
});

it('lets IT in only with the authenticator app or a passkey, never a code by message alone', function () {
    $this->actingAs($this->it, 'web')->get(UserResource::getUrl())->assertRedirect(); // to set up the authenticator
    $this->actingAs(withMfa($this->it), 'web')->get(UserResource::getUrl())->assertOk();

    $yaw = staff(supportAccount($this->owner), 'yaw', 4);
    $yaw->forceFill(['signin_method' => 'code'])->saveQuietly();
    expect(fn () => app(AccessService::class)->grant(actorFor(supportAccount($this->owner)), $yaw->fresh(), Role::where('key', 'it_admin')->first(), ScopeType::Tenant, $this->tenant->id))
        ->toThrow(DomainException::class, 'authenticator app or a passkey');
});

it('signs IT out after 15 minutes without a click or key; the bell refreshing is not activity', function () {
    $it = withMfa($this->it);
    $this->actingAs($it, 'web')->withSession([SignOutWhenIdle::SESSION_KEY => now()->subMinutes(10)->getTimestamp()])
        ->get(UserResource::getUrl())->assertOk();
    $this->actingAs($it, 'web')->withSession([SignOutWhenIdle::SESSION_KEY => now()->subMinutes(16)->getTimestamp()])
        ->get(UserResource::getUrl())->assertRedirect();
    $this->assertGuest('web');
    expect(AuditEvent::where('action', 'auth.idle_signed_out')->where('subject_id', $it->id)->exists())->toBeTrue();

    $refresh = Request::create('/livewire/update', 'POST', ['components' => [['calls' => [['method' => '$refresh']], 'updates' => []]]]);
    $refresh->headers->set('X-Livewire', '1');
    $typed = Request::create('/livewire/update', 'POST', ['components' => [['calls' => [], 'updates' => ['data.name' => 'Esi']]]]);
    $typed->headers->set('X-Livewire', '1');
    expect(SignOutWhenIdle::isActivity($refresh))->toBeFalse()->and(SignOutWhenIdle::isActivity($typed))->toBeTrue()
        ->and(SignOutWhenIdle::isActivity(Request::create('/admin')))->toBeTrue()
        ->and(SignInGuard::isStrict($this->owner))->toBeFalse(); // the owner is not on the 15-minute rule
});

it('tells the owner of a sign-in on a new computer, by notification and e-mail, and not for known ones', function () {
    [$home, $other] = [(string) Str::uuid7(), (string) Str::uuid7()];
    ($this->signIn)($this->owner, $home); // the first sign-in ever: nothing to compare with
    ($this->signIn)($this->owner, $home);
    expect($this->owner->notifications()->count())->toBe(0);

    ($this->signIn)($this->owner, $other);
    expect($this->owner->notifications()->where('data->title', 'Your account signed in on a new computer')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'auth.new_computer')->where('subject_id', $this->owner->id)->count())->toBe(1);
    Mail::assertSent(PlainMessage::class, fn ($m) => $m->hasTo($this->owner->email) && str_contains($m->text, 'account/not-me/'));

    ($this->signIn)($this->cashier, $home); // staff below the owner and IT are not watched
    ($this->signIn)($this->cashier, $other);
    expect($this->cashier->fresh()->notifications()->count())->toBe(0);
});

it('signs the account out everywhere and locks it on "This wasn\'t me", and tells support', function () {
    $computer = (string) Str::uuid7();
    DB::table('sessions')->insert(['id' => 'stolen-session', 'user_id' => $this->owner->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    $url = URL::temporarySignedRoute('account.not-me', now()->addDays(7), ['user' => $this->owner->id, 'computer' => $computer]);

    $this->get($url)->assertOk()->assertSee('was not you')->assertSee('Also lock the account');
    expect($this->owner->fresh()->is_active)->toBeTrue(); // opening the link changes nothing (mail scanners open links)

    $this->post($url, ['lock' => '1'])->assertOk()->assertSee('signed out everywhere')->assertSee('locked');
    expect($this->owner->fresh()->is_active)->toBeFalse()->and(DB::table('sessions')->where('user_id', $this->owner->id)->count())->toBe(0)
        ->and(AuditEvent::where('action', 'auth.not_me')->where('subject_id', $this->owner->id)->exists())->toBeTrue();
    Mail::assertSent(PlainMessage::class, fn ($m) => str_contains($m->subjectLine, 'not genuine'));

    $this->post(str_replace('signature=', 'signature=x', $url), ['lock' => '1'])->assertForbidden(); // a tampered link does nothing
});

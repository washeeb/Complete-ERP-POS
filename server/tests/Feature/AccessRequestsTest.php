<?php

use App\Domain\Access\AccessRequests;
use App\Domain\Access\AccessService;
use App\Domain\Access\SentForApproval;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Resources\AccessRequests\Pages\ListAccessRequests;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\AccessRequest;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Security\ScopeType;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

// Owner decision 2026-11-13: IT Admin asks; the owner or SyncLytics support approves; support acts directly and the
// owner is told; unanswered requests lapse after 72 hours.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->ama = staff($this->owner, 'ama', 2); // before IT Admin: after that the owner no longer adds users
    $this->it = withMfa(staff($this->owner, 'kofi', 4, 'it_admin'));
    $this->owner = withMfa($this->owner->fresh());
    $this->access = app(AccessService::class);
    $this->requests = app(AccessRequests::class);
    $this->sent = function (callable $do): AccessRequest {
        try {
            $do();
        } catch (SentForApproval $e) {
            return $e->request;
        }
        throw new RuntimeException('Expected a request for approval');
    };
});

it('turns IT Admin\'s new user into a request; the owner\'s yes adds it, without the password ever stored plainly', function () {
    $r = ($this->sent)(fn () => $this->access->createUser(actorFor($this->it), ['name' => 'Esi Teller', 'username' => 'esi', 'email' => 'esi@demo.test',
        'password' => 'a-good-long-password', 'security_level' => 2]));
    expect(User::where('username', 'esi')->exists())->toBeFalse()
        ->and($r->summary)->toContain('Esi Teller')->and(json_encode($r->payload))->not->toContain('a-good-long-password')
        ->and($this->owner->notifications()->where('data->title', 'Access change waiting for your approval')->exists())->toBeTrue();

    $done = $this->requests->approve(actorFor($this->owner), $r);
    $esi = User::where('username', 'esi')->first();
    expect($done->status)->toBe('approved')->and(Hash::check('a-good-long-password', $esi->password))->toBeTrue()
        ->and(AuditEvent::where('action', 'user.created')->where('subject_id', $esi->id)->value('reason'))->toContain('Asked by Kofi')
        ->and($this->it->notifications()->where('data->title', 'Your access request was approved')->exists())->toBeTrue();
});

it('gives access on approval with the approver\'s authority, technical roles included', function () {
    $teller = Role::where('key', 'teller')->first();
    $r = ($this->sent)(fn () => $this->access->grant(actorFor($this->it), $this->ama, $teller, ScopeType::Branch, $this->branches['HQ']->id));
    expect(RoleAssignment::where('user_id', $this->ama->id)->where('role_id', $teller->id)->exists())->toBeFalse();
    $this->requests->approve(actorFor($this->owner), $r);
    $g = RoleAssignment::where('user_id', $this->ama->id)->where('role_id', $teller->id)->first();
    expect($g)->not->toBeNull()->and($g->granted_by)->toBe($this->owner->id);

    // A second IT person: the owner holds no technical permissions, but the IT person who asked does.
    $yaw = ($this->sent)(fn () => $this->access->createUser(actorFor($this->it), ['name' => 'Yaw IT', 'username' => 'yaw', 'email' => 'yaw@demo.test', 'password' => 'a-good-long-password', 'security_level' => 4]));
    $this->requests->approve(actorFor($this->owner), $yaw);
    $r = ($this->sent)(fn () => $this->access->grant(actorFor($this->it), User::where('username', 'yaw')->first(), Role::where('key', 'it_admin')->first(), ScopeType::Tenant, $this->tenant->id));
    expect($this->requests->approve(actorFor($this->owner), $r)->status)->toBe('approved');
});

it('asks for a yes before a till PIN, a two-step reset or new sign-in details; a name or switching off happens at once', function () {
    $pin = ($this->sent)(fn () => $this->access->setPin(actorFor($this->it), $this->ama, '482613'));
    expect(json_encode($pin->payload))->not->toContain('482613');
    $this->requests->approve(actorFor($this->owner), $pin);
    expect(Hash::check('482613', $this->ama->fresh()->pin_hash))->toBeTrue();

    $mail = ($this->sent)(fn () => $this->access->updateUser(actorFor($this->it), $this->ama->fresh(), ['email' => 'ama.new@demo.test']));
    expect($mail->summary)->toContain('e-mail')->and($this->ama->fresh()->email)->toBe('ama@demo.test');

    $this->access->updateUser(actorFor($this->it), $this->ama->fresh(), ['name' => 'Ama Mensah']);
    $this->access->updateUser(actorFor($this->it), $this->ama->fresh(), ['is_active' => false]);
    expect($this->ama->fresh()->name)->toBe('Ama Mensah')->and($this->ama->fresh()->is_active)->toBeFalse();

    $support = supportAccount($this->owner);
    $reset = ($this->sent)(fn () => $this->access->resetMfa(actorFor($this->it), withMfa($this->ama->fresh()), 'New phone'));
    expect($this->requests->approve(actorFor($support), $reset)->status)->toBe('approved')
        ->and($this->ama->fresh()->app_authentication_secret)->toBeNull();
});

it('takes access away at once, without asking', function () {
    $g = $this->access->grant(actorFor(supportAccount($this->owner)), $this->ama, Role::where('key', 'teller')->first(), ScopeType::Tenant, $this->tenant->id);
    $this->access->revoke(actorFor($this->it), $g, 'Left the company');
    expect($g->fresh()->revoked_at)->not->toBeNull()->and(AccessRequest::count())->toBe(0);
});

it('lets only the owner or support decide, never the asker, and never about the asker\'s own account', function () {
    $r = ($this->sent)(fn () => $this->access->setPin(actorFor($this->it), $this->ama, '482613'));
    expect(fn () => $this->requests->approve(actorFor($this->it), $r))->toThrow(AccessDenied::class)
        ->and(fn () => $this->requests->approve(actorFor(withMfa(staff(supportAccount($this->owner), 'gm', 5, 'general_manager'))), $r))->toThrow(AccessDenied::class)
        ->and(fn () => $this->access->updateUser(actorFor($this->it), $this->it, ['email' => 'me@demo.test']))->toThrow(DomainException::class, 'your own');

    expect(fn () => $this->requests->reject(actorFor($this->owner), $r, ''))->toThrow(DomainException::class);
    expect($this->requests->reject(actorFor($this->owner), $r, 'Ama sets her own PIN')->status)->toBe('rejected')
        ->and(fn () => $this->requests->approve(actorFor($this->owner), $r->fresh()))->toThrow(DomainException::class, 'no longer waiting');

    $w = ($this->sent)(fn () => $this->access->setPin(actorFor($this->it), $this->ama, '482614'));
    expect($this->requests->withdraw(actorFor($this->it), $w)->status)->toBe('withdrawn');
});

it('lets a request lapse after 72 hours, and tells the person who asked', function () {
    $r = ($this->sent)(fn () => $this->access->setPin(actorFor($this->it), $this->ama, '482613'));
    $this->travel(73)->hours();
    $this->artisan('erp:access-requests')->assertSuccessful();
    expect($r->fresh()->status)->toBe('expired')
        ->and($this->it->notifications()->where('data->title', 'Your access request lapsed')->exists())->toBeTrue()
        ->and(fn () => $this->requests->approve(actorFor($this->owner), $r->fresh()))->toThrow(DomainException::class);
});

it('lets SyncLytics support act directly, and tells the owner each time', function () {
    $support = supportAccount($this->owner);
    $this->access->grant(actorFor($support), $this->ama, Role::where('key', 'teller')->first(), ScopeType::Tenant, $this->tenant->id);
    expect(AccessRequest::count())->toBe(0)
        ->and($this->owner->notifications()->where('data->title', 'SyncLytics support changed access')->count())->toBe(1);
});

it('shows it on the screens: IT asks from the user page, the owner approves from the list', function () {
    $this->actingAs($this->it, 'web');
    Livewire::test(CreateUser::class)->fillForm(['name' => 'Esi Teller', 'username' => 'esi', 'email' => 'esi@demo.test', 'password' => 'a-good-long-password',
        'security_level' => 2])->call('create')->assertRedirect()->assertNotified('Sent for approval');
    $r = AccessRequest::sole();
    Livewire::test(ListAccessRequests::class)->assertCanSeeTableRecords([$r])->assertTableActionHidden('approve', $r)->assertTableActionVisible('withdraw', $r);

    $this->actingAs($this->owner, 'web');
    Livewire::test(ListAccessRequests::class)->assertTableActionVisible('approve', $r)
        ->callAction(TestAction::make('approve')->table($r), ['note' => 'Fine'])->assertNotified('Approved and done');
    expect(User::where('username', 'esi')->exists())->toBeTrue();
});

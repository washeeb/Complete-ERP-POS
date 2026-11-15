<?php

use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\Actor;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Pages\ManageSettings;
use App\Filament\Support\Erp;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Supplier;
use App\Security\Reconfirm;
use App\Security\ReconfirmNeeded;
use App\Support\Settings;
use Filament\Support\Exceptions\Halt;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

// Owner decision 2026-11-13, limit GH₵ 3,000 (2026-11-15): money above the limit, a debt written off and new bank or MoMo
// details ask the person at the screen to confirm it is them (authenticator, passkey, a code sent, or the password).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    Reconfirm::$assumeConfirmed = false;
    session()->start();
    $this->a = actorFor($this->owner);
    $this->supplier = app(SupplierService::class)->save($this->a, new Supplier, ['name' => 'Nestlé Ghana']);
    $this->bank = Account::where('system_key', 'bank')->first();
    $this->pay = fn (int $minor, ?Actor $actor = null) => app(SupplierPaymentService::class)->pay($actor ?? $this->a, $this->supplier, $this->branches['HQ'], $this->bank, 'bank', $minor, 'TRF-'.$minor);
});

it('pays up to the limit at once and asks above it; a confirmation lasts five minutes', function () {
    expect(($this->pay)(300000)->amount_minor)->toBe(300000) // GH₵ 3,000: the limit itself is fine
        ->and(fn () => ($this->pay)(300001))->toThrow(ReconfirmNeeded::class, "Confirm it's you before paying a supplier above GH₵ 3,000.00");

    Reconfirm::mark();
    expect(($this->pay)(500000)->amount_minor)->toBe(500000);
    $this->travel(6)->minutes();
    expect(fn () => ($this->pay)(500000))->toThrow(ReconfirmNeeded::class);

    // The API, the server and the tills are not people at a screen: their own controls apply.
    expect(($this->pay)(500000, Actor::user($this->owner->fresh(), 'api'))->amount_minor)->toBe(500000);
});

it('always asks before new bank or MoMo details, and before changing the limit itself', function () {
    expect(fn () => app(SupplierService::class)->save($this->a, $this->supplier->fresh(), ['name' => 'Nestlé Ghana', 'bank_account_no' => '0012345678', 'bank_name' => 'GCB']))
        ->toThrow(ReconfirmNeeded::class, 'new bank or MoMo details')
        ->and($this->supplier->fresh()->bank_account_no)->toBeNull();

    $this->actingAs($this->owner, 'web');
    Livewire::test(ManageSettings::class)->set('data.'.str_replace('.', '__', Reconfirm::SETTING), 0)->call('save')->assertNotified("Confirm it's you first");
    expect((int) app(Settings::class)->get(Reconfirm::SETTING, $this->tenant->id))->toBe(300000);
});

it('confirms with the authenticator code on the page, and not with a wrong one', function () {
    $this->actingAs($this->owner, 'web');
    $code = app(Google2FA::class)->getCurrentOtp($this->owner->getAppAuthenticationSecret());

    Livewire::test(ConfirmItsYou::class)->set('code', '000000')->call('confirm')->assertNotified('That did not match')->assertSet('done', false);
    expect(Reconfirm::recent())->toBeFalse();

    Livewire::test(ConfirmItsYou::class)->set('code', $code)->call('confirm')->assertSet('done', true);
    expect(Reconfirm::recent())->toBeTrue()
        ->and(AuditEvent::where('action', 'auth.reconfirmed')->where('subject_id', $this->owner->id)->exists())->toBeTrue();
});

it('shows the note with the way to confirm on the payment screen', function () {
    $this->actingAs($this->owner, 'web');
    $r = Erp::class;
    expect(fn () => $r::run(fn () => ($this->pay)(900000)))->toThrow(Halt::class);
});

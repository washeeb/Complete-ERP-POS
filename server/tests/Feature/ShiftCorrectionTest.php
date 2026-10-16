<?php

use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Pos\ShiftService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Models\AuditEvent;
use App\Models\Till;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->branches['HQ']->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->teller = staff($this->owner, 'janet', 2, 'teller', ScopeType::Till, $till->id);
    $this->shifts = app(ShiftService::class);
    $this->overShort = fn () => (int) DB::table('journal_lines')->where('account_id', app(GeneralLedger::class)->account($this->tenant->id, 'cash_over_short')->id)->sum('amount_minor');
});

it('closes a shift no till can close, from the back office, with the reason', function () {
    $shift = $this->shifts->open($this->device, $this->teller, 0);
    $closed = $this->shifts->closeFromOffice(actorFor($this->owner), $shift, 0, 'Till reinstalled; test shift');
    expect($closed->status)->toBe('closed')->and($closed->over_short_minor)->toBe(0)->and($closed->closed_by_name)->toBe($this->owner->name)
        ->and($closed->close_note)->toBe('Closed in the back office: Till reinstalled; test shift')
        ->and(AuditEvent::where('action', 'shift.closed_in_office')->exists())->toBeTrue();
    expect(fn () => $this->shifts->closeFromOffice(actorFor($this->owner), $closed, 0, 'again'))->toThrow(DomainException::class, 'already closed');
    expect(fn () => $this->shifts->closeFromOffice(actorFor($this->teller), $this->shifts->open($this->device, $this->teller, 0), 0, 'x'))->toThrow(AccessDenied::class);
});

it('corrects a mistyped count without editing the shift, and puts the books right', function () {
    $shift = $this->shifts->open($this->device, $this->teller, 0);
    $closed = $this->shifts->close($this->device, $this->teller, 400000); // GH₵ 4,000 typed, nothing expected
    app(AutoPoster::class)->run($this->tenant->id);
    expect(($this->overShort)())->toBe(-400000); // credited to cash over/short

    $c = $this->shifts->correctCount(actorFor($this->owner), $closed, 0, 'Typed 4000 by mistake; no cash');
    expect($c->counted_before_minor)->toBe(400000)->and($c->over_short_after_minor)->toBe(0)
        ->and($closed->fresh()->counted_cash_minor)->toBe(400000) // the shift keeps its original count
        ->and(($this->overShort)())->toBe(0)                     // the books are right again
        ->and(AuditEvent::where('action', 'shift.count_corrected')->exists())->toBeTrue();
    expect(fn () => $this->shifts->correctCount(actorFor($this->owner), $closed->fresh(), 0, 'again'))->toThrow(DomainException::class, 'already recorded');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ListShifts::class)->assertSee('Corrected from')->assertTableActionVisible('correctCount', $closed);
});

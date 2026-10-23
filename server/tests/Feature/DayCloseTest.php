<?php

use App\Domain\Devices\DeviceService;
use App\Domain\Messaging\DailySummary;
use App\Domain\Pos\DayCloseService;
use App\Domain\Pos\ShiftService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\CloseDay;
use App\Models\AuditEvent;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Models\DayClose;
use App\Models\Till;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Branch::find($this->branches['HQ']->id);
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $till->id);
    $this->today = Carbon::now($this->hq->timezone ?: ($this->tenant->timezone ?: 'UTC'))->toDateString();
    $this->svc = app(DayCloseService::class);
    BackupRun::create(['file_name' => 'b1.zip', 'status' => 'ok', 'started_by' => 'scheduler', 'started_at' => now()->subHours(2)]);
});

it('says what is not wrapped up, and closes the day only with a reason until it is', function () {
    $shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $checks = collect($this->svc->checks($this->hq, $this->today)['checks'])->keyBy('key');
    expect($checks['tills']['status'])->toBe('fail')->and($checks['tills']['detail'])->toContain($this->cashier->name)
        ->and($checks['backup']['status'])->toBe('ok');

    expect(fn () => $this->svc->close(actorFor($this->owner), $this->hq, $this->today, []))->toThrow(DomainException::class, 'Every till closed')
        ->and(fn () => $this->svc->close(actorFor($this->cashier), $this->hq, $this->today, ['note' => 'x']))->toThrow(AccessDenied::class)
        ->and(fn () => $this->svc->close(actorFor($this->owner), $this->hq, Carbon::parse($this->today)->addDay()->toDateString(), ['note' => 'x']))->toThrow(DomainException::class, 'not before');
    $c = $this->svc->close(actorFor($this->owner), $this->hq, $this->today, ['note' => 'Till 1 left open for the night shift']);
    expect($c->problems)->toBe(1)->and(AuditEvent::where('action', 'day.closed')->count())->toBe(1);

    // The till closes later; the manager closes the day again with the bank deposit.
    app(ShiftService::class)->close($this->device, $this->cashier, 0, null, $shift->id);
    expect(fn () => $this->svc->close(actorFor($this->owner), $this->hq, $this->today, ['banked' => '150.00']))->toThrow(DomainException::class, 'deposit slip');
    $c = $this->svc->close(actorFor($this->owner), $this->hq, $this->today, ['banked' => '150.00', 'bank_ref' => 'SLIP-77', 'safe' => '20']);
    expect(DayClose::count())->toBe(1)->and($c->problems)->toBe(0)->and($c->banked_minor)->toBe(15000)->and($c->safe_minor)->toBe(2000)
        ->and(AuditEvent::where('action', 'day.closed_again')->count())->toBe(1);

    // The owner's evening summary says who closed each branch's day.
    $text = app(DailySummary::class)->build($this->tenant->fresh(), Carbon::parse($this->today))['text'];
    expect($text)->toContain($this->hq->name.' — by '.$this->owner->name)->toContain('not yet');
});

it('closes the day from Cash → Close the day', function () {
    $this->actingAs(withMfa($this->owner));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CloseDay::class, ['branch' => $this->hq->id])->assertSee('Every till closed')->assertSee('Backup')
        ->set('banked', '0')->set('note', 'Quiet day')->call('closeDay')->assertNotified('Day closed')->assertSee('Closed by '.$this->owner->name);
    expect(DayClose::where('branch_id', $this->hq->id)->exists())->toBeTrue();

    $this->actingAs(withMfa($this->cashier));
    $this->get(CloseDay::getUrl())->assertForbidden();
});

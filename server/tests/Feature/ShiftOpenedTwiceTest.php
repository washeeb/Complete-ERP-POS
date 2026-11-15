<?php

use App\Domain\Access\AccessService;
use App\Domain\Inventory\InventoryService;
use App\Models\AuditEvent;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Str;

// Owner report 2026-11-15 (SUPBOG): Open shift pressed again while the first press was on its way. The server accepted
// one shift; the till queued a second one seconds later and sold under it; the server refused the second shift and all
// 146 sales in it. Same person, same till, within 10 minutes: it is the same shift.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), Warehouse::find($till->warehouse_id), $this->milo, $this->milo->units->first(), '50', 'opening', '');
    $teller = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $teller, '482613');
    $this->t = fakeTill($this, $till, 'esi', '482613');
    $this->at = fn (int $s) => now()->subHours(3)->addSeconds($s)->toIso8601String();
});

it('takes a second open of the same shift as the same shift, so the sales in it are not refused', function () {
    $first = $this->t->openShift(0, ($this->at)(0));
    $realId = $this->t->shiftId;
    $this->t->sync([$first])->assertOk();
    $second = $this->t->openShift(0, ($this->at)(9)); // pressed again: a new id, 9 seconds later
    $twinId = $this->t->shiftId;
    $sale = $this->t->sale([[$this->milo, '1', null]], ($this->at)(60));

    $r = $this->t->sync([$second, $sale])->assertOk()->json('results');
    expect($r[0]['status'])->toBe('duplicate')->and($r[1]['status'])->toBe('accepted')
        ->and(Shift::count())->toBe(1)->and(Sale::sole()->shift_id)->toBe($realId)
        ->and(AuditEvent::where('action', 'shift.opened_twice')->where('subject_id', $realId)->exists())->toBeTrue();

    // Its close closes the real shift; the same events again change nothing.
    $close = $this->t->closeShift(5800, ($this->at)(3600));
    expect($this->t->sync([$close])->json('results.0.status'))->toBe('accepted')
        ->and(Shift::find($realId)->status)->toBe('closed')
        ->and($this->t->sync([$second, $sale])->json('results.*.status'))->toBe(['duplicate', 'duplicate']);
    expect($twinId)->not->toBe($realId);
});

it('brings in sales that were refused before the fix when the till retries, and flags those after the count', function () {
    $this->t->sync([$this->t->openShift(0, ($this->at)(0))])->assertOk();
    $realId = $this->t->shiftId;
    $close = $this->t->closeShift(0, ($this->at)(7200));
    $this->t->shiftId = $realId;
    $this->t->sync([$close])->assertOk(); // the shift was counted and closed with no sales, as on 14 Nov

    // Retry refused: the second open, then a sale made in it before the close.
    $second = $this->t->openShift(0, ($this->at)(8));
    $sale = $this->t->sale([[$this->milo, '2', null]], ($this->at)(600));
    expect($this->t->sync([$second, $sale])->json('results.*.status'))->toBe(['duplicate', 'accepted'])
        ->and(Sale::sole()->shift_id)->toBe($realId)
        ->and(ReviewItem::where('type', 'after_count')->count())->toBe(1);
});

it('still refuses a second shift by someone else, or long after', function () {
    $this->t->sync([$this->t->openShift(0, ($this->at)(0))])->assertOk();
    $late = $this->t->openShift(0, ($this->at)(20 * 60)); // 20 minutes later: not a double press
    expect($this->t->sync([$late])->json('results.0.code'))->toBe('shift_open');

    $other = staff($this->owner, 'kofi', 2, 'teller', ScopeType::Till, Till::where('branch_id', $this->branches['HQ']->id)->first()->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $other, '482614');
    $grant = $this->withToken($this->t->token)->postJson('/api/v1/pos/login', ['username' => 'kofi', 'pin' => '482614'])->json('offline_grant');
    $this->withoutToken();
    app('auth')->forgetGuards();
    $id = (string) Str::uuid7();
    $theirs = ['id' => $id, 'type' => 'shift_open', 'grant' => $grant, 'payload' => ['id' => $id, 'opening_float_minor' => 0, 'opened_at' => ($this->at)(5)]];
    expect($this->t->sync([$theirs])->json('results.0.code'))->toBe('shift_open');
});

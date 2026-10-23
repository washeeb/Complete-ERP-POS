<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Messaging\DailySummary;
use App\Models\Message;
use App\Models\Warehouse;
use App\Support\Settings;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-23 12:00', 'Africa/Accra'));
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

it('builds the summary with payments, stock and today’s losses', function () {
    $hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $p = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00');
    app(InventoryService::class)->adjust($this->a, $hq, $p, $p->units->first(), '20', 'opening', '', null, '10.00');
    app(InventoryService::class)->adjust($this->a, $hq, $p, $p->units->first(), '-2', 'adjustment', 'damaged'); // GH₵ 20 lost today
    $text = app(DailySummary::class)->build($this->tenant->fresh(), now())['text'];
    expect($text)->toContain('Losses today: GH₵ 20.00')->toContain('Cash GH₵ 0.00');
});

it('goes once a day after its time, to the owner by bell and e-mail', function () {
    $s = app(Settings::class);
    $s->set($this->a, 'summary.daily', true);
    expect(app(DailySummary::class)->sendDue())->toBe(0); // 12:00, before 20:00
    Carbon::setTestNow(Carbon::parse('2026-10-23 20:10', 'Africa/Accra'));
    expect(app(DailySummary::class)->sendDue())->toBe(1)
        ->and(app(DailySummary::class)->sendDue())->toBe(0); // once a day
    $n = $this->owner->notifications()->sole();
    expect($n->data['title'])->toContain('Fri 23 Oct 2026')->and($n->data['body'])->toContain('Sales: GH₵ 0.00')->toContain('Nothing waiting for approval.')
        ->and(Message::where('template_key', 'daily_summary')->where('channel', 'email')->where('to', $this->owner->email)->exists())->toBeTrue();
});

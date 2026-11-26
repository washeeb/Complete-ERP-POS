<?php

use App\Domain\Inventory\InventoryService;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;

// Today in the new back office (part 37): the owner's phone view, today or yesterday, with "needs attention" linked to the
// new pages; refused to people without the daily summary.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner);
});

it('shows the business today and yesterday on one screen, and links what needs attention', function () {
    $this->actingAs($this->owner, 'web');
    $d = $this->getJson('/office-api/today')->assertOk()->json();
    expect($d)->toMatchArray(['yesterday' => false, 'count' => 0, 'closes' => 0, 'over_short' => 0, 'over_short_text' => 'balanced'])
        ->and(collect($d['payments'])->pluck('label')->all())->toBe(['Cash', 'Mobile money', 'Card'])
        ->and($d['branches'])->toHaveCount(count($this->b))->and($d['branches'][0]['closed'])->toBeNull();
    $y = $this->getJson('/office-api/today?yesterday=1')->assertOk()->json();
    expect($y['yesterday'])->toBeTrue()->and($y['date'])->not->toBe($d['date']);

    // A batch expiring soon needs attention, and opens the expiry page of the new look.
    $o = actorFor($this->owner);
    $wh = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $milk = makeProduct($this->owner, 'MILK', 'Milk 1L', retail: '10.00');
    DB::table('products')->where('id', $milk->id)->update(['track_batches' => true]);
    app(InventoryService::class)->adjust($o, $wh, $milk->fresh(), $milk->units->first(), '5', 'opening', '', null, '6.00', 'B1', now()->addDays(5)->toDateString());
    $att = collect($this->getJson('/office-api/today')->json('attention'))->firstWhere('link', 'expiry');
    expect($att)->toMatchArray(['tone' => 'warning', 'open' => '/office/expiry'])->and($att['text'])->toContain('expiring');
});

it('is refused to people without the daily summary', function () {
    $this->actingAs(staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id), 'web');
    $this->getJson('/office-api/today')->assertForbidden();
});

<?php

use App\Domain\Access\AccessReview;
use App\Filament\Pages\AccessReviewPage;
use App\Security\ScopeType;
use Filament\Facades\Filament;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('flags one person ordering and receiving, not the owner or a split team', function () {
    $manager = staff($this->owner, 'abena', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $buyer = staff($this->owner, 'kofi', 2, 'purchasing_officer', ScopeType::Branch, $this->branches['HQ']->id);
    $store = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);

    $r = app(AccessReview::class)->build($this->tenant->id);
    $orderReceive = collect($r['risks'])->firstWhere('key', 'order_receive');
    $who = collect($orderReceive['who'])->pluck('name');
    expect($who)->toContain($manager->name)
        ->not->toContain($this->owner->name)
        ->not->toContain($buyer->name)
        ->not->toContain($store->name)
        ->and($orderReceive['who'][0]['where'])->toContain('Head Office')
        ->and(collect($r['people'])->firstWhere('owner', true)['user']->id)->toBe($this->owner->id)
        ->and($r['counts']['high'])->toBeGreaterThan(0);
});

it('notes access left on people who are switched off or never sign in', function () {
    $gone = staff($this->owner, 'esi', 3, 'cashier', ScopeType::Branch, $this->branches['HQ']->id);
    $gone->forceFill(['is_active' => false])->save();

    $notes = collect(app(AccessReview::class)->build($this->tenant->id)['notes'])->pluck('text')->implode("\n");
    expect($notes)->toContain($gone->name.' is switched off but still has');
});

it('shows the page to the owner and an auditor, and not to a cashier', function () {
    $this->actingAs(withMfa($this->owner), 'web')->get(AccessReviewPage::getUrl())->assertOk()->assertSee('Risky combinations')->assertSee('Everyone');
    $auditor = withMfa(staff($this->owner, 'efua', 1, 'auditor', ScopeType::Tenant, $this->tenant->id));
    $this->flushSession();
    $this->actingAs($auditor, 'web')->get(AccessReviewPage::getUrl())->assertOk();
    $cashier = withMfa(staff($this->owner, 'kojo', 3, 'cashier', ScopeType::Branch, $this->branches['HQ']->id));
    $this->flushSession();
    $this->actingAs($cashier, 'web')->get(AccessReviewPage::getUrl())->assertForbidden();
});

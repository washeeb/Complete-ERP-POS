<?php

use App\Domain\Sync\ReviewQueue;
use App\Filament\Resources\ReviewItems\Pages\ListReviewItems;
use App\Filament\Resources\ReviewItems\ReviewItemResource;
use App\Filament\Resources\SyncPeers\Pages\ListSyncPeers;
use App\Filament\Resources\SyncPeers\SyncPeerResource;
use App\Models\ReviewItem;
use App\Models\SyncPeer;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('registers a branch server from the Sync Center and shows its secret once', function () {
    Livewire::test(ListSyncPeers::class)
        ->callAction('addBranch', ['node_id' => 'b01', 'name' => 'Branch A server'])
        ->assertNotified('Branch server registered');

    $peer = SyncPeer::first();
    expect($peer->node_id)->toBe('B01')->and($peer->role)->toBe('branch')
        ->and(strlen($peer->secret))->toBe(48)
        ->and(DB::table('sync_peers')->value('secret'))->not->toBe($peer->secret) // encrypted at rest
        ->and($peer->groups)->toHaveKeys(['sales', 'stock', 'reviews']);

    $this->get(SyncPeerResource::getUrl())->assertOk()->assertSee('Branch A server')->assertSee('Node B01')->assertSee('Waiting');

    Livewire::test(ListSyncPeers::class)->callTableAction('toggle', $peer)->assertNotified('Disabled');
    expect($peer->fresh()->is_active)->toBeFalse();
});

it('lists open review items with their type and lets a manager resolve them with a note', function () {
    DB::transaction(fn () => app(ReviewQueue::class)->add(actorFor($this->owner), 'negative_stock', 'product', null,
        'Bread is at -1.000 in BA-W1 — count it', [], $this->branches['BA']->id));
    $item = ReviewItem::first();

    $this->get(ReviewItemResource::getUrl())->assertOk()->assertSee('Stock went below zero after syncing')->assertSee('count it');

    Livewire::test(ListReviewItems::class)
        ->callTableAction('resolve', $item, ['resolution' => 'Counted: 0 left. Adjustment posted.'])
        ->assertNotified('Resolved');
    expect($item->fresh()->status)->toBe('resolved')->and($item->fresh()->resolved_by_name)->toBe($this->owner->name);
});

it('limits the review queue to the branches a manager covers', function () {
    $mgr = withMfa(staff($this->owner, 'bm', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id));
    DB::transaction(function () {
        app(ReviewQueue::class)->add(actorFor($this->owner), 'clock_skew', 'sale', null, 'Branch A item', [], $this->branches['BA']->id);
        app(ReviewQueue::class)->add(actorFor($this->owner), 'clock_skew', 'sale', null, 'HQ item', [], $this->branches['HQ']->id);
    });

    $this->actingAs($mgr)->get(ReviewItemResource::getUrl())->assertOk()->assertSee('Branch A item')->assertDontSee('HQ item');
});

it('keeps the Sync Center away from people without sync rights', function () {
    $mgr = withMfa(staff($this->owner, 'gm', 5, 'general_manager'));

    $this->actingAs($mgr)->get(SyncPeerResource::getUrl())->assertForbidden();
});

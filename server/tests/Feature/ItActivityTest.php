<?php

use App\Domain\Access\AccessService;
use App\Domain\Access\SentForApproval;
use App\Domain\Catalog\CatalogService;
use App\Filament\Pages\ItActivity;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Security\ScopeType;
use App\Support\Settings;
use Livewire\Livewire;

// Owner decision 2026-11-13: "Owner sees all IT activity": what IT and support did, and every technical change by anyone.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->ama = staff($this->owner, 'ama', 2);
    $this->it = withMfa(staff($this->owner, 'kofi', 4, 'it_admin'));
    $this->owner = withMfa($this->owner->fresh());
    $this->support = supportAccount($this->owner);
});

it('shows the owner what IT and support did and every technical change, and leaves everyday business out', function () {
    try { // IT asks for a PIN (a request); support gives access directly; the owner changes a setting and a product
        app(AccessService::class)->setPin(actorFor($this->it), $this->ama, '482613');
    } catch (SentForApproval) {
    }
    app(AccessService::class)->grant(actorFor($this->support), $this->ama, Role::where('key', 'teller')->first(), ScopeType::Tenant, $this->tenant->id);
    app(Settings::class)->set(actorFor($this->owner), 'receipt.footer', 'Thank you');
    $product = makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $product, ['name' => 'Rice 5 kg bag']);

    $asked = AuditEvent::where('action', 'access_request.submitted')->sole();
    $granted = AuditEvent::where('action', 'access.granted')->where('actor_id', $this->support->id)->sole();
    $setting = AuditEvent::where('action', 'setting.changed')->latest('seq')->first();
    $productEdit = AuditEvent::where('action', 'product.updated')->latest('seq')->first();

    $this->actingAs($this->owner, 'web');
    Livewire::test(ItActivity::class)->assertSuccessful()
        ->assertCanSeeTableRecords([$asked, $granted, $setting])
        ->assertCanNotSeeTableRecords([$productEdit])
        ->assertSee('SyncLytics support');
    Livewire::test(ItActivity::class)->filterTable('who', 'support')
        ->assertCanSeeTableRecords([$granted])->assertCanNotSeeTableRecords([$asked, $setting]);
});

it('is the owner\'s and support\'s to see, not staff\'s', function () {
    $this->actingAs($this->ama->fresh(), 'web')->get(ItActivity::getUrl())->assertForbidden();
    $this->flushSession(); // a new person at the screen
    $this->actingAs($this->owner, 'web')->get(ItActivity::getUrl())->assertOk()->assertSee('IT and support activity');
});

<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\LicensePage;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Till;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->archive = app(ArchiveService::class);
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
});

it('archives a branch made by mistake, takes it out of the lists, and restores it as it was', function () {
    $ba = $this->branches['BA'];
    $spare = Warehouse::where('branch_id', $ba->id)->first();
    $tills = Till::where('branch_id', $ba->id)->pluck('id');
    Till::whereKey($tills->first())->update(['is_active' => false]); // already off before: stays off after restoring

    $this->archive->archive($this->a, $ba, 'Created by mistake');
    $ba->refresh();
    expect($ba->archived_at)->not->toBeNull()->and($ba->is_active)->toBeFalse()->and($ba->archive_reason)->toBe('Created by mistake')
        ->and($spare->fresh()->is_active)->toBeFalse()->and(Till::whereIn('id', $tills)->where('is_active', true)->count())->toBe(0)
        ->and(Branch::choices())->not->toHaveKey($ba->id)
        ->and(Branch::choices($ba->id))->toHaveKey($ba->id) // a record that already has it still shows it
        ->and(AuditEvent::where('action', 'branch.archived')->where('subject_id', $ba->id)->exists())->toBeTrue();

    $this->archive->restore($this->a, $ba);
    expect($ba->fresh()->archived_at)->toBeNull()->and($ba->fresh()->is_active)->toBeTrue()->and($spare->fresh()->is_active)->toBeTrue()
        ->and(Till::whereKey($tills->first())->value('is_active'))->toBeFalse()
        ->and(Till::whereIn('id', $tills->slice(1))->where('is_active', false)->count())->toBe(0);
});

it('refuses to archive a branch that still trades, and says what to do first', function () {
    $ba = $this->branches['BA'];
    expect($this->archive->blockers($this->branches['HQ']))->toContain('It is the head office. Make another branch the head office first.');

    $p = makeProduct($this->owner, 'RICE', 'Rice 5kg', '90');
    $store = Warehouse::where('branch_id', $ba->id)->first();
    app(InventoryService::class)->adjust($this->a, $store, $p, $p->units->firstWhere('is_base', true), '4', 'opening', '', 'Loaded', '50');
    Device::forceCreate(['tenant_id' => $this->tenant->id, 'branch_id' => $ba->id, 'till_id' => Till::where('branch_id', $ba->id)->value('id'),
        'name' => 'Till 1', 'kind' => 'pos', 'status' => 'active', 'created_by' => $this->owner->id]);

    $why = implode(' ', $this->archive->blockers($ba));
    expect($why)->toContain('still hold stock')->toContain('device(s) are still enrolled');
    expect(fn () => $this->archive->archive($this->a, $ba, 'Closed'))->toThrow(DomainException::class);
    expect($ba->fresh()->archived_at)->toBeNull();
});

it('archives an industry only once no branch in use runs it, and hides it from the pickers', function () {
    expect($this->archive->blockers($this->grocery)[0])->toContain('Branches still use it (BA)');
    $this->archive->archive($this->a, $this->branches['BA'], 'Closed');
    $this->archive->archive($this->a, $this->grocery, 'No more groceries');
    expect(Industry::licensed()->whereKey($this->grocery->id)->exists())->toBeFalse();

    // Restoring the branch first is refused: its industry is archived.
    expect(fn () => $this->archive->restore($this->a, $this->branches['BA']->fresh()))->toThrow(DomainException::class, 'Restore');
});

it('archives a legal entity only when nothing keeps its books there, never the head office company', function () {
    $own = LegalEntity::create(['tenant_id' => $this->tenant->id, 'name' => 'Mistake Ltd', 'currency_code' => 'GHS', 'is_primary' => false, 'is_active' => true]);
    $head = LegalEntity::where('is_primary', true)->first();
    expect($this->archive->blockers($head))->not->toBeEmpty();
    $this->archive->archive($this->a, $own, 'Typed twice');
    expect(LegalEntity::choices())->not->toHaveKey($own->id)->and(LegalEntity::choices())->toHaveKey($head->id);
});

it('needs a reason, and lists only the ones in use unless asked', function () {
    expect(fn () => $this->archive->archive($this->a, $this->branches['BA'], ''))->toThrow(DomainException::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ListBranches::class)->callTableAction('archive', $this->branches['BA'], data: ['reason' => 'Closed'])->assertHasNoTableActionErrors();
    expect($this->branches['BA']->fresh()->archive_reason)->toBe('Closed');
    Livewire::test(ListBranches::class)->assertCanSeeTableRecords([$this->branches['HQ']])->assertCanNotSeeTableRecords([$this->branches['BA']])
        ->filterTable('archived', true)->assertCanSeeTableRecords([$this->branches['BA']])
        ->callTableAction('restore', $this->branches['BA']);
    expect($this->branches['BA']->fresh()->archived_at)->toBeNull();
});

it('frees the place of an archived branch in the licence', function () {
    $count = fn () => app(LicensePage::class)->usage()['branches'];
    expect($count())->toBe(2);
    $this->archive->archive($this->a, $this->branches['BA'], 'Closed');
    expect($count())->toBe(1);
});

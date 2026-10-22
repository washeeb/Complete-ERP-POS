<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\BatchesExpiry;
use App\Models\AuditEvent;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $med = makeProduct($this->owner, 'AMOX', 'Amoxicillin 500mg', retail: '30.00');
    app(CatalogService::class)->saveProduct($this->a, $med, ['track_batches' => true]);
    $this->med = $med->fresh(['units']);
    app(InventoryService::class)->adjust($this->a, $this->hq, $this->med, $this->med->units->first(), '10', 'opening', '', null, '20.00', 'B1', '2027-03-31');
    $this->b1 = StockBatch::where('batch_no', 'B1')->sole();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('corrects a batch expiry date from Batches & expiry, with the reason in the audit log', function () {
    Livewire::test(BatchesExpiry::class)->set('warehouse', $this->hq->id)->assertSee('Correct expiry')
        ->callAction('correct', ['expires_on' => '2027-09-30', 'reason' => 'Mistyped at receiving'], ['batch' => $this->b1->id])
        ->assertNotified('Expiry date corrected');
    expect($this->b1->fresh()->expires_on->toDateString())->toBe('2027-09-30')
        ->and(AuditEvent::where('action', 'stock_batch.corrected')->value('reason'))->toBe('Mistyped at receiving')
        ->and((string) DB::table('stock_batch_levels')->where('batch_id', $this->b1->id)->value('on_hand'))->toBe('10.000'); // stock untouched

    expect(fn () => app(InventoryService::class)->correctBatch($this->a, $this->b1->fresh(), '2027-09-30', 'Same again'))->toThrow(DomainException::class, 'Nothing was changed')
        ->and(fn () => app(InventoryService::class)->correctBatch($this->a, $this->b1->fresh(), '2028-01-31', ''))->toThrow(DomainException::class, 'Say why');
});

it('adds a batch the system does not have, and moves stock from a wrong batch number to the right one, as draft adjustments', function () {
    Livewire::test(BatchesExpiry::class)->set('warehouse', $this->hq->id)
        ->callAction('addBatch', ['kind' => 'found', 'product_id' => $this->med->id, 'batch_no' => 'b9', 'expires_on' => '2028-06-30', 'qty' => '4'])
        ->assertNotified('Draft stock adjustment made — check and post it');
    $found = StockAdjustment::sole();
    expect($found->reason_code)->toBe('found')->and($found->lines()->sole()->batch_no)->toBe('B9');

    Livewire::test(BatchesExpiry::class)->set('warehouse', $this->hq->id)
        ->callAction('addBatch', ['kind' => 'move', 'product_id' => $this->med->id, 'from_batch_id' => $this->b1->id, 'batch_no' => 'B2', 'expires_on' => '2027-12-31', 'qty' => '3']);
    $move = StockAdjustment::where('reason_code', 'correction')->sole();
    expect($move->lines()->orderBy('line_no')->get(['mode', 'batch_no'])->map(fn ($l) => "{$l->mode} {$l->batch_no}")->all())->toBe(['remove B1', 'add B2']);

    app(StockAdjustmentService::class)->post($this->a, $move->fresh());
    $level = fn (string $no) => (string) DB::table('stock_batch_levels')->where('batch_id', StockBatch::where('batch_no', $no)->value('id'))->value('on_hand');
    expect($level('B1'))->toBe('7.000')->and($level('B2'))->toBe('3.000');
});

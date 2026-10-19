<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Support\DomainException;
use App\Domain\Sync\ReplicationApplier;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Uom;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $c = app(CatalogService::class);
    $this->medicine = $c->saveSimple($this->a, new Category, ['name' => 'Medicine', 'is_active' => true]);
    $this->meds = $c->saveSimple($this->a, new Category, ['name' => 'Meds (old)', 'is_active' => true]);
    $this->ernest = $c->saveSimple($this->a, new Brand, ['name' => 'Ernest']);
    $this->p1 = makeProduct($this->owner, 'P1', 'Paracetamol', retail: '5.00');
    $this->p2 = makeProduct($this->owner, 'P2', 'Ibuprofen', retail: '6.00');
    $c->moveProducts($this->a, [$this->p1, $this->p2], 'category_id', $this->meds->id);
});

it('moves products between categories, brands and tax groups, and deletes only what nothing uses', function () {
    $c = app(CatalogService::class);
    expect($this->p1->fresh()->category_id)->toBe($this->meds->id);
    expect(fn () => $c->deleteSimple($this->a, $this->meds->fresh()))->toThrow(DomainException::class, 'still used by 2 products');

    // Merge the duplicate: move everything, then delete the empty one.
    expect($c->moveProducts($this->a, [$this->p1->id, $this->p2->id], 'category_id', $this->medicine->id))->toBe(2);
    $c->deleteSimple($this->a, $this->meds->fresh());
    expect(Category::find($this->meds->id))->toBeNull()->and(DB::table('audit_events')->where('action', 'category.deleted')->count())->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'category.deleted')->count())->toBe(1);

    $c->moveProducts($this->a, [$this->p1], 'brand_id', $this->ernest->id);
    expect(fn () => $c->deleteSimple($this->a, $this->ernest->fresh()))->toThrow(DomainException::class, 'still used');
    $c->moveProducts($this->a, [$this->p1], 'brand_id', null);
    $c->deleteSimple($this->a, $this->ernest->fresh());
    expect(Brand::count())->toBe(0);

    expect(fn () => $c->moveProducts($this->a, [$this->p1], 'tax_group_id', null))->toThrow(DomainException::class, 'tax group');
    $pc = Uom::where('code', 'PC')->first();
    expect(fn () => $c->deleteSimple($this->a, $pc))->toThrow(DomainException::class, 'still used');
});

it('deletes on branch servers too when head office deletes an unused category', function () {
    $id = $this->medicine->id;
    $event = ['id' => 'e1', 'tenant_id' => $this->tenant->id, 'origin_node' => 'HQ', 'origin_seq' => 1, 'aggregate_type' => 'category', 'aggregate_id' => $id,
        'event_type' => 'category.deleted', 'payload' => ['snapshot' => ['row' => ['id' => $id]]]];
    DB::transaction(fn () => app(ReplicationApplier::class)->apply($event));
    expect(Category::find($id))->toBeNull();
    // Still used here: kept.
    $event['aggregate_id'] = $this->meds->id;
    DB::transaction(fn () => app(ReplicationApplier::class)->apply($event));
    expect(Category::find($this->meds->id))->not->toBeNull();
});

it('moves ticked products and merges from the screens', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(ListProducts::class)->callTableBulkAction('move', [$this->p1->id], ['field' => 'brand_id', 'to' => $this->ernest->id])
        ->assertNotified('1 product(s) moved');
    expect($this->p1->fresh()->brand_id)->toBe($this->ernest->id);
    Livewire::test(ListCategories::class)->callTableAction('moveProducts', $this->meds->id, ['to' => $this->medicine->id])->assertNotified('2 product(s) moved')
        ->callTableAction('delete', $this->meds->id)->assertNotified('Deleted');
    expect(Category::find($this->meds->id))->toBeNull();
    Livewire::test(ListBrands::class)->callTableAction('delete', $this->ernest->id)->assertNotified();
    expect(Brand::find($this->ernest->id))->not->toBeNull(); // still used by Paracetamol
});

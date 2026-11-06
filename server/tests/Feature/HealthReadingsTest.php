<?php

use App\Domain\Pharmacy\HealthReadings;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\HealthReadingsPage;
use App\Models\Customer;
use App\Models\HealthReading;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Security\ScopeType;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->svc = app(HealthReadings::class);
    $this->branch = $this->branches['HQ']->id;
});

it('flags readings by common screening limits', function (string $kind, ?int $s, ?int $d, ?float $v, string $flag) {
    expect(HealthReadings::flag($kind, $s, $d, $v))->toBe($flag);
})->with([
    ['bp', 120, 80, null, 'normal'], ['bp', 139, 89, null, 'normal'], ['bp', 140, 80, null, 'high'], ['bp', 130, 90, null, 'high'], ['bp', 180, 100, null, 'urgent'], ['bp', 150, 120, null, 'urgent'], ['bp', 88, 58, null, 'low'],
    ['sugar', null, null, 5.4, 'normal'], ['sugar', null, null, 11.1, 'high'], ['sugar', null, null, 3.5, 'low'], ['sugar', null, null, 2.5, 'urgent'], ['sugar', null, null, 20.0, 'urgent'],
    ['temperature', null, null, 36.8, 'normal'], ['temperature', null, null, 38.2, 'high'], ['temperature', null, null, 40.0, 'urgent'], ['weight', null, null, 70.0, 'normal'],
]);

it('keeps a reading on the patient, refuses nonsense, and is only for those who see patients', function () {
    $c = Customer::create(['code' => 'C1', 'name' => 'Abena', 'tenant_id' => $this->tenant->id]);
    $r = $this->svc->record($this->a, ['kind' => 'bp', 'branch_id' => $this->branch, 'customer_id' => $c->id, 'systolic' => 128, 'diastolic' => 82, 'pulse' => 74]);
    expect($r->flag)->toBe('normal')->and($r->summary())->toBe('128/82 mmHg, pulse 74')->and($r->customer_id)->toBe($c->id);
    expect(fn () => $this->svc->record($this->a, ['kind' => 'bp', 'branch_id' => $this->branch, 'systolic' => 80, 'diastolic' => 120]))->toThrow(DomainException::class, 'two blood pressure numbers');
    expect(fn () => $this->svc->record($this->a, ['kind' => 'sugar', 'branch_id' => $this->branch]))->toThrow(DomainException::class, 'Enter the reading');
    $store = staff($this->owner, 'store', 2, 'storekeeper', ScopeType::Tenant, $this->tenant->id);
    expect(fn () => $this->svc->record(actorFor($store), ['kind' => 'weight', 'branch_id' => $this->branch, 'value' => 70]))->toThrow(AccessDenied::class);
});

it('sets up a fee service, records and lists readings on the page', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(HealthReadingsPage::class)->assertSuccessful()
        ->callAction('service', ['name' => 'Blood pressure check', 'price' => '5.00', 'reading_kind' => 'bp', 'tax_group_id' => TaxGroup::query()->value('id')])->assertHasNoActionErrors();
    $p = Product::where('reading_kind', 'bp')->sole();
    expect($p->track_stock)->toBeFalse();

    Livewire::test(HealthReadingsPage::class)->callAction('take', ['branch_id' => $this->branch, 'kind' => 'bp', 'systolic' => '150', 'diastolic' => '95'])->assertHasNoActionErrors();
    Livewire::test(HealthReadingsPage::class)->assertCountTableRecords(1)->assertSee('150/95 mmHg')->assertSee('High')->filterTable('flag', ['normal'])->assertCountTableRecords(0);
    expect(HealthReading::sole()->taken_by_name)->toBe('Ama Owner');
});

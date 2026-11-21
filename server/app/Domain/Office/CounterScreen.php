<?php

namespace App\Domain\Office;

use App\Filament\Pages\HealthReadingsPage;
use App\Filament\Pages\ScaleItems;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Customer;
use App\Models\HealthReading;
use App\Models\TaxGroup;
use App\Models\User;
use App\Support\Money;

/**
 * Two industry pages of the counter in the new back office (part 20 of the rewrite), as the current pages: Pharmacy →
 * Health checks (every reading taken, Record a reading, Add a fee service) and Grocery → Scale items (the weighed
 * products the scales are loaded with). Same queries and rules: HealthReadings and CatalogService (CounterController).
 */
final class CounterScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const FLAG_TONE = ['urgent' => 'danger', 'high' => 'warning', 'low' => 'warning', 'retest' => 'warning', 'normal' => 'success'];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    // -------------------------------------------------------------------------------------------- Health checks

    public static function canHealth(): bool
    {
        return HealthReadingsPage::canAccess();
    }

    public static function canAddService(): bool
    {
        return Erp::canAnywhere('catalog.manage') && Erp::canAnywhere('pricing.manage');
    }

    /** @param  array<string, mixed>  $p  from, to, kind, flags (comma-separated), patient, page, per */
    public function readings(array $p): array
    {
        $q = HealthReading::query()->with('customer', 'branch');
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null;
        if ($from = $date($p['from'] ?? '')) {
            $q->where('taken_at', '>=', $from.' 00:00:00');
        }
        if ($to = $date($p['to'] ?? '')) {
            $q->where('taken_at', '<=', $to.' 23:59:59');
        }
        if (array_key_exists($p['kind'] ?? '', HealthReading::KINDS)) {
            $q->where('kind', $p['kind']);
        }
        $flags = array_values(array_intersect(explode(',', (string) ($p['flags'] ?? '')), array_keys(HealthReading::FLAGS)));
        if ($flags) {
            $q->whereIn('flag', $flags);
        }
        $patient = null;
        if (preg_match('/^[0-9a-f-]{36}$/i', (string) ($p['patient'] ?? ''))) {
            $q->where('customer_id', $p['patient']);
            $patient = Customer::find($p['patient'])?->name;
        }
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'patient_name' => $patient, 'kinds' => self::choices(HealthReading::KINDS),
            'flags' => self::choices(HealthReading::FLAGS), 'can_service' => self::canAddService(),
            'rows' => $q->orderByDesc('taken_at')->orderByDesc('id')->forPage($page, $per)->get()->map(fn (HealthReading $r) => ['id' => $r->id,
                'taken_at' => $r->taken_at?->toIso8601String(), 'person' => $r->customer?->name ?? 'Walk-in', 'customer_id' => $r->customer_id,
                'kind' => HealthReading::KINDS[$r->kind] ?? $r->kind, 'reading' => $r->summary(), 'flag' => $r->flag,
                'flag_label' => HealthReading::FLAGS[$r->flag] ?? (string) $r->flag, 'tone' => self::FLAG_TONE[$r->flag] ?? 'subtle',
                'note' => $r->note, 'branch' => $r->branch?->code, 'by' => $r->taken_by_name])->all()];
    }

    /** What Record a reading and Add a fee service offer. */
    public function readingForm(): array
    {
        return ['branches' => self::choices(FinanceUi::branches()), 'branch' => (string) FinanceUi::hq(), 'kinds' => self::choices(HealthReading::KINDS),
            'results' => collect(HealthReading::RESULTS)->map(fn ($r) => self::choices($r))->all(), 'currency' => $this->currency(),
            'exponent' => Money::exponent($this->currency()),
            'tax_groups' => self::canAddService() ? self::choices(TaxGroup::orderBy('name')->pluck('name', 'id')->all()) : []];
    }

    public function patients(string $q): array
    {
        return Customer::where('name', 'ilike', "%{$q}%")->orderBy('name')->limit(30)->get(['id', 'name', 'phone'])
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'detail' => $c->phone])->all();
    }

    // -------------------------------------------------------------------------------------------- Scale items

    public static function canScale(): bool
    {
        return ScaleItems::canAccess();
    }

    public function scaleItems(): array
    {
        $page = new ScaleItems;

        return ['prefixes' => $page->prefixes(), 'currency' => $this->currency(), 'exponent' => Money::exponent($this->currency()),
            'rows' => collect($page->items())->map(fn ($i) => ['id' => (string) $i->plu.'-'.$i->sku, 'plu' => str_pad((string) $i->plu, 5, '0', STR_PAD_LEFT), 'sku' => $i->sku,
                'name' => $i->name, 'unit' => $i->unit, 'price' => $i->amount_minor === null ? null : (int) $i->amount_minor])->values()->all()];
    }

    public function scaleSheets(): array
    {
        $d = $this->scaleItems();

        return ['title' => 'Scale items', 'filters' => ["Label prefixes: {$d['prefixes']}"], 'sheets' => [['title' => 'Scale items',
            'headings' => ['Scale code (PLU)', 'SKU', 'Product', 'Unit', 'Retail price per unit'],
            'rows' => array_map(fn ($r) => [$r['plu'], (string) $r['sku'], $r['name'], (string) $r['unit'], $r['price'] === null ? 'No retail price' : FinanceUi::money($r['price'])], $d['rows'])]]];
    }
}

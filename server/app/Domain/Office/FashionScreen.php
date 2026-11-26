<?php

namespace App\Domain\Office;

use App\Domain\Fashion\AlterationService;
use App\Domain\Fashion\FashionReports;
use App\Filament\Pages\StyleReport;
use App\Filament\Resources\Alterations\AlterationResource;
use App\Filament\Resources\Styles\StyleResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Alteration;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Style;
use App\Models\TaxGroup;
use App\Models\User;
use App\Support\IndustryContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fashion in the new back office (part 36 of the rewrite), as the current pages: Styles (list, one style with its size ×
 * colour grid, the form), Alterations, and Sell-through & consignment. Same queries and rules: the StyleService,
 * AlterationService, FashionReports and ConsignmentService (FashionController).
 */
final class FashionScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const ALTERATION_TONE = ['ready' => 'success', 'collected' => 'subtle', 'cancelled' => 'subtle', 'in_progress' => 'warning', 'received' => 'informative'];

    public function __construct(private readonly User $user) {}

    private static function pairs(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function m(): array
    {
        $c = $this->user->tenant?->currency_code ?? 'GHS';

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    private function major(?int $minor): string
    {
        if ($minor === null) {
            return '';
        }
        $e = $this->m()['exponent'];

        return number_format($minor / (10 ** $e), $e, '.', '');
    }

    // -------------------------------------------------------------------------------------------- Styles

    public function stylesQuery(): Builder
    {
        return StyleResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, season, show, page, per */
    public function styles(array $p): array
    {
        $q = $this->stylesQuery()->withCount('variants');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('code', 'ilike', "%{$s}%")->orWhere('name', 'ilike', "%{$s}%"));
        }
        if (($season = (string) ($p['season'] ?? '')) !== '') {
            $q->where('season', $season);
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('archived_at'), 'both' => null, default => $q->whereNull('archived_at')
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $archive = new CompanyScreen($this->user);

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'can_create' => StyleResource::canCreate(),
            'seasons' => self::pairs(Style::whereNotNull('season')->distinct()->orderBy('season')->pluck('season', 'season')->all()),
            'rows' => $q->orderBy('code')->forPage($page, $per)->get()->map(fn (Style $s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'season' => $s->season,
                'collection' => $s->collection, 'products' => (int) $s->variants_count, 'price' => $s->price_minor === null ? null : (int) $s->price_minor,
                'for_sale' => (bool) $s->is_active] + $archive->archiveOf($s))->all()];
    }

    /** One style with its size × colour grid: on hand now (all warehouses) and sold in the last 30 days. */
    public function style(Style $s): array
    {
        $m = FashionReports::matrix($s, now()->subDays(30)->toDateString(), now()->toDateString());

        return $this->m() + ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'season' => $s->season, 'collection' => $s->collection,
            'price' => $s->price_minor === null ? null : (int) $s->price_minor, 'for_sale' => (bool) $s->is_active, 'description' => $s->description,
            'category' => Category::whereKey($s->category_id)->value('name'), 'brand' => Brand::whereKey($s->brand_id)->value('name'),
            'tax' => TaxGroup::whereKey($s->tax_group_id)->value('name'), 'sizes' => $m['sizes'], 'colours' => $m['colours'],
            'cells' => collect($m['cells'])->map(fn ($row) => collect($row)->map(fn ($c) => ['id' => $c['product_id'], 'sku' => $c['sku'], 'on_hand' => $c['on_hand'], 'sold' => $c['sold']])->all())->all(),
            'missing' => collect($m['colours'])->sum(fn ($c) => collect($m['sizes'])->filter(fn ($z) => ! isset($m['cells'][$c][$z]))->count()),
            'actions' => ['edit' => StyleResource::canCreate() && ! $s->archived_at, 'generate' => StyleResource::canCreate() && ! $s->archived_at,
                'price' => StyleResource::canCreate() && Erp::can('pricing.manage') && ! $s->archived_at]] + (new CompanyScreen($this->user))->archiveOf($s);
    }

    /** The style form: a new one, or a style's values to change (its code never changes). */
    public function styleForm(?Style $s): array
    {
        $industries = IndustryContext::choices($this->user);

        return ['id' => $s?->id, 'code' => $s?->code, 'categories' => self::pairs(Category::orderBy('name')->pluck('name', 'id')->all()),
            'brands' => self::pairs(Brand::orderBy('name')->pluck('name', 'id')->all()), 'taxes' => self::pairs(TaxGroup::orderBy('name')->pluck('name', 'id')->all()),
            'industries' => count(array_diff_key($industries, [IndustryContext::SHARED => true])) > 1 || isset($industries[IndustryContext::SHARED]) ? self::pairs($industries) : [],
            'values' => ['name' => (string) $s?->name, 'industry_id' => $s ? (string) ($s->industry_id ?? IndustryContext::SHARED) : (string) IndustryContext::defaultId(),
                'category_id' => (string) $s?->category_id, 'brand_id' => (string) $s?->brand_id,
                'tax_group_id' => (string) ($s?->tax_group_id ?? TaxGroup::where('code', 'STD')->value('id')), 'season' => (string) $s?->season,
                'collection' => (string) $s?->collection, 'price' => $this->major($s?->price_minor), 'sizes' => array_values($s?->sizes ?? []),
                'colours' => array_values($s?->colours ?? []), 'description' => (string) $s?->description, 'is_active' => (bool) ($s?->is_active ?? true)]];
    }

    // -------------------------------------------------------------------------------------------- Alterations

    public function alterationsQuery(): Builder
    {
        return AlterationResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status, page, per */
    public function alterations(array $p): array
    {
        $q = $this->alterationsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('customer_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")->orWhere('garment', 'ilike', "%{$s}%"));
        }
        if (isset(Alteration::STATUSES[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $ids = Erp::branchIds('fashion.alterations');

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::pairs(Alteration::STATUSES), 'can_create' => AlterationResource::canCreate(),
            'branches' => self::pairs(Branch::when($ids !== null, fn ($b) => $b->whereIn('id', $ids))->whereNull('archived_at')->orderBy('code')->pluck('name', 'id')->all()),
            'rows' => $q->orderByDesc('received_at')->forPage($page, $per)->get()->map(fn (Alteration $a) => ['id' => $a->id, 'number' => $a->number,
                'customer' => $a->customer_name, 'phone' => $a->phone, 'garment' => $a->garment, 'work' => $a->work, 'charge' => (int) $a->charge_minor,
                'due' => $a->due_on?->toDateString(), 'tailor' => $a->tailor_name, 'status' => $a->status, 'status_label' => Alteration::STATUSES[$a->status] ?? $a->status,
                'tone' => self::ALTERATION_TONE[$a->status] ?? 'subtle',
                'next' => self::pairs(collect(AlterationService::TRANSITIONS[$a->status] ?? [])->mapWithKeys(fn ($s) => [$s => Alteration::STATUSES[$s]])->all()),
                'can_hand_over' => $a->status === 'ready' && (int) $a->charge_minor === 0])->all()];
    }

    // -------------------------------------------------------------------------------------------- Sell-through & consignment

    /** @param  array<string, mixed>  $p  from, to, season */
    public function report(array $p): array
    {
        $page = new StyleReport;
        $page->from = is_string($p['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['from']) ? $p['from'] : '';
        $page->to = is_string($p['to'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['to']) ? $p['to'] : '';
        $page->season = (string) ($p['season'] ?? '');
        $page->mount();
        $consign = Erp::canAnywhere('fashion.consignment');

        return $this->m() + ['from' => $page->from, 'to' => $page->to, 'season' => $page->season, 'seasons' => self::pairs(array_combine($page->seasons(), $page->seasons()) ?: []),
            'styles' => array_map(fn ($r) => ['id' => $r->style_id, 'code' => $r->code, 'name' => $r->name, 'season' => $r->season, 'collection' => $r->collection,
                'sold' => $r->sold, 'on_hand' => $r->on_hand, 'sell_through' => number_format($r->sell_through_bp / 100, 1).' %', 'revenue' => (int) $r->revenue_minor], $page->styles()),
            'consignment' => $consign, 'can_settle' => $page->canSettle(),
            'owed' => $page->owed()->map(fn ($o) => ['consignor_id' => $o->consignor_id, 'name' => $o->name, 'branch_id' => $o->branch_id, 'branch' => $page->branchCode($o->branch_id),
                'qty' => Fields::qty($o->qty), 'sold' => (int) $o->sold_minor, 'owed' => (int) $o->owed_minor])->values()->all()];
    }

    public static function canReport(): bool
    {
        return StyleReport::canAccess();
    }
}

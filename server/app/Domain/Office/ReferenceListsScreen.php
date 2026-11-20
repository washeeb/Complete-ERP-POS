<?php

namespace App\Domain\Office;

use App\Domain\Org\ArchiveService;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\PriceLists\PriceListResource;
use App\Filament\Resources\TaxGroups\TaxGroupResource;
use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Filament\Resources\Uoms\UomResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Industry;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\TaxGroup;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Models\User;
use App\Support\IndustryContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue and pricing reference lists in the new back office (catalog and pricing setup, part 7 of the rewrite),
 * as the current screens: categories, brands, units of measure, tax rates, tax groups and price lists — each a list
 * with Add and Change in a dialog, and per kind Move products, Merge into, Delete when unused, Record accountant check,
 * and Archive and Restore, through CatalogService and ArchiveService (ReferenceListsController). Who may do what is
 * each current screen's own rule (its Resource).
 */
final class ReferenceListsScreen
{
    /** kind => [Resource, model, product field to move by (or null), deletable, archivable] */
    public const KINDS = [
        'categories' => [CategoryResource::class, Category::class, 'category_id', true, true],
        'brands' => [BrandResource::class, Brand::class, 'brand_id', true, false],
        'units' => [UomResource::class, Uom::class, null, true, false],
        'tax-rates' => [TaxRateResource::class, TaxRate::class, null, false, true],
        'tax-groups' => [TaxGroupResource::class, TaxGroup::class, null, false, false],
        'price-lists' => [PriceListResource::class, PriceList::class, null, false, true],
    ];

    /** The fields each kind's form saves (the rest of the record is never touched from the screen). */
    public const FIELDS = [
        'categories' => ['name', 'industry_id', 'parent_id'],
        'brands' => ['name', 'industry_id'],
        'units' => ['code', 'name', 'decimals'],
        'tax-rates' => ['code', 'name', 'rate_bp', 'sort'],
        'tax-groups' => ['name'],
        'price-lists' => ['name', 'prices_include_tax', 'is_default', 'pos_selectable', 'industry_id', 'branch_id'],
    ];

    public function __construct(private readonly User $user, private readonly string $kind) {}

    public function resource(): string
    {
        return self::KINDS[$this->kind][0];
    }

    public function canView(): bool
    {
        return $this->resource()::canViewAny();
    }

    public function canCreate(): bool
    {
        return $this->resource()::canCreate();
    }

    public function canEdit(Model $r): bool
    {
        return $this->resource()::canEdit($r);
    }

    /** The manage permission of this kind (catalogue or pricing), for Delete and Move products, as now. */
    public function managePermission(): string
    {
        return in_array($this->kind, ['tax-rates', 'tax-groups', 'price-lists'], true) ? 'pricing.manage' : 'catalog.manage';
    }

    public function query(): Builder
    {
        $q = $this->resource()::getEloquentQuery();
        if ($this->kind === 'price-lists' && ($id = IndustryContext::selected())) {
            $q->where(fn ($w) => $w->whereNull('industry_id')->orWhere('industry_id', $id));
        }

        return $q;
    }

    public function find(string $id): ?Model
    {
        return $this->query()->find($id);
    }

    /** @param  array<string, mixed>  $p  q, show (in use | archived | both) */
    public function list(array $p): array
    {
        $q = $this->query();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->when(in_array($this->kind, ['units', 'tax-rates', 'tax-groups', 'price-lists'], true),
                fn ($x) => $x->orWhere('code', 'ilike', "%{$search}%")));
        }
        if (self::KINDS[$this->kind][4]) {
            match ($p['show'] ?? '') {
                'archived' => $q->whereNotNull('archived_at'),
                'both' => null,
                default => $q->whereNull('archived_at'),
            };
        }
        $q = match ($this->kind) {
            'units' => $q->orderBy('code'),
            'tax-rates' => $q->orderBy('sort')->orderBy('code'),
            'tax-groups' => $q->with('rates')->orderBy('code'),
            'price-lists' => $q->with('industry', 'branch')->withCount('prices')->orderBy('code'),
            default => $q->orderBy('name'),
        };
        $rows = $q->get();
        $counts = $this->productCounts($rows->modelKeys());

        return ['kind' => $this->kind, 'can_create' => $this->canCreate(), 'archivable' => self::KINDS[$this->kind][4],
            'rows' => $rows->map(fn (Model $r) => $this->rowOf($r, $counts[$r->getKey()] ?? 0))->all()];
    }

    /** @return array<string, int> id => products using it */
    private function productCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $field = self::KINDS[$this->kind][2];

        return match (true) {
            $field !== null => Product::whereIn($field, $ids)->groupBy($field)->select($field, DB::raw('count(*) as n'))->pluck('n', $field)->map(fn ($n) => (int) $n)->all(),
            $this->kind === 'units' => ProductUom::whereIn('uom_id', $ids)->groupBy('uom_id')->select('uom_id', DB::raw('count(distinct product_id) as n'))->pluck('n', 'uom_id')->map(fn ($n) => (int) $n)->all(),
            $this->kind === 'tax-groups' => Product::whereIn('tax_group_id', $ids)->groupBy('tax_group_id')->select('tax_group_id', DB::raw('count(*) as n'))->pluck('n', 'tax_group_id')->map(fn ($n) => (int) $n)->all(),
            default => [],
        };
    }

    public function rowOf(Model $r, int $products): array
    {
        $archive = app(ArchiveService::class);
        $archivable = self::KINDS[$this->kind][4];
        $allowed = $archivable && $archive->allowed($this->user, $r);
        $manage = Erp::can($this->managePermission());
        $base = ['id' => $r->getKey(), 'name' => $r->name, 'products' => $products, 'archived' => $archivable && $r->archived_at !== null, 'can_edit' => $this->canEdit($r),
            'can_move' => self::KINDS[$this->kind][2] !== null && $manage && $products > 0, 'can_delete' => self::KINDS[$this->kind][3] && $manage,
            'can_archive' => $allowed && ! $r->archived_at, 'archive_blockers' => $allowed && ! $r->archived_at ? $archive->blockers($r) : [], 'can_restore' => $allowed && (bool) $r->archived_at,
            'values' => $this->values($r)];

        return $base + match ($this->kind) {
            'categories' => ['parent' => $r->parent?->name, 'industry' => $r->industry_id ? Industry::find($r->industry_id)?->name : 'Shared'],
            'brands' => ['industry' => $r->industry_id ? Industry::find($r->industry_id)?->name : 'Shared'],
            'units' => ['code' => $r->code, 'decimals' => (int) $r->decimals, 'merged_into' => $r->merged_into_id ? Uom::find($r->merged_into_id)?->code : null,
                'can_merge' => Erp::canAnywhere('catalog.manage') && ! $r->merged_into_id],
            'tax-rates' => ['code' => $r->code, 'rate' => number_format($r->rate_bp / 100, 2).'%'],
            'tax-groups' => ['code' => $r->code, 'taxes' => $r->rates->map(fn ($x) => $x->code.' '.number_format($x->rate_bp / 100, 2).'%')->implode(' + ') ?: 'none',
                'verified' => $r->verified_at ? "Verified by {$r->verified_by_name}, {$r->verified_at->format('j M Y')}" : null, 'can_verify' => ! $r->verified_at && Erp::can('pricing.manage')],
            'price-lists' => ['code' => $r->code, 'currency' => $r->currency_code, 'incl_tax' => (bool) $r->prices_include_tax, 'default' => (bool) $r->is_default,
                'at_till' => (bool) $r->pos_selectable, 'industry' => $r->industry?->name ?? 'Shared', 'branch' => $r->branch?->code ?? 'All', 'prices' => (int) $r->prices_count],
            default => [],
        };
    }

    /** The record's form values, as the form shows them. */
    private function values(Model $r): array
    {
        $v = array_intersect_key($r->only(self::FIELDS[$this->kind]), array_flip(self::FIELDS[$this->kind]));
        if (array_key_exists('industry_id', $v) && $this->kind !== 'price-lists') {
            $v['industry_id'] = $r->industry_id ?? IndustryContext::SHARED;
        }
        if ($this->kind === 'tax-groups') {
            $v['rate_ids'] = $r->rates->pluck('id')->all();
        }

        return $v;
    }

    /** What the forms choose from. */
    public function options(): array
    {
        $pairs = fn (iterable $a) => collect($a)->map(fn ($v, $k) => ['id' => (string) $k, 'label' => (string) $v])->values()->all();

        return match ($this->kind) {
            'categories' => ['industries' => $pairs(IndustryContext::choices($this->user)), 'parents' => $pairs(Category::orderBy('name')->pluck('name', 'id')), 'default_industry' => IndustryContext::defaultId()],
            'brands' => ['industries' => $pairs(IndustryContext::choices($this->user)), 'default_industry' => IndustryContext::defaultId()],
            'units' => ['decimals' => $pairs([0 => '0 — whole units only', 1 => '1', 2 => '2', 3 => '3 — e.g. 1.250 kg']),
                'merge_into' => $pairs(Uom::whereNull('merged_into_id')->orderBy('code')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->code} · {$u->name}"]))],
            'tax-groups' => ['rates' => $pairs(TaxRate::orderBy('sort')->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->name} (".number_format($r->rate_bp / 100, 2).'%)']))],
            'price-lists' => ['industries' => Industry::exists() ? $pairs(Industry::licensed()->orderBy('sort')->pluck('name', 'id')) : [], 'branches' => $pairs(Branch::choices(null, 'both')),
                'default_industry' => IndustryContext::selected()],
            default => [],
        } + ['move_to' => self::KINDS[$this->kind][2] ? $pairs(self::KINDS[$this->kind][1]::orderBy('name')->pluck('name', 'id')) : []];
    }
}

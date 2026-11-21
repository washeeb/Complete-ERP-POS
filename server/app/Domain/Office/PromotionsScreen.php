<?php

namespace App\Domain\Office;

use App\Domain\Org\ArchiveService;
use App\Domain\Pricing\PromotionEngine;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Support\IndustryContext;

/**
 * Promotions and coupons in the new back office (catalog and pricing setup, part 9 of the rewrite), as the current
 * screen: the list (kind, offer, when, running, in use or archived), New promotion and Change with the same fields
 * (what it applies to, when and where), Archive and Restore, through PromotionService (PromotionsController). The till
 * and the server run the same engine; who may do what is unchanged.
 */
final class PromotionsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return PromotionResource::canViewAny();
    }

    private static function pairs(array $a): array
    {
        return array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);
    }

    /** @param  array<string, mixed>  $p  q, type, running (yes | no | both), show (in use | archived | both), page, per */
    public function list(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = PromotionResource::getEloquentQuery();
        if (($search = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('coupon_code', 'ilike', "%{$search}%"));
        }
        if (array_key_exists((string) ($p['type'] ?? ''), PromotionEngine::TYPES)) {
            $q->where('type', $p['type']);
        }
        match ($p['running'] ?? '') {
            'no' => $q->where('is_active', false), 'both' => null, default => $q->where('is_active', true),
        };
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('promotions.archived_at'), 'both' => null, default => $q->whereNull('promotions.archived_at'),
        };
        $archive = app(ArchiveService::class);
        $canEdit = PromotionResource::canCreate();

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'can_create' => $canEdit,
            'rows' => $q->orderBy('name')->forPage($page, $per)->get()->map(function (Promotion $r) use ($archive, $canEdit) {
                $allowed = $archive->allowed($this->user, $r);

                return ['id' => $r->id, 'name' => $r->name, 'coupon' => $r->coupon_code, 'type' => PromotionEngine::TYPES[$r->type] ?? $r->type,
                    'offer' => PromotionResource::describe($r), 'when' => $this->when($r), 'running' => (bool) $r->is_active, 'archived' => $r->archived_at !== null,
                    'can_edit' => $canEdit, 'can_archive' => $allowed && ! $r->archived_at, 'can_restore' => $allowed && (bool) $r->archived_at];
            })->all()];
    }

    private function when(Promotion $r): string
    {
        return trim(implode(' ', array_filter([
            $r->starts_at ? 'from '.$r->starts_at->format('j M') : null, $r->ends_at ? 'until '.$r->ends_at->format('j M') : null,
            $r->time_from ? substr($r->time_from, 0, 5).'–'.substr((string) $r->time_to, 0, 5) : null]))) ?: 'always';
    }

    public function options(): array
    {
        $industries = IndustryContext::choices($this->user);

        return ['types' => self::pairs(PromotionEngine::TYPES), 'currency' => CustomerResource::currency(),
            'industries' => count(array_diff_key($industries, [IndustryContext::SHARED => true])) > 1 || isset($industries[IndustryContext::SHARED]) ? self::pairs($industries) : [],
            'default_industry' => IndustryContext::defaultId(),
            'categories' => self::pairs(Category::orderBy('name')->pluck('name', 'id')->all()),
            'branches' => self::pairs(Branch::choices()), 'price_lists' => self::pairs(PriceList::orderBy('name')->pluck('name', 'id')->all())];
    }

    /** Products to pick, by name or code. */
    public function products(string $q): array
    {
        $q = trim($q);

        return $q === '' ? [] : Product::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(30)->get()->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->sku} · {$p->name}"])->all();
    }

    /** One promotion as the form shows it (percentages and money as people write them, as the current screen). */
    public function values(Promotion $p): array
    {
        $f = PromotionResource::toForm($p);
        $products = Product::whereIn('id', $p->product_ids ?? [])->get()->map(fn ($x) => ['id' => $x->id, 'label' => "{$x->sku} · {$x->name}"])->all();
        $keep = array_values($p->branch_ids ?? []);
        $str = fn ($v) => $v === null ? '' : (string) $v;

        return ['name' => $p->name, 'industry_id' => (string) ($p->industry_id ?? IndustryContext::SHARED), 'is_active' => (bool) $p->is_active, 'type' => $p->type,
            'coupon_code' => $str($p->coupon_code), 'percent' => $str($f['percent']), 'amount' => $f['amount'] !== null ? str_replace(',', '', $f['amount']) : '',
            'min_spend' => $f['min_spend'] !== null ? str_replace(',', '', $f['min_spend']) : '',
            'buy_qty' => $p->buy_qty ? (string) $p->buy_qty : '', 'get_qty' => $p->get_qty ? (string) $p->get_qty : '', 'bundle_qty' => $p->bundle_qty ? (string) $p->bundle_qty : '',
            'product_ids' => array_values($p->product_ids ?? []), 'products' => $products, 'category_ids' => array_values($p->category_ids ?? []),
            'starts_at' => $p->starts_at?->format('Y-m-d\TH:i') ?? '', 'ends_at' => $p->ends_at?->format('Y-m-d\TH:i') ?? '',
            'days' => array_values(array_map('strval', $p->days ?? [])), 'time_from' => $p->time_from ? substr($p->time_from, 0, 5) : '', 'time_to' => $p->time_to ? substr($p->time_to, 0, 5) : '',
            'branch_ids' => $keep, 'archived_branches' => self::pairs(array_diff_key(Branch::choices($keep), Branch::choices())),
            'price_list_ids' => array_values($p->price_list_ids ?? [])];
    }
}

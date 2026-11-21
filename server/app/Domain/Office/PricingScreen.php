<?php

namespace App\Domain\Office;

use App\Domain\Org\ArchiveService;
use App\Filament\Pages\PricesToReview;
use App\Filament\Resources\PricingRules\PricingRuleResource;
use App\Filament\Support\FinanceUi;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\PriceSuggestion;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pricing rules and Prices to review in the new back office (catalog and pricing setup, part 8 of the rewrite), as the
 * current screens: the rules that work out selling prices from the latest cost (whole company, a category or one
 * product, on a price list), New rule and Change, Apply the rules to all products now, Archive and Restore; and the
 * prices the rules worked out that wait for a manager, Approve or Keep current (one or many), through PricingRules
 * (PricingController). Who may do what is unchanged.
 */
final class PricingScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canRules(): bool
    {
        return PricingRuleResource::canViewAny();
    }

    public static function canReview(): bool
    {
        return PricesToReview::canAccess();
    }

    private static function pairs(array $a): array
    {
        return array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);
    }

    /** @param  array<string, mixed>  $p  show (in use | archived | both) */
    public function rules(array $p): array
    {
        $q = PricingRuleResource::getEloquentQuery()->with('category', 'product', 'priceList');
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('pricing_rules.archived_at'),
            'both' => null,
            default => $q->whereNull('pricing_rules.archived_at'),
        };
        $archive = app(ArchiveService::class);

        return ['can_create' => PricingRuleResource::canCreate(),
            'rows' => $q->orderBy('scope')->orderBy('created_at')->get()->map(function (PricingRule $r) use ($archive) {
                $allowed = $archive->allowed($this->user, $r);

                return ['id' => $r->id, 'applies_to' => match ($r->scope) {
                    'category' => 'Category: '.$r->category?->name, 'product' => 'Product: '.$r->product?->name, default => 'Whole company'
                },
                    'price_list' => $r->priceList?->name, 'rule' => $r->describe(), 'gives' => PricingRule::TAX_MODES[$r->tax_mode] ?? $r->tax_mode,
                    'rounding' => (PricingRule::ROUNDING[$r->round_minor] ?? $r->round_minor).($r->round_mode === 'up' ? ', up' : ''),
                    'on_change' => PricingRule::ON_CHANGE[$r->on_change] ?? $r->on_change, 'active' => (bool) $r->is_active, 'archived' => $r->archived_at !== null,
                    'can_edit' => PricingRuleResource::canEdit($r), 'can_archive' => $allowed && ! $r->archived_at, 'can_restore' => $allowed && (bool) $r->archived_at,
                    'values' => ['scope' => $r->scope, 'category_id' => $r->category_id, 'product_id' => $r->product_id, 'product' => $r->product?->name, 'price_list_id' => $r->price_list_id,
                        'method' => $r->method, 'value' => match ($r->method) {
                            'multiply' => (string) (($r->value_bp ?? 0) / 10000), 'markup', 'margin' => (string) (($r->value_bp ?? 0) / 100), default => ''
                        },
                        'amount' => $r->amount_minor !== null ? (string) ($r->amount_minor / 100) : '', 'tax_mode' => $r->tax_mode, 'round_minor' => (string) $r->round_minor,
                        'round_mode' => $r->round_mode, 'on_change' => $r->on_change, 'is_active' => (bool) $r->is_active]];
            })->all()];
    }

    public function options(): array
    {
        $currency = $this->user->tenant?->currency_code ?? 'GHS';

        return ['scopes' => self::pairs(PricingRule::SCOPES), 'methods' => self::pairs(PricingRule::METHODS), 'tax_modes' => self::pairs(PricingRule::TAX_MODES),
            'rounding' => self::pairs(PricingRule::ROUNDING), 'on_change' => self::pairs(PricingRule::ON_CHANGE), 'currency' => $currency,
            'price_lists' => self::pairs(PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all()),
            'default_list' => PriceList::where('is_default', true)->value('id'),
            'categories' => self::pairs(Category::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())];
    }

    /** Products for the "One product" rule, by name or code. */
    public function products(string $q): array
    {
        $q = trim($q);

        return $q === '' ? [] : self::pairs(Product::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(30)->pluck('name', 'id')->all());
    }

    /** Waiting suggestions for products of the industries this person prices (all for company-wide ones), as now. */
    public function suggestionsQuery(): Builder
    {
        $ids = app(Authorizer::class)->industryIds($this->user, 'pricing.manage');

        return PriceSuggestion::query()->where('status', 'pending')->when($ids !== null, fn ($q) => $q->whereHas('product', fn ($p) => $p->whereIn('industry_id', $ids)));
    }

    /** @param  array<string, mixed>  $p  q, page, per */
    public function suggestions(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = $this->suggestionsQuery()->with(['product', 'productUom.uom', 'priceList']);
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->whereHas('product', fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"));
        }
        $currency = FinanceUi::currency();

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'currency' => $currency, 'exponent' => Money::exponent($currency),
            'rows' => $q->orderByDesc('updated_at')->forPage($page, $per)->get()->map(fn (PriceSuggestion $s) => [
                'id' => $s->id, 'product' => $s->product?->name, 'sku' => $s->product?->sku, 'unit' => $s->productUom?->uom?->code, 'price_list' => $s->priceList?->name,
                'cost' => (int) $s->cost_minor, 'now' => $s->current_minor === null ? null : (int) $s->current_minor, 'new' => (int) $s->suggested_minor,
                'change' => $s->current_minor ? sprintf('%+.1f %%', ($s->suggested_minor - $s->current_minor) * 100 / max(1, $s->current_minor)) : 'new',
                'why' => $s->reason, 'since' => $s->updated_at?->toIso8601String(),
            ])->all()];
    }
}

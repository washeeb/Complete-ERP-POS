<?php

namespace App\Domain\Pricing;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Category;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\PriceSuggestion;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductUom;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;

/**
 * Automatic selling prices. A rule — for the whole company, a category (and those under it) or one product, on a
 * price list — turns the latest cost of a product into its price:
 *   cost = the most recent posted delivery's cost per base unit, with the freight, duty and clearing spread onto that
 *          delivery line (landed costs); before any delivery, the average cost of the stock;
 *   price = cost × number | cost + markup % | cost ÷ (1 − margin %) | cost + amount, per sellable unit (× its factor),
 *           as the shelf price with tax inside (default) or before tax with taxes added, rounded (default 10 pesewas).
 * The most specific rule wins: product, then the nearest category up the tree, then the company. "Manual" keeps a
 * product or category out of automatic pricing. Only the standard price (from 1, no dates) of each sellable unit is
 * set; quantity breaks and dated offers stay as typed.
 * When the cost changes (a delivery posted, freight spread), each affected price follows the rule's choice: rises at
 * once and drops for a manager's approval (default), always at once, or always for approval ("Prices to review").
 * Prices are set by the head office (or a standalone server), as the rest of the price lists.
 */
final class PricingRules
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly Notifier $notifier) {}

    // --- rules ---------------------------------------------------------------------------------------------------------

    public function saveRule(Actor $actor, PricingRule $rule, array $d): PricingRule
    {
        $this->requirePricing($actor);
        $scope = (string) ($d['scope'] ?? 'company');
        $method = (string) ($d['method'] ?? 'multiply');
        if (! isset(PricingRule::SCOPES[$scope]) || ! isset(PricingRule::METHODS[$method])) {
            throw new DomainException('Choose what the rule applies to and how the price is worked out.');
        }
        $list = PriceList::find($d['price_list_id'] ?? null) ?? throw new DomainException('Choose the price list the rule sets.');
        $value = isset($d['value']) && $d['value'] !== '' ? (float) $d['value'] : null;
        $valueBp = match ($method) {
            'multiply' => $value !== null && $value >= 1 && $value <= 100 ? (int) round($value * 10000) : throw new DomainException('Multiply the cost by a number of at least 1, e.g. 1.40.'),
            'markup' => $value !== null && $value >= 0 && $value <= 1000 ? (int) round($value * 100) : throw new DomainException('The markup is a percentage of the cost, e.g. 40.'),
            'margin' => $value !== null && $value >= 0 && $value < 95 ? (int) round($value * 100) : throw new DomainException('The margin is a percentage of the selling price, below 95, e.g. 25.'),
            default => null,
        };
        $amount = $method === 'plus' ? (int) round(((float) ($d['amount'] ?? 0)) * 100) : null;
        if ($method === 'plus' && $amount <= 0) {
            throw new DomainException('Add an amount above zero to the cost.');
        }
        $rule->forceFill([
            'tenant_id' => $actor->tenantId, 'scope' => $scope, 'price_list_id' => $list->id,
            'category_id' => $scope === 'category' ? (Category::find($d['category_id'] ?? null)?->id ?? throw new DomainException('Choose the category.')) : null,
            'product_id' => $scope === 'product' ? (Product::find($d['product_id'] ?? null)?->id ?? throw new DomainException('Choose the product.')) : null,
            'method' => $method, 'value_bp' => $valueBp, 'amount_minor' => $amount,
            'tax_mode' => isset(PricingRule::TAX_MODES[$d['tax_mode'] ?? '']) ? $d['tax_mode'] : 'inclusive',
            'round_minor' => isset(PricingRule::ROUNDING[(int) ($d['round_minor'] ?? 10)]) ? (int) ($d['round_minor'] ?? 10) : 10,
            'round_mode' => ($d['round_mode'] ?? 'nearest') === 'up' ? 'up' : 'nearest',
            'on_change' => isset(PricingRule::ON_CHANGE[$d['on_change'] ?? '']) ? $d['on_change'] : 'rise_auto',
            'is_active' => (bool) ($d['is_active'] ?? true), 'updated_by_name' => $actor->name,
        ]);
        $twin = PricingRule::where('scope', $scope)->where('price_list_id', $list->id)->where('is_active', true)
            ->where('category_id', $rule->category_id)->where('product_id', $rule->product_id)->when($rule->exists, fn ($q) => $q->whereKeyNot($rule->id))->exists();
        if ($twin && $rule->is_active) {
            throw new DomainException('There is already a rule for this on this price list. Change that one instead.');
        }

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $rule, 'pricing_rule.saved'));
    }

    /** The rule that sets this product's price on the list: product, then the nearest category, then the company. */
    public function ruleFor(Product $product, PriceList $list): ?PricingRule
    {
        $rules = PricingRule::where('price_list_id', $list->id)->where('is_active', true)->get();
        if ($r = $rules->first(fn ($r) => $r->scope === 'product' && $r->product_id === $product->id)) {
            return $r;
        }
        for ($cat = $product->category_id ? Category::find($product->category_id) : null, $depth = 0; $cat && $depth < 20; $cat = $cat->parent_id ? Category::find($cat->parent_id) : null, $depth++) {
            if ($r = $rules->first(fn ($r) => $r->scope === 'category' && $r->category_id === $cat->id)) {
                return $r;
            }
        }

        return $rules->first(fn ($r) => $r->scope === 'company');
    }

    // --- cost and price ------------------------------------------------------------------------------------------------

    /** @return array{cost: float, source: string}|null the cost of one base unit, in minor units */
    public function latestCost(Product $product): ?array
    {
        $line = DB::table('goods_receipt_lines as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->where('g.tenant_id', $product->tenant_id)->where('g.status', 'posted')->where('l.product_id', $product->id)
            ->orderByDesc('g.posted_at')->orderByDesc('g.id')->orderByDesc('l.line_no')->first(['l.id', 'l.net_minor', 'l.qty', 'l.factor', 'g.number']);
        if ($line && (float) $line->qty > 0) {
            $landed = (int) DB::table('landed_cost_lines')->where('goods_receipt_line_id', $line->id)->sum('allocated_minor');
            $base = (float) $line->qty * (float) ($line->factor ?: 1);

            return ['cost' => ($line->net_minor + $landed) / $base, 'source' => $line->number.($landed ? ' with freight' : '')];
        }
        $avg = DB::table('stock_levels')->where('tenant_id', $product->tenant_id)->where('product_id', $product->id)->where('on_hand', '>', 0)
            ->selectRaw('SUM(on_hand * avg_cost_minor) / NULLIF(SUM(on_hand), 0) AS c')->value('c');

        return $avg ? ['cost' => (float) $avg, 'source' => 'average cost of the stock'] : null;
    }

    /** The price the rule gives one unit (factor = base units in it), as stored on the list (its own tax treatment). */
    public function priceFor(PricingRule $rule, Product $product, float $unitCost, PriceList $list): ?int
    {
        $v = ($rule->value_bp ?? 0) / 10000;
        $result = match ($rule->method) {
            'multiply' => $unitCost * $v,
            'markup' => $unitCost * (1 + $v),
            'margin' => $v < 1 ? $unitCost / (1 - $v) : null,
            'plus' => $unitCost + ($rule->amount_minor ?? 0),
            default => null,
        };
        if ($result === null) {
            return null;
        }
        $taxBp = (int) ($product->taxGroup?->rates->where('is_active', true)->sum('rate_bp') ?? 0);
        $resultIsGross = $rule->tax_mode === 'inclusive';
        $stored = match (true) {
            $resultIsGross && ! $list->prices_include_tax => $result * 10000 / (10000 + $taxBp),
            ! $resultIsGross && $list->prices_include_tax => $result * (10000 + $taxBp) / 10000,
            default => $result,
        };
        $step = max(1, (int) $rule->round_minor);

        return (int) ($rule->round_mode === 'up' ? ceil($stored / $step - 1e-9) * $step : round($stored / $step) * $step);
    }

    // --- following cost changes ----------------------------------------------------------------------------------------

    /**
     * Works out each product's prices on every list with a rule and applies or queues the changes.
     *
     * @param  iterable<Product|string>  $products
     * @return array{applied: int, suggested: int}
     */
    public function follow(Actor $actor, iterable $products, string $why): array
    {
        if (config('erp.node.role') === 'branch') {
            return ['applied' => 0, 'suggested' => 0]; // prices are set at head office and reach branches by sync
        }
        $lists = PriceList::where('is_active', true)->whereIn('id', PricingRule::where('is_active', true)->select('price_list_id'))->get();
        $done = ['applied' => 0, 'suggested' => 0];
        $fresh = 0; // suggestions new or changed: only those are worth a message
        if ($lists->isEmpty()) {
            return $done;
        }
        foreach ($products as $p) {
            $product = $p instanceof Product ? $p : Product::find($p);
            if (! $product || ! $product->is_active) {
                continue;
            }
            $cost = $this->latestCost($product);
            if (! $cost || $cost['cost'] <= 0) {
                continue;
            }
            foreach ($lists as $list) {
                $rule = $this->ruleFor($product, $list);
                if (! $rule || $rule->method === 'manual') {
                    continue;
                }
                foreach (ProductUom::where('product_id', $product->id)->where('is_sellable', true)->get() as $unit) {
                    $unitCost = $cost['cost'] * (float) $unit->factor;
                    $target = $this->priceFor($rule, $product, $unitCost, $list);
                    if ($target === null || $target <= 0) {
                        continue;
                    }
                    $price = Price::where('price_list_id', $list->id)->where('product_uom_id', $unit->id)->where('min_qty', 1)->whereNull('starts_at')->whereNull('ends_at')->first();
                    $current = $price?->amount_minor;
                    if ($current === $target) {
                        PriceSuggestion::where('product_uom_id', $unit->id)->where('price_list_id', $list->id)->where('status', 'pending')
                            ->update(['status' => 'dismissed', 'decided_by_name' => 'Already at this price', 'decided_at' => now()]);

                        continue;
                    }
                    $rise = $current === null || $target > $current;
                    $auto = $rule->on_change === 'auto' || ($rule->on_change === 'rise_auto' && $rise);
                    $reason = ($rise ? 'Cost went up' : 'Cost went down').' ('.$cost['source'].'); '.$rule->describe();
                    if ($auto) {
                        $this->setPrice($actor, $product, $unit, $list, $price, $target, $reason.' · '.$why);
                        $done['applied']++;
                    } else {
                        $fresh += $this->suggest($product, $unit, $list, $rule, (int) round($unitCost), $current, $target, $reason) ? 1 : 0;
                        $done['suggested']++;
                    }
                }
            }
        }
        if ($fresh > 0) {
            $this->notifier->toPermission($actor->tenantId, 'pricing.manage', null, "{$fresh} price(s) to review",
                'The pricing rules worked out new prices that wait for your approval.', url('/admin/prices-to-review'), topic: 'pricing');
        }

        return $done;
    }

    /** Every product with a rule, now (e.g. after a rule is set up or changed). */
    public function followAll(Actor $actor): array
    {
        $this->requirePricing($actor);
        $ids = Product::where('is_active', true)->pluck('id');
        $total = ['applied' => 0, 'suggested' => 0];
        foreach ($ids->chunk(200) as $chunk) {
            $r = $this->recorder->transaction(fn () => $this->follow($actor, $chunk->all(), 'pricing rules applied by '.$actor->name));
            $total['applied'] += $r['applied'];
            $total['suggested'] += $r['suggested'];
        }

        return $total;
    }

    /** A manager accepts suggested prices. */
    public function approve(Actor $actor, iterable $suggestions): int
    {
        $this->requirePricing($actor);

        return $this->recorder->transaction(function () use ($actor, $suggestions) {
            $n = 0;
            foreach ($suggestions as $s) {
                $s = $s instanceof PriceSuggestion ? $s : PriceSuggestion::find($s);
                if (! $s || $s->status !== 'pending') {
                    continue;
                }
                $price = Price::where('price_list_id', $s->price_list_id)->where('product_uom_id', $s->product_uom_id)->where('min_qty', 1)->whereNull('starts_at')->whereNull('ends_at')->first();
                $this->setPrice($actor, $s->product, $s->productUom, $s->priceList, $price, $s->suggested_minor, $s->reason.' · approved by '.$actor->name);
                $s->forceFill(['status' => 'applied', 'decided_by_name' => $actor->name, 'decided_at' => now()])->save();
                $n++;
            }

            return $n;
        });
    }

    public function dismiss(Actor $actor, iterable $suggestions): int
    {
        $this->requirePricing($actor);
        $n = 0;
        foreach ($suggestions as $s) {
            $s = $s instanceof PriceSuggestion ? $s : PriceSuggestion::find($s);
            if ($s && $s->status === 'pending') {
                $s->forceFill(['status' => 'dismissed', 'decided_by_name' => $actor->name, 'decided_at' => now()])->save();
                $this->recorder->audit->record($actor, 'price_suggestion.dismissed', $s, ['suggested' => [$s->suggested_minor, null]]);
                $n++;
            }
        }

        return $n;
    }

    private function setPrice(Actor $actor, Product $product, ProductUom $unit, PriceList $list, ?Price $price, int $amount, string $reason): void
    {
        $price ??= new Price;
        $price->fill(['price_list_id' => $list->id, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'min_qty' => '1', 'amount_minor' => $amount, 'starts_at' => null, 'ends_at' => null]);
        $price->tenant_id = $product->tenant_id;
        $price->updated_by = $actor->id;
        $this->recorder->save($actor, $price, 'price.set_by_rule', mb_substr($reason, 0, 250));
        PriceSuggestion::where('product_uom_id', $unit->id)->where('price_list_id', $list->id)->where('status', 'pending')
            ->update(['status' => 'applied', 'decided_by_name' => 'Set by the rule', 'decided_at' => now()]);
    }

    /** One waiting suggestion per unit and list; true when it is new or its price changed. */
    private function suggest(Product $product, ProductUom $unit, PriceList $list, PricingRule $rule, int $cost, ?int $current, int $target, string $reason): bool
    {
        $s = PriceSuggestion::where('product_uom_id', $unit->id)->where('price_list_id', $list->id)->where('status', 'pending')->first() ?? new PriceSuggestion;
        if ($s->exists && $s->suggested_minor === $target && $s->current_minor === $current) {
            return false;
        }
        $s->forceFill(['tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'price_list_id' => $list->id,
            'pricing_rule_id' => $rule->id, 'cost_minor' => $cost, 'current_minor' => $current, 'suggested_minor' => $target, 'reason' => mb_substr($reason, 0, 120)])->save();

        return true;
    }

    private function requirePricing(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'pricing.manage')) {
            throw new AccessDenied('Pricing rules need the pricing permission (company-wide).');
        }
    }
}

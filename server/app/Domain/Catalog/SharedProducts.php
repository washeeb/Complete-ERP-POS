<?php

namespace App\Domain\Catalog;

use App\Domain\Support\Actor;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Tidying products that sit in no industry: every industry's tills sell them. The suggestion comes from where a product
 * really has stock or has sold. Nothing moves unless a person says so (owner decision 2026-11-07).
 */
final class SharedProducts
{
    private const SALES_MONTHS = 12;

    public function __construct(private readonly CatalogService $catalog) {}

    /** @return array<string, list<string>> product id => the industries it has stock or recent sales in */
    public function whereUsed(string $tenantId): array
    {
        $stock = DB::table('stock_levels as l')->join('products as p', 'p.id', '=', 'l.product_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->where('p.tenant_id', $tenantId)->whereNull('p.industry_id')->whereNotNull('b.industry_id')->where('l.on_hand', '>', 0)
            ->select('p.id', 'b.industry_id')->distinct();
        $sales = DB::table('sale_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('p.tenant_id', $tenantId)->whereNull('p.industry_id')->whereNotNull('b.industry_id')->where('s.occurred_at', '>=', now()->subMonths(self::SALES_MONTHS))
            ->select('p.id', 'b.industry_id')->distinct();

        $out = [];
        foreach ($stock->union($sales)->get() as $row) {
            $out[$row->id][] = $row->industry_id;
        }

        return $out;
    }

    /** The one industry to suggest; none when the product is used in several, or nowhere yet. */
    public static function suggestion(array $industries): ?string
    {
        return count($industries) === 1 ? $industries[0] : null;
    }

    /** @return int how many moved */
    public function moveAll(Actor $actor, string $industryId): int
    {
        return $this->catalog->moveToIndustry($actor, Product::whereNull('industry_id')->get(), $industryId);
    }

    /**
     * @param  iterable<Product>  $products
     * @return int how many moved: products with no suggestion stay where they are
     */
    public function moveSuggested(Actor $actor, iterable $products): int
    {
        $used = $this->whereUsed($actor->tenantId);
        $moved = 0;
        foreach ($products as $product) {
            if ($to = self::suggestion($used[$product->id] ?? [])) {
                $moved += $this->catalog->moveToIndustry($actor, [$product], $to);
            }
        }

        return $moved;
    }
}

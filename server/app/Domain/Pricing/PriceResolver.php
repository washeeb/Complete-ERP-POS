<?php

namespace App\Domain\Pricing;

use App\Models\Price;
use App\Models\PriceList;
use App\Support\Qty;
use Carbon\CarbonInterface;

/**
 * The price for a product unit on a list at a moment, for a quantity:
 * among rows active at that time with min_qty ≤ qty, the highest min_qty wins (quantity tiers);
 * among equal tiers, a dated (scheduled) price beats an open-ended one.
 * No fallback to other lists: a missing price means "not for sale on this list".
 */
final class PriceResolver
{
    public function resolve(PriceList $list, string $productId, string $productUomId, int $qtyMilli, CarbonInterface $at): ?Price
    {
        return Price::where('price_list_id', $list->id)
            ->where('product_id', $productId)
            ->where('product_uom_id', $productUomId)
            ->where('min_qty', '<=', Qty::format($qtyMilli))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->orderByDesc('min_qty')
            ->orderByRaw('starts_at IS NULL') // scheduled first
            ->orderByDesc('starts_at')
            ->first();
    }
}

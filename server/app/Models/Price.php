<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Price extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['price_list_id', 'product_id', 'product_uom_id', 'min_qty', 'amount_minor', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['min_qty' => 'decimal:3', 'amount_minor' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'set_by_hand_at' => 'datetime'];
    }

    /**
     * Set on purpose (product form, price file, a direct purchase's new price): the pricing rules leave it until a
     * delivery newer than the latest one now. Remembers that delivery line, not a time: a price typed and a delivery
     * posted in the same second must still be told apart.
     */
    public function markSetByHand(): void
    {
        $this->set_by_hand_at = now();
        $this->set_by_hand_cost_line_id = self::latestDeliveryLineId((string) $this->tenant_id, (string) $this->product_id);
    }

    /** The product's latest posted delivery line (the one the pricing rules take the cost from), or null. */
    public static function latestDeliveryLineId(string $tenantId, string $productId): ?string
    {
        return DB::table('goods_receipt_lines as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->where('g.tenant_id', $tenantId)->where('g.status', 'posted')->where('l.product_id', $productId)
            ->orderByDesc('g.posted_at')->orderByDesc('g.id')->orderByDesc('l.line_no')->value('l.id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class);
    }
}

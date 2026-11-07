<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barcode extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['product_id', 'product_uom_id', 'industry_id', 'code'];

    protected function casts(): array
    {
        return [];
    }

    /**
     * The barcode as scanned in an industry: that industry's product first, then a shared one. Without an industry
     * (head office, a company-level store) only a code on one product counts; on several it is ambiguous (null).
     */
    public static function scanned(string $code, ?string $industryId): ?self
    {
        $rows = self::where('code', $code)->get();
        if ($industryId !== null) {
            return $rows->firstWhere('industry_id', $industryId) ?? $rows->first(fn ($b) => $b->industry_id === null);
        }

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /** True when a code is on products in several industries (scanned at company level, it says which). */
    public static function inSeveralIndustries(string $code): bool
    {
        return self::where('code', $code)->count() > 1;
    }

    /** The industry a store sells for: its branch's (null = company level). */
    public static function industryOf(?Warehouse $warehouse): ?string
    {
        return $warehouse ? Branch::withoutGlobalScopes()->whereKey($warehouse->branch_id)->value('industry_id') : null;
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

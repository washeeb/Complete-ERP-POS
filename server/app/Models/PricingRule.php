<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pricing rule: how a selling price follows the latest cost (App\Domain\Pricing\PricingRules). */
class PricingRule extends Model
{
    use Archivable, BelongsToTenant, HasOriginNode, HasUuids;

    public const SCOPES = ['company' => 'The whole company', 'category' => 'A category (and the categories under it)', 'product' => 'One product'];

    public const METHODS = ['multiply' => 'Cost × a number (e.g. × 1.40)', 'markup' => 'Markup: cost + a percentage of cost', 'margin' => 'Margin: a percentage of the selling price',
        'plus' => 'Cost + a fixed amount', 'manual' => 'Manual: no automatic price'];

    public const TAX_MODES = ['inclusive' => 'Shelf price (tax inside)', 'exclusive' => 'Before tax (taxes added on top)'];

    public const ON_CHANGE = ['rise_auto' => 'Rises at once, drops after a manager\'s approval', 'auto' => 'Always at once', 'approve' => 'Always after a manager\'s approval'];

    public const ROUNDING = [1 => 'No rounding', 5 => 'To 5 pesewas', 10 => 'To 10 pesewas', 50 => 'To 50 pesewas', 100 => 'To the cedi'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value_bp' => 'integer', 'amount_minor' => 'integer', 'round_minor' => 'integer', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** "Cost × 1.40", "Markup 25 %", "Cost + 2.00", "Manual". */
    public function describe(): string
    {
        $v = $this->value_bp ?? 0;

        return match ($this->method) {
            'multiply' => 'Cost × '.number_format($v / 10000, 2),
            'markup' => 'Markup '.rtrim(rtrim(number_format($v / 100, 2), '0'), '.').' %',
            'margin' => 'Margin '.rtrim(rtrim(number_format($v / 100, 2), '0'), '.').' %',
            'plus' => 'Cost + '.number_format(($this->amount_minor ?? 0) / 100, 2),
            default => 'Manual',
        };
    }
}

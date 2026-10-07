<?php

namespace App\Domain\Grocery;

use App\Domain\Channels\OutboxConsumers;
use App\Domain\Pricing\PriceResolver;
use App\Models\OutboxEvent;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Electronic shelf labels hook (Grocery): when a product or price list changes, the new shelf prices are sent to the
 * shelf-label gateway's address (Settings → Grocery). A gateway that is down is retried at the next run; other
 * deliveries (tills, online stores) do not wait for it.
 *
 * Body: {"items": [{"sku", "name", "price_minor", "currency", "unit"}]}
 */
final class EslRelay
{
    public function __construct(private readonly OutboxConsumers $consumers, private readonly Settings $settings, private readonly PriceResolver $prices) {}

    public function run(): int
    {
        return $this->consumers->consume('esl', function (OutboxEvent $e) {
            $url = trim((string) $this->settings->get('grocery.esl_webhook_url', $e->tenant_id));
            if ($url === '') {
                return;
            }
            $ids = match (true) {
                $e->aggregate_type === 'product' => [$e->aggregate_id],
                in_array($e->aggregate_type, ['price_list', 'tax_group', 'tax_rate'], true) => Product::withoutGlobalScopes()->where('tenant_id', $e->tenant_id)->where('is_active', true)->pluck('id')->all(),
                default => [],
            };
            if (! $ids) {
                return;
            }
            $list = PriceList::withoutGlobalScopes()->where('tenant_id', $e->tenant_id)->where('is_default', true)->first();
            $items = [];
            foreach (Product::withoutGlobalScopes()->whereIn('id', $ids)->get() as $p) {
                $unit = ProductUom::with('uom')->where('product_id', $p->id)->where('is_base', true)->first();
                $price = $list && $unit ? $this->prices->resolve($list, $p->id, $unit->id, 1000, now())?->amount_minor : null;
                $items[] = ['sku' => $p->sku, 'name' => $p->name, 'price_minor' => $price, 'currency' => $list?->currency_code, 'unit' => $unit?->uom?->code];
            }
            $res = Http::timeout(10)->acceptJson()->post($url, ['items' => $items]);
            if ($res->failed()) {
                throw new RuntimeException("Shelf-label gateway answered {$res->status()}."); // the cursor stays: retried next run
            }
        }, 200, fn (OutboxEvent $e) => in_array($e->aggregate_type, ['product', 'price_list', 'tax_group', 'tax_rate'], true));
    }
}

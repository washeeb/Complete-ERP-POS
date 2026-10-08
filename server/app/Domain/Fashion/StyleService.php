<?php

namespace App\Domain\Fashion;

use App\Domain\Catalog\CatalogService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Barcode;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Style;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Security\Authorizer;
use App\Support\Ean13;
use App\Support\Money;

/**
 * Styles (Fashion, owner default 2026-10-19): one design in sizes and colours. Each size × colour is its own product —
 * own SKU (CODE-COLOUR-SIZE), own internal barcode, own stock and price — so the till, stock ledger, promotions and
 * reports need nothing new; the style ties them together for the matrix views.
 */
final class StyleService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly CatalogService $catalog) {}

    /** @param array{code?: string, name?: string, category_id?: ?string, brand_id?: ?string, tax_group_id?: string, season?: ?string, collection?: ?string, sizes?: list<string>|string, colours?: list<string>|string, price?: ?string, description?: ?string, is_active?: bool} $data */
    public function save(Actor $actor, Style $style, array $data): Style
    {
        $this->require($actor);
        $code = strtoupper(trim((string) ($data['code'] ?? $style->code)));
        if (! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $code)) {
            throw new DomainException('Style code: letters, digits, dot, dash or underscore (up to 30).');
        }
        if (Style::where('code', $code)->when($style->exists, fn ($q) => $q->whereKeyNot($style->id))->exists()) {
            throw new DomainException("Style {$code} already exists.");
        }
        $sizes = self::values($data['sizes'] ?? $style->sizes ?? [], 20);
        $colours = self::values($data['colours'] ?? $style->colours ?? [], 40);
        if ($sizes === [] || $colours === [] || count($sizes) * count($colours) > 400) {
            throw new DomainException('Give at least one size and one colour (at most 400 combinations).');
        }
        if ($style->exists) {
            // Sizes and colours already sold or stocked stay: products are never deleted.
            $used = Product::where('style_id', $style->id)->get(['size', 'colour']);
            $lost = $used->filter(fn ($p) => ! in_array($p->size, $sizes, true) || ! in_array($p->colour, $colours, true));
            if ($lost->isNotEmpty()) {
                throw new DomainException('Sizes or colours that already have products cannot be removed (switch those products off instead): '.
                    $lost->map(fn ($p) => "{$p->colour} {$p->size}")->unique()->implode(', ').'.');
            }
        }
        $name = trim((string) ($data['name'] ?? $style->name));
        $tax = TaxGroup::find($data['tax_group_id'] ?? $style->tax_group_id) ?? throw new DomainException('Choose the tax group.');
        foreach ([[Category::class, 'category_id', 'category'], [Brand::class, 'brand_id', 'brand']] as [$model, $field, $label]) {
            if (! empty($data[$field]) && ! $model::find($data[$field])) {
                throw new DomainException("Choose the {$label} from the list.");
            }
        }
        $price = array_key_exists('price', $data) ? ($data['price'] === null || $data['price'] === '' ? null : Money::toMinor((string) $data['price'], $actor->user->tenant->currency_code)) : $style->price_minor;
        if ($name === '' || ($price !== null && $price < 0)) {
            throw new DomainException('Enter the style name, and a price that is not negative.');
        }
        $style->forceFill(['tenant_id' => $actor->tenantId, 'code' => $code, 'name' => mb_substr($name, 0, 255), 'tax_group_id' => $tax->id,
            'category_id' => array_key_exists('category_id', $data) ? ($data['category_id'] ?: null) : $style->category_id,
            'brand_id' => array_key_exists('brand_id', $data) ? ($data['brand_id'] ?: null) : $style->brand_id,
            'season' => array_key_exists('season', $data) ? (strtoupper(trim((string) $data['season'])) ?: null) : $style->season,
            'collection' => array_key_exists('collection', $data) ? (trim((string) $data['collection']) ?: null) : $style->collection,
            'sizes' => $sizes, 'colours' => $colours, 'price_minor' => $price,
            'description' => array_key_exists('description', $data) ? (trim((string) $data['description']) ?: null) : $style->description,
            'is_active' => (bool) ($data['is_active'] ?? $style->is_active ?? true)]);

        return $this->recorder->save($actor, $style, $style->exists ? 'style.updated' : 'style.created');
    }

    /**
     * Create the size × colour products that do not exist yet, and bring the shared details (name, category, brand,
     * tax) of the existing ones in line with the style. Returns how many were created.
     */
    public function generate(Actor $actor, Style $style): int
    {
        $this->require($actor);
        $list = PriceList::where('is_default', true)->firstOrFail();
        $pc = Uom::where('code', 'PC')->firstOrFail();
        $existing = Product::where('style_id', $style->id)->get()->keyBy(fn ($p) => "{$p->colour}|{$p->size}");
        $created = 0;

        return $this->recorder->transaction(function () use ($actor, $style, $list, $pc, $existing, &$created) {
            foreach ($style->colours as $colour) {
                foreach ($style->sizes as $size) {
                    $shared = ['name' => mb_substr("{$style->name} {$colour} {$size}", 0, 160), 'category_id' => $style->category_id, 'brand_id' => $style->brand_id,
                        'tax_group_id' => $style->tax_group_id, 'is_active' => $style->is_active];
                    if ($p = $existing["{$colour}|{$size}"] ?? null) {
                        $this->catalog->saveProduct($actor, $p, $shared);

                        continue;
                    }
                    $sku = mb_substr($style->code.'-'.self::skuPart($colour).'-'.self::skuPart($size), 0, 40);
                    $p = $this->catalog->saveProduct($actor, new Product, $shared + ['sku' => $sku, 'base_uom_id' => $pc->id, 'track_stock' => true,
                        'base_barcodes' => [$this->barcode($actor->tenantId, $sku)], 'units' => [],
                        'prices' => $style->price_minor !== null ? [['price_list_id' => $list->id, 'uom_id' => $pc->id, 'min_qty' => '1',
                            'amount' => Money::format($style->price_minor, $list->currency_code)]] : []]);
                    $p->forceFill(['style_id' => $style->id, 'size' => $size, 'colour' => $colour]);
                    $this->recorder->save($actor, $p, 'product.variant_of_style', branchId: null, payload: ['style_id' => $style->id]);
                    $created++;
                }
            }

            return $created;
        });
    }

    /** One price on the default price list for every size and colour of the style (other lists untouched). */
    public function setPrice(Actor $actor, Style $style, string $price): int
    {
        $this->require($actor);
        $list = PriceList::where('is_default', true)->firstOrFail();
        $amount = Money::toMinor($price, $list->currency_code);
        if ($amount < 0) {
            throw new DomainException('The price cannot be negative.');
        }

        return $this->recorder->transaction(function () use ($actor, $style, $list, $amount) {
            $n = 0;
            foreach (Product::with(['prices.productUom', 'units'])->where('style_id', $style->id)->get() as $p) {
                $base = $p->units->firstWhere('is_base', true);
                $rows = $p->prices->reject(fn ($x) => $x->price_list_id === $list->id && $x->product_uom_id === $base->id && (float) $x->min_qty <= 1 && ! $x->starts_at)
                    ->map(fn ($x) => ['price_list_id' => $x->price_list_id, 'uom_id' => $x->productUom->uom_id, 'min_qty' => (string) $x->min_qty,
                        'amount' => Money::format($x->amount_minor, $list->currency_code), 'starts_at' => $x->starts_at?->toDateTimeString(), 'ends_at' => $x->ends_at?->toDateTimeString()])
                    ->values()->all();
                $rows[] = ['price_list_id' => $list->id, 'uom_id' => $base->uom_id, 'min_qty' => '1', 'amount' => Money::format($amount, $list->currency_code)];
                $this->catalog->saveProduct($actor, $p, ['prices' => $rows]);
                $n++;
            }
            $style->forceFill(['price_minor' => $amount]);
            $this->recorder->save($actor, $style, 'style.price_set');

            return $n;
        });
    }

    /** "s, M ,l" → ["S", "M", "L"] (trimmed, unique, in the order given). */
    public static function values(array|string $list, int $max): array
    {
        $items = is_array($list) ? $list : explode(',', $list);

        return array_values(array_unique(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, $max), $items), fn ($v) => $v !== '')));
    }

    private static function skuPart(string $v): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9.]/', '', $v)) ?: 'X';
    }

    /** An internal EAN-13 (prefix 20, for in-store use) that no product has yet. */
    private function barcode(string $tenantId, string $sku): string
    {
        $n = crc32($tenantId.'|'.$sku) % 10_000_000_000;
        for ($i = 0; $i < 50; $i++) {
            $code = Ean13::complete('20'.str_pad((string) (($n + $i) % 10_000_000_000), 10, '0', STR_PAD_LEFT));
            if (! Barcode::where('code', $code)->exists()) {
                return $code;
            }
        }
        throw new DomainException('Could not find a free barcode; try again.');
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'catalog.manage')) {
            throw new AccessDenied;
        }
    }
}

<?php

namespace App\Domain\Catalog;

use App\Domain\Pricing\PriceResolver;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Ean13;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Barcode and shelf labels (Stock Controller release 3): on A4 label sheets by default (any office printer), or one per
 * label on a label printer (Zebra, Xprinter…, set as the browser's printer). Products without a barcode can be given
 * one first: an in-store EAN-13 starting with the company's prefix (default 20), which the tills scan like any other.
 */
final class Labels
{
    /** key => [label, columns, rows, width mm, height mm] (rows 0 = one label per page, for label printers) */
    public const LAYOUTS = [
        'a4_24' => ['A4 sheet, 24 labels (3 × 8, 64 × 34 mm — e.g. Avery L7159)', 3, 8, 64.0, 33.9],
        'a4_21' => ['A4 sheet, 21 labels (3 × 7, 63.5 × 38 mm — e.g. Avery L7160)', 3, 7, 63.5, 38.1],
        'a4_65' => ['A4 sheet, 65 small labels (5 × 13, 38 × 21 mm — e.g. Avery L7651)', 5, 13, 38.1, 21.2],
        'roll_50x25' => ['Label printer, 50 × 25 mm', 1, 0, 50.0, 25.0],
        'roll_38x25' => ['Label printer, 38 × 25 mm', 1, 0, 38.0, 25.0],
        'roll_58x40' => ['Label printer, 58 × 40 mm', 1, 0, 58.0, 40.0],
    ];

    public const KINDS = ['shelf' => 'Shelf label: name and price large, barcode small', 'barcode' => 'Barcode sticker: barcode large, name and price small'];

    public function __construct(private readonly Authorizer $authz, private readonly Settings $settings, private readonly CatalogService $catalog) {}

    /**
     * Give products without any barcode an in-store EAN-13 (prefix + 10-digit number + check digit) on their base unit.
     *
     * @param  list<string>  $productIds
     * @return int how many were given one
     */
    public function giveBarcodes(Actor $actor, array $productIds): int
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'catalog.manage')) {
            throw new AccessDenied;
        }
        $prefix = preg_replace('/\D/', '', (string) $this->settings->get('catalog.internal_barcode_prefix', $actor->tenantId));
        if (strlen($prefix) !== 2) {
            throw new DomainException('Set the in-store barcode prefix (2 digits) under System → Settings → Stock first.');
        }
        $without = Product::whereIn('id', $productIds)->whereNotExists(fn ($q) => $q->from('barcodes')->whereColumn('barcodes.product_id', 'products.id'))->get();
        $n = 0;
        foreach ($without as $p) {
            $last = DB::table('barcodes')->where('tenant_id', $actor->tenantId)->where('code', 'like', $prefix.'__________%')->whereRaw('length(code) = 13')->max('code');
            $next = str_pad((string) ((int) substr((string) $last, 2, 10) + 1), 10, '0', STR_PAD_LEFT);
            $this->catalog->saveProduct($actor, $p, ['units' => CatalogService::inputOf($p)['units'], 'base_barcodes' => [Ean13::complete($prefix.$next)]]); // the other units stay as they are
            $n++;
        }

        return $n;
    }

    /**
     * Keep a label job for the print page (an hour, for this person only), and return its print address.
     *
     * @param  array{kind: string, layout: string, price_list_id: ?string, copies: int, skip: int}  $options
     */
    public function prepare(Actor $actor, array $productIds, array $options): string
    {
        if (! isset(self::KINDS[$options['kind'] ?? '']) || ! isset(self::LAYOUTS[$options['layout'] ?? ''])) {
            throw new DomainException('Choose the kind of label and the label sheet or printer.');
        }
        if ($productIds === []) {
            throw new DomainException('Choose the products first.');
        }
        $token = (string) Str::uuid7();
        Cache::put("labels:{$token}", ['user_id' => $actor->id, 'products' => array_values($productIds), 'options' => $options], now()->addHour());

        return route('print.labels', ['token' => $token]);
    }

    /** @return array{user_id: string, products: list<string>, options: array}|null */
    public static function job(string $token): ?array
    {
        return Cache::get("labels:{$token}");
    }

    /**
     * The labels to print, in order, with copies.
     *
     * @return list<array{name: string, sku: string, unit: string, price: ?string, barcode: ?string, location: ?string}>
     */
    public function rows(string $tenantId, array $productIds, ?string $priceListId, int $copies): array
    {
        $list = ($priceListId ? PriceList::find($priceListId) : null) ?? PriceList::where('is_default', true)->first();
        $currency = Tenant::find($tenantId)->currency_code;
        $products = Product::with(['units.uom', 'units.barcodes'])->whereIn('id', $productIds)->get()->sortBy(fn ($p) => array_search($p->id, $productIds, true));
        $out = [];
        foreach ($products as $p) {
            $base = $p->units->firstWhere('is_base', true) ?? $p->units->first();
            $price = $list && $base ? app(PriceResolver::class)->resolve($list, $p->id, $base->id, 1000, now()) : null;
            $row = ['name' => $p->name, 'sku' => $p->sku, 'unit' => $base?->uom->code ?? '', 'location' => $p->location,
                'price' => $price ? Money::display((int) $price->amount_minor, $currency) : null,
                'barcode' => $base?->barcodes->first()?->code ?? $p->units->flatMap->barcodes->first()?->code];
            for ($i = 0; $i < max(1, min(500, $copies)); $i++) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** The barcode as SVG bars: EAN-13 when the code is a valid one, else Code 128 (letters, other lengths). */
    public static function svg(string $code, int $height = 40): string
    {
        $svg = (new BarcodeGeneratorSVG)->getBarcode($code, Ean13::valid($code) ? BarcodeGeneratorSVG::TYPE_EAN_13 : BarcodeGeneratorSVG::TYPE_CODE_128, 1.6, $height); // bars only; the digits print under it

        return preg_replace('/<svg(?=\s)/', '<svg preserveAspectRatio="none"', $svg, 1); // stretches to the label's width
    }
}

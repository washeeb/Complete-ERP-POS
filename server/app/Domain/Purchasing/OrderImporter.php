<?php

namespace App\Domain\Purchasing;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvoiceScanner;
use App\Domain\Imports\SheetFile;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\AiDocument;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Purchase orders from a file (owner request 2026-10-22): an Excel or CSV list, or a supplier's pro-forma invoice read by
 * the AI (photo or PDF). Each line is matched to a product — the supplier's own code, the SKU, a barcode, then the name —
 * and the result is a draft order that a person checks, changes and submits as usual. Lines that cannot be matched, or
 * have no cost, are left out and listed, never guessed.
 */
final class OrderImporter
{
    public const SHEET_TYPES = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    private const COLUMNS = [
        'sku' => ['supplier_sku', 'supplier_code', 'sku', 'code', 'item_code', 'product_code'],
        'barcode' => ['barcode', 'ean', 'upc'],
        'name' => ['product', 'product_name', 'name', 'description', 'item', 'item_name'],
        'qty' => ['qty', 'quantity', 'order_qty', 'qty_ordered'],
        'unit' => ['unit', 'uom', 'pack', 'unit_of_measure'],
        'cost' => ['unit_cost', 'cost', 'unit_price', 'price', 'rate'],
    ];

    public function __construct(private readonly PurchaseOrderService $orders, private readonly AiGateway $gateway, private readonly Authorizer $authz) {}

    /**
     * An Excel (.xlsx) or CSV file with a product column (code, barcode or name), a quantity and optionally a unit and cost.
     *
     * @return array{order: PurchaseOrder, skipped: list<string>}
     */
    public function fromSheet(Actor $actor, Supplier $supplier, Warehouse $warehouse, string $path, string $fileName): array
    {
        $this->allow($actor, $warehouse);
        $rows = [];
        foreach (SheetFile::read($path, $fileName)['rows'] as $row) {
            $r = ['line' => $row['line']];
            foreach (self::COLUMNS as $field => $headings) {
                $r[$field] = '';
                foreach ($headings as $h) {
                    if (trim((string) ($row['values'][$h] ?? '')) !== '') {
                        $r[$field] = trim((string) $row['values'][$h]);
                        break;
                    }
                }
            }
            if ($r['sku'].$r['barcode'].$r['name'] !== '') {
                $rows[] = $r;
            }
        }
        if ($rows === []) {
            throw new DomainException('No lines found. The file needs a heading row with a product column (SKU, barcode or product name) and a Qty column.');
        }

        return $this->draft($actor, $supplier, $warehouse, $rows, 'Imported from '.mb_substr($fileName, 0, 120));
    }

    /**
     * A supplier's pro-forma invoice (photo or PDF): the AI reads the supplier and lines; the supplier chosen on screen wins.
     *
     * @return array{order: PurchaseOrder, skipped: list<string>}
     */
    public function fromProforma(Actor $actor, Warehouse $warehouse, ?Supplier $supplier, string $path, string $fileName): array
    {
        $this->allow($actor, $warehouse);
        $disk = Storage::disk('local');
        $mime = $disk->mimeType($path) ?: '';
        if (! in_array($mime, InvoiceScanner::TYPES, true) || $disk->size($path) > 10 * 1024 * 1024) {
            throw new DomainException('Upload a photo (JPG, PNG, WebP) or PDF of the pro-forma invoice, at most 10 MB.');
        }
        $provider = $this->gateway->provider($actor->tenantId, 'vision');
        if ($mime === 'application/pdf' && ! in_array($provider->name(), ['claude', 'fake'], true)) {
            throw new DomainException('This AI model reads photos, not PDFs: take a photo or screenshot of the pro-forma (JPG or PNG).');
        }
        $doc = AiDocument::create(['tenant_id' => $actor->tenantId, 'kind' => 'proforma', 'file_path' => $path, 'file_name' => mb_substr($fileName, 0, 255),
            'status' => 'failed', 'created_by_name' => $actor->name]);
        try {
            $reply = $this->gateway->chat($actor, 'proforma_scan', $provider, $this->prompt(), [['role' => 'user', 'content' => 'Read this pro-forma invoice and return the JSON.']],
                images: [['mime' => $mime, 'data' => base64_encode($disk->get($path))]], json: true);
            $data = InvoiceScanner::json($reply->text) ?? throw new DomainException('The pro-forma could not be read. Try a sharper, straight photo.');
            $supplier ??= InvoiceScanner::matchSupplier($data)
                ?? throw new DomainException('Choose the supplier: "'.trim((string) ($data['supplier_name'] ?? 'unknown')).'" is not among your suppliers.');
            $rows = [];
            foreach (array_values((array) ($data['lines'] ?? [])) as $i => $l) {
                $qty = (float) ($l['qty'] ?? 0);
                $price = (float) ($l['unit_price'] ?? 0) ?: ($qty > 0 ? (float) ($l['line_total'] ?? 0) / $qty : 0);
                $rows[] = ['line' => $i + 1, 'sku' => trim((string) ($l['sku'] ?? '')), 'barcode' => '', 'name' => trim((string) ($l['description'] ?? '')),
                    'qty' => (string) $qty, 'unit' => trim((string) ($l['unit'] ?? '')), 'cost' => $price > 0 ? (string) $price : ''];
            }
            $ref = trim((string) ($data['proforma_number'] ?? ''));
            $result = $this->draft($actor, $supplier, $warehouse, $rows, 'From pro-forma'.($ref !== '' ? ' '.mb_substr($ref, 0, 60) : '').' — check every line against the paper.');
        } catch (DomainException $e) {
            $doc->forceFill(['error' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }
        $doc->forceFill(['status' => 'used', 'extracted' => $data, 'form' => ['purchase_order_id' => $result['order']->id, 'skipped' => $result['skipped']]])->save();

        return $result;
    }

    private function prompt(): string
    {
        return 'You read pro-forma invoices (quotations) from suppliers to a business in Ghana. Return only JSON with these keys: supplier_name, '
            .'supplier_tax_no (TIN, or null), proforma_number, date (YYYY-MM-DD), currency (e.g. GHS), lines (a list of {description, sku (the '
            .'supplier\'s item code, or null), qty (number), unit (as printed, e.g. PACK, BOX, or null), unit_price (number, before tax), line_total '
            .'(number, before tax)}). Use numbers without currency symbols or thousands separators. Copy text exactly as printed; use null for '
            .'anything you cannot read. Do not add lines that are not on the document.';
    }

    /**
     * @param  list<array{line: int, sku: string, barcode: string, name: string, qty: string, unit: string, cost: string}>  $rows
     * @return array{order: PurchaseOrder, skipped: list<string>}
     */
    private function draft(Actor $actor, Supplier $supplier, Warehouse $warehouse, array $rows, string $note): array
    {
        if (! $supplier->is_active) {
            throw new DomainException("{$supplier->name} is inactive.");
        }
        $lines = [];
        $skipped = [];
        foreach ($rows as $r) {
            $label = 'Line '.$r['line'].' ('.($r['name'] ?: $r['sku'] ?: $r['barcode']).')';
            $qty = self::number($r['qty']);
            if ($qty === null || (float) $qty <= 0) {
                $skipped[] = "{$label}: no quantity";

                continue;
            }
            $product = $this->product($supplier, $r);
            if (! $product) {
                $skipped[] = "{$label}: no matching product";

                continue;
            }
            $unit = $this->unit($supplier, $product, $r['unit']);
            $cost = self::number($r['cost']);
            if ($cost === null && ! SupplierProduct::where('supplier_id', $supplier->id)->where('product_uom_id', $unit->id)->whereNotNull('last_cost_minor')->exists()) {
                $skipped[] = "{$label}: no cost given and none known from {$supplier->name}";

                continue;
            }
            $lines[] = ['product_uom_id' => $unit->id, 'qty' => $qty, 'unit_cost' => $cost];
        }
        if ($lines === []) {
            throw new DomainException('No line could be used. '.implode('; ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? '; …' : ''));
        }
        $order = $this->orders->saveDraft($actor, new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'note' => $note, 'lines' => $lines]);

        return ['order' => $order, 'skipped' => $skipped];
    }

    /** The supplier's own item code, the SKU, a barcode, the exact name, then a close name among this supplier's products. */
    private function product(Supplier $supplier, array $r): ?Product
    {
        $active = fn () => Product::where('is_active', true);
        if ($r['sku'] !== '') {
            $id = SupplierProduct::where('supplier_id', $supplier->id)->whereRaw('lower(supplier_sku) = ?', [mb_strtolower($r['sku'])])->value('product_id');
            $p = ($id ? $active()->find($id) : null) ?? $active()->whereRaw('lower(sku) = ?', [mb_strtolower($r['sku'])])->first();
            if ($p) {
                return $p;
            }
        }
        foreach (array_filter([$r['barcode'], ctype_digit($r['sku']) ? $r['sku'] : '']) as $code) {
            $id = DB::table('barcodes')->where('tenant_id', $supplier->tenant_id)->where('code', $code)->value('product_id');
            if ($id && ($p = $active()->find($id))) {
                return $p;
            }
        }
        $name = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $r['name'])));
        if ($name === '') {
            return null;
        }
        if ($p = $active()->where('name', $name)->first()) {
            return $p;
        }
        // Close names, among what this supplier sells first, then every product: at least 85% alike, so near misses are listed, not guessed.
        $theirs = SupplierProduct::where('supplier_id', $supplier->id)->pluck('product_id')->unique();
        foreach ([$active()->whereIn('id', $theirs), $active()] as $q) {
            $best = null;
            $score = 0;
            foreach ($q->get(['id', 'name']) as $c) {
                similar_text($name, mb_strtoupper($c->name), $pct);
                if ($pct > $score) {
                    [$best, $score] = [$c, $pct];
                }
            }
            if ($score >= 85) {
                return Product::find($best->id);
            }
        }

        return null;
    }

    /** The unit named on the line (code or name), else the unit this supplier sells it in, else the base unit. */
    private function unit(Supplier $supplier, Product $product, string $unit): ProductUom
    {
        $units = ProductUom::with('uom')->where('product_id', $product->id)->get();
        $u = mb_strtoupper(trim($unit));
        if ($u !== '' && ($match = $units->first(fn ($x) => in_array($u, [mb_strtoupper($x->uom->code), mb_strtoupper($x->uom->name)], true)))) {
            return $match;
        }
        $theirs = SupplierProduct::where('supplier_id', $supplier->id)->where('product_id', $product->id)->orderByDesc('is_preferred')->value('product_uom_id');

        return $units->firstWhere('id', $theirs) ?? $units->firstWhere('is_base', true) ?? $units->first();
    }

    /** "1,200.50" → "1200.50"; null when there is no number. */
    private static function number(string $v): ?string
    {
        $v = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $v));

        return is_numeric($v) ? $v : null;
    }

    private function allow(Actor $actor, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'purchasing.manage', $warehouse)) {
            throw new AccessDenied;
        }
    }
}

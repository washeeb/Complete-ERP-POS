<?php

namespace App\Domain\Ai;

use App\Domain\Payables\BillService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\AiDocument;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Supplier invoice → draft bill. The AI reads the photo (or PDF, cloud model only) into supplier, number, dates, the
 * order number quoted and lines; the lines are matched to goods received from that supplier and still to bill (by SKU,
 * then by name; deliveries on the quoted order first), then to services confirmed on their orders and not yet billed;
 * the rest become expense lines. Checks are shown with the form: the total printed against the lines read, an invoice
 * number already entered, a supplier not found. The result only pre-fills the bill form: a person checks every line
 * and enters the bill, which then goes through the normal price-matching and approval rules. Nothing is posted by the AI.
 */
final class InvoiceScanner
{
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(private readonly AiGateway $gateway, private readonly Authorizer $authz) {}

    /** $path: a file on the local (private) disk, already uploaded. */
    /** @param ?Supplier $supplier known already (an invoice sent on the supplier portal): not guessed from the paper */
    public function scan(Actor $actor, Branch $branch, string $path, string $fileName, ?Supplier $supplier = null, ?string $supplierInvoiceId = null): AiDocument
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'payables.manage', $branch)) {
            throw new AccessDenied;
        }
        $disk = Storage::disk('local');
        $mime = $disk->mimeType($path) ?: '';
        if (! in_array($mime, self::TYPES, true) || $disk->size($path) > 10 * 1024 * 1024) {
            throw new DomainException('Upload a photo (JPG, PNG, WebP) or PDF of the invoice, at most 10 MB.');
        }
        $provider = $this->gateway->provider($actor->tenantId, 'vision');
        if ($mime === 'application/pdf' && ! in_array($provider->name(), ['claude', 'fake'], true)) {
            throw new DomainException('This AI model reads photos, not PDFs: take a photo or screenshot of the invoice (JPG or PNG).');
        }
        $doc = AiDocument::create(['tenant_id' => $actor->tenantId, 'kind' => 'supplier_invoice', 'file_path' => $path, 'file_name' => mb_substr($fileName, 0, 255),
            'status' => 'failed', 'created_by_name' => $actor->name]);
        try {
            $reply = $this->gateway->chat($actor, 'invoice_scan', $provider, $this->prompt(), [['role' => 'user', 'content' => 'Read this supplier invoice and return the JSON.']],
                images: [['mime' => $mime, 'data' => base64_encode($disk->get($path))]], json: true);
            $data = self::json($reply->text) ?? throw new DomainException('The invoice could not be read. Try a sharper, straight photo.');
        } catch (DomainException $e) {
            $doc->forceFill(['error' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }
        $form = $this->form($actor, $branch, $data, $supplier) + ($supplierInvoiceId ? ['supplier_invoice_id' => $supplierInvoiceId] : []);
        $doc->forceFill(['status' => 'read', 'extracted' => $data, 'form' => $form])->save();

        return $doc;
    }

    /**
     * A direct purchase's invoice: every page read, then one form (Purchasing → Direct purchases), with the lots and
     * expiry dates. Lines are matched to products by the supplier's code, the SKU, then the name; one with no match
     * keeps what was read, for a person to choose or add. Nothing is entered until a person checks and saves it.
     *
     * @param  list<string>  $paths  files on the local (private) disk, in page order
     * @return array<string, mixed> the form's data
     */
    public function scanPurchase(Actor $actor, Branch $branch, array $paths): array
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'purchasing.direct', $branch)) {
            throw new AccessDenied;
        }
        $disk = Storage::disk('local');
        $provider = $this->gateway->provider($actor->tenantId, 'vision');
        $pages = [];
        foreach ($paths as $path) {
            $mime = $disk->mimeType($path) ?: '';
            if (! in_array($mime, self::TYPES, true) || $disk->size($path) > 10 * 1024 * 1024) {
                throw new DomainException('Upload photos (JPG, PNG, WebP) or a PDF of the invoice, at most 10 MB each.');
            }
            if ($mime === 'application/pdf' && ! in_array($provider->name(), ['claude', 'fake'], true)) {
                throw new DomainException('This AI model reads photos, not PDFs: take a photo or screenshot of the invoice (JPG or PNG).');
            }
            $reply = $this->gateway->chat($actor, 'invoice_scan', $provider, $this->purchasePrompt(), [['role' => 'user', 'content' => 'Read this page of a supplier invoice and return the JSON.']],
                images: [['mime' => $mime, 'data' => base64_encode($disk->get($path))]], json: true);
            $page = self::json($reply->text) ?? throw new DomainException('The invoice could not be read. Try a sharper, straight photo.');
            AiDocument::create(['tenant_id' => $actor->tenantId, 'kind' => 'supplier_invoice', 'file_path' => $path, 'file_name' => basename($path), 'status' => 'read',
                'created_by_name' => $actor->name, 'extracted' => $page]);
            $pages[] = $page;
        }
        $first = $pages[0] ?? [];
        $supplier = self::matchSupplier($first);
        $lines = [];
        foreach ($pages as $page) {
            foreach ((array) ($page['lines'] ?? []) as $l) {
                $lines[] = $this->purchaseLine($l, $supplier);
            }
        }
        $total = collect($pages)->map(fn ($p) => $p['total'] ?? null)->filter()->last();

        return ['supplier_id' => $supplier?->id, 'invoice_no' => mb_substr(trim((string) ($first['invoice_number'] ?? '')), 0, 60), 'invoice_date' => min(self::date($first['invoice_date'] ?? null) ?? now()->toDateString(), now()->toDateString()),
            'prices_include_tax' => (bool) ($first['prices_include_tax'] ?? false), 'printed_total' => $total, 'attachments' => $paths, 'lines' => $lines,
            'note' => 'Read from a scanned invoice: check every line against the paper.'];
    }

    private function purchaseLine(array $l, ?Supplier $supplier): array
    {
        $code = trim((string) ($l['sku'] ?? ''));
        $name = trim((string) ($l['description'] ?? ''));
        $unit = null;
        if ($code !== '') {
            $unit = DB::table('supplier_products')->where('supplier_sku', $code)->when($supplier, fn ($q) => $q->where('supplier_id', $supplier->id))->value('product_uom_id');
            $product = $unit ? null : Product::whereRaw('lower(sku) = lower(?)', [$code])->first();
        }
        $product ??= null;
        if (! $unit && ! $product && $name !== '') {
            $best = 0;
            foreach (Product::where('is_active', true)->get(['id', 'name']) as $p) {
                similar_text(mb_strtolower($name), mb_strtolower($p->name), $pct);
                if ($pct > $best && $pct >= 75) {
                    [$product, $best] = [$p, $pct];
                }
            }
        }
        if (! $unit && $product) {
            $buying = Product::find($product->id);
            $unit = ProductUom::where('product_id', $product->id)->when($buying->buying_uom_id, fn ($q, $u) => $q->where('uom_id', $u), fn ($q) => $q->where('is_base', true))->value('id');
        }
        $qty = (float) ($l['qty'] ?? 0) ?: 1.0;

        return ['product_uom_id' => $unit, 'read_as' => $unit ? null : $name, 'qty' => $qty, 'unit_price' => (float) ($l['unit_price'] ?? 0) ?: ((float) ($l['line_total'] ?? 0) / $qty),
            'batch_no' => mb_substr(trim((string) ($l['lot'] ?? '')), 0, 60) ?: null, 'expires_on' => self::date($l['expiry_date'] ?? null), 'supplier_sku' => $code !== '' ? mb_substr($code, 0, 60) : null];
    }

    private function purchasePrompt(): string
    {
        return 'You read one page of a supplier invoice for a business in Ghana. Return only JSON with these keys: supplier_name, supplier_tax_no (TIN, or null), invoice_number, invoice_date (YYYY-MM-DD), '
            .'prices_include_tax (true only if the prices are stated to include VAT), lines (array of {sku: the supplier\'s item code or null, description, qty (number), lot (lot or batch number, or null), '
            .'expiry_date (YYYY-MM-DD; for 28/02/29 give 2029-02-28), unit_price (number), line_total (number)}), total (the invoice total if on this page, or null). Use numbers without currency symbols or '
            .'thousands separators. Copy text exactly as printed; ignore handwriting; use null for anything you cannot read. Do not add lines that are not on the page.';
    }

    /** The bill was entered from this scan. */
    public function used(AiDocument $doc, string $billId): void
    {
        $doc->forceFill(['status' => 'used', 'supplier_bill_id' => $billId])->save();
    }

    private function prompt(): string
    {
        return 'You read supplier invoices for a business in Ghana. Return only JSON with these keys: supplier_name, supplier_tax_no (TIN, or null), '
            .'invoice_number, order_number (the customer\'s purchase order number quoted on it, e.g. PO-HQ-000123, or null), invoice_date (YYYY-MM-DD), due_date (YYYY-MM-DD or null), currency (e.g. GHS), lines (a list of {description, sku (or null), '
            .'qty (number), unit_price (number, before tax), line_total (number, before tax)}), subtotal, tax_total, total. Use numbers without currency '
            .'symbols or thousands separators. Copy text exactly as printed; use null for anything you cannot read. Do not add lines that are not on the invoice.';
    }

    /** Parse JSON from a model answer (tolerating code fences). */
    public static function json(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

        return is_array($data) ? $data : null;
    }

    /** The supplier bill form, pre-filled and matched. */
    private function form(Actor $actor, Branch $branch, array $d, ?Supplier $known = null): array
    {
        $currency = Money::exponent(DB::table('tenants')->where('id', $actor->tenantId)->value('currency_code') ?? 'GHS');
        $supplier = $known ?? self::matchSupplier($d);
        $date = self::date($d['invoice_date'] ?? null);
        $form = ['supplier_id' => $supplier?->id, 'supplier_invoice_no' => mb_substr(trim((string) ($d['invoice_number'] ?? '')), 0, 60), 'branch_id' => $branch->id,
            'bill_date' => $date && $date <= now()->toDateString() ? $date : now()->toDateString(), 'due_on' => self::date($d['due_date'] ?? null),
            'note' => 'Read from a scanned invoice — check every line against the paper.', 'stock_lines' => [], 'order_lines' => [], 'expense_lines' => []];
        $order = $supplier ? self::orderOf($supplier, (string) ($d['order_number'] ?? '')) : null;
        $open = $supplier ? $this->openLines($supplier, $order) : [];
        $services = $supplier ? collect(app(BillService::class)->billableOrderLines($supplier))
            ->sortBy(fn ($l) => $order && $l['order'] === $order ? 0 : 1)->keyBy('purchase_order_line_id')->all() : [];
        foreach ((array) ($d['lines'] ?? []) as $l) {
            $qty = (float) ($l['qty'] ?? 0) ?: 1.0;
            $price = (float) ($l['unit_price'] ?? 0) ?: ((float) ($l['line_total'] ?? 0) / $qty);
            $match = $this->match($open, (string) ($l['sku'] ?? ''), (string) ($l['description'] ?? ''));
            $service = $match ? null : $this->match(array_map(fn ($x) => $x + ['sku' => null, 'supplier_sku' => null], $services), '', (string) ($l['description'] ?? ''));
            if ($service) {
                $take = min($qty, (float) $services[$service]['open_qty']);
                $services[$service]['open_qty'] = (string) ((float) $services[$service]['open_qty'] - $take);
                $form['order_lines'][] = ['purchase_order_line_id' => $service, 'qty' => rtrim(rtrim(number_format($take, 3, '.', ''), '0'), '.'),
                    'unit_cost' => number_format($price, $currency, '.', '')];
            } elseif ($match) {
                $take = min($qty, (float) $open[$match]['open_qty']);
                $open[$match]['open_qty'] = (string) ((float) $open[$match]['open_qty'] - $take);
                $form['stock_lines'][] = ['goods_receipt_line_id' => $match, 'qty' => rtrim(rtrim(number_format($take, 3, '.', ''), '0'), '.'),
                    'unit_cost' => number_format($price, $currency, '.', '')];
            } else {
                $form['expense_lines'][] = ['account_id' => null, 'description' => mb_substr(trim((string) ($l['description'] ?? 'Invoice line')), 0, 255),
                    'amount' => number_format($qty * $price, $currency, '.', ''), 'tax_group_id' => null];
            }
        }

        return $form;
    }

    /** The supplier's order quoted on the invoice (PO-HQ-000123, or just 123 / 000123), if it is one of theirs. */
    public static function orderOf(Supplier $supplier, string $quoted): ?string
    {
        $quoted = strtoupper(trim($quoted));
        if ($quoted === '') {
            return null;
        }
        $orders = DB::table('purchase_orders')->where('supplier_id', $supplier->id)->whereNotIn('status', ['draft', 'cancelled'])->pluck('number');
        $digits = ltrim(preg_replace('/\D/', '', $quoted), '0');

        return $orders->first(fn ($n) => strtoupper($n) === $quoted) ?? ($digits !== '' ? $orders->first(fn ($n) => ltrim(preg_replace('/\D/', '', $n), '0') === $digits) : null);
    }

    /**
     * What a person should look at before entering the bill: the supplier not found, the invoice already entered, the
     * total printed against the lines read, prices above the order.
     *
     * @return list<string>
     */
    public static function checks(AiDocument $doc): array
    {
        $d = $doc->extracted ?? [];
        $f = $doc->form ?? [];
        $out = [];
        $supplier = ($f['supplier_id'] ?? null) ? Supplier::find($f['supplier_id']) : null;
        if (! $supplier) {
            $out[] = 'The supplier "'.($d['supplier_name'] ?? '?').'" was not found: choose it, or add it first.';
        }
        $no = trim((string) ($f['supplier_invoice_no'] ?? ''));
        if ($supplier && $no !== '' && ($dup = DB::table('supplier_bills')->where('supplier_id', $supplier->id)->whereRaw('lower(supplier_invoice_no) = ?', [mb_strtolower($no)])->value('number'))) {
            $out[] = "Invoice {$no} from {$supplier->name} is already entered as {$dup}.";
        }
        $lines = array_sum(array_map(fn ($l) => (float) ($l['line_total'] ?? ((float) ($l['qty'] ?? 1) * (float) ($l['unit_price'] ?? 0))), (array) ($d['lines'] ?? [])));
        $subtotal = isset($d['subtotal']) && is_numeric($d['subtotal']) ? (float) $d['subtotal'] : null;
        if ($subtotal !== null && abs($subtotal - $lines) > max(1, 0.005 * $subtotal)) {
            $out[] = 'The lines read add up to '.number_format($lines, 2).' but the invoice says '.number_format($subtotal, 2).' before tax: a line may be missing or misread.';
        }
        if (isset($d['total'], $d['tax_total']) && is_numeric($d['total']) && is_numeric($d['tax_total']) && $subtotal !== null
            && abs($subtotal + (float) $d['tax_total'] - (float) $d['total']) > 1) {
            $out[] = 'The invoice\'s own figures do not add up (before tax + tax ≠ total): check the paper.';
        }
        if (($d['order_number'] ?? null) && $supplier && ! self::orderOf($supplier, (string) $d['order_number'])) {
            $out[] = "The invoice quotes order {$d['order_number']}, which is not an order to {$supplier->name}.";
        }
        if (($f['expense_lines'] ?? []) !== [] && $supplier) {
            $out[] = count($f['expense_lines']).' line(s) matched nothing received or ordered: they are under Other charges; choose the account, or receive the goods first.';
        }

        return $out;
    }

    /** The supplier named on a document: by TIN, else by the closest name (at least 60% alike). */
    public static function matchSupplier(array $d): ?Supplier
    {
        $tin = preg_replace('/\s+/', '', (string) ($d['supplier_tax_no'] ?? ''));
        if ($tin !== '' && ($s = Supplier::where('is_active', true)->whereRaw("replace(tax_no, ' ', '') = ?", [$tin])->first())) {
            return $s;
        }
        $name = mb_strtolower(trim((string) ($d['supplier_name'] ?? '')));
        if ($name === '') {
            return null;
        }
        $best = null;
        $score = 0;
        foreach (Supplier::where('is_active', true)->get(['id', 'name']) as $s) {
            similar_text($name, mb_strtolower($s->name), $pct);
            if (str_contains($name, mb_strtolower($s->name)) || str_contains(mb_strtolower($s->name), $name)) {
                $pct = max($pct, 90);
            }
            if ($pct > $score) {
                [$best, $score] = [$s, $pct];
            }
        }

        return $score >= 60 ? Supplier::find($best->id) : null;
    }

    /** Goods received from the supplier and not yet billed: [grn line id => line + sku + supplier sku]. */
    private function openLines(Supplier $supplier, ?string $order = null): array
    {
        $out = [];
        $orderId = $order ? DB::table('purchase_orders')->where('supplier_id', $supplier->id)->where('number', $order)->value('id') : null;
        $grns = GoodsReceipt::where('supplier_id', $supplier->id)->where('status', 'posted')->orderBy('number')->get()
            ->sortBy(fn ($g) => $orderId && $g->purchase_order_id === $orderId ? 0 : 1); // the quoted order's deliveries first
        foreach ($grns as $grn) {
            foreach (app(BillService::class)->billable($grn) as $l) {
                if (Qty::toMilli((string) $l['open_qty']) > 0) {
                    $pid = DB::table('goods_receipt_lines')->where('id', $l['goods_receipt_line_id'])->value('product_id');
                    $out[$l['goods_receipt_line_id']] = $l + ['supplier_sku' => DB::table('supplier_products')->where('supplier_id', $supplier->id)->where('product_id', $pid)->value('supplier_sku')];
                }
            }
        }

        return $out;
    }

    private function match(array $open, string $sku, string $description): ?string
    {
        $sku = mb_strtolower(trim($sku));
        $description = mb_strtolower(trim($description));
        $best = null;
        $score = 0;
        foreach ($open as $id => $l) {
            if ((float) $l['open_qty'] <= 0) {
                continue;
            }
            if ($sku !== '' && in_array($sku, [mb_strtolower((string) $l['sku']), mb_strtolower((string) $l['supplier_sku'])], true)) {
                return $id;
            }
            similar_text($description, mb_strtolower($l['name']), $pct);
            if ($pct > $score) {
                [$best, $score] = [$id, $pct];
            }
        }

        return $score >= 55 ? $best : null;
    }

    public static function date(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Store an upload privately and return its path on the local disk. */
    public static function store(string $contents, string $extension): string
    {
        $path = 'ai-documents/'.Str::uuid7().'.'.strtolower($extension);
        Storage::disk('local')->put($path, $contents);

        return $path;
    }
}

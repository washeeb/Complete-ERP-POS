<?php

namespace App\Domain\Ai;

use App\Domain\Payables\BillService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\AiDocument;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Supplier invoice → draft bill. The AI reads the photo (or PDF, cloud model only) into supplier, number, dates and
 * lines; the lines are matched to goods received from that supplier and still to bill (by SKU, then by name); the rest
 * become expense lines. The result only pre-fills the bill form: a person checks every line and enters the bill, which
 * then goes through the normal price-matching and approval rules. Nothing is posted by the AI.
 */
final class InvoiceScanner
{
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(private readonly AiGateway $gateway, private readonly Authorizer $authz) {}

    /** $path: a file on the local (private) disk, already uploaded. */
    public function scan(Actor $actor, Branch $branch, string $path, string $fileName): AiDocument
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
        $doc->forceFill(['status' => 'read', 'extracted' => $data, 'form' => $this->form($actor, $branch, $data)])->save();

        return $doc;
    }

    /** The bill was entered from this scan. */
    public function used(AiDocument $doc, string $billId): void
    {
        $doc->forceFill(['status' => 'used', 'supplier_bill_id' => $billId])->save();
    }

    private function prompt(): string
    {
        return 'You read supplier invoices for a business in Ghana. Return only JSON with these keys: supplier_name, supplier_tax_no (TIN, or null), '
            .'invoice_number, invoice_date (YYYY-MM-DD), due_date (YYYY-MM-DD or null), currency (e.g. GHS), lines (a list of {description, sku (or null), '
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
    private function form(Actor $actor, Branch $branch, array $d): array
    {
        $currency = Money::exponent(DB::table('tenants')->where('id', $actor->tenantId)->value('currency_code') ?? 'GHS');
        $supplier = $this->supplier($d);
        $date = self::date($d['invoice_date'] ?? null);
        $form = ['supplier_id' => $supplier?->id, 'supplier_invoice_no' => mb_substr(trim((string) ($d['invoice_number'] ?? '')), 0, 60), 'branch_id' => $branch->id,
            'bill_date' => $date && $date <= now()->toDateString() ? $date : now()->toDateString(), 'due_on' => self::date($d['due_date'] ?? null),
            'note' => 'Read from a scanned invoice — check every line against the paper.', 'stock_lines' => [], 'expense_lines' => []];
        $open = $supplier ? $this->openLines($supplier) : [];
        foreach ((array) ($d['lines'] ?? []) as $l) {
            $qty = (float) ($l['qty'] ?? 0) ?: 1.0;
            $price = (float) ($l['unit_price'] ?? 0) ?: ((float) ($l['line_total'] ?? 0) / $qty);
            $match = $this->match($open, (string) ($l['sku'] ?? ''), (string) ($l['description'] ?? ''));
            if ($match) {
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

    private function supplier(array $d): ?Supplier
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
    private function openLines(Supplier $supplier): array
    {
        $out = [];
        foreach (GoodsReceipt::where('supplier_id', $supplier->id)->where('status', 'posted')->orderBy('number')->get() as $grn) {
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

    private static function date(?string $v): ?string
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

<?php

namespace App\Domain\Grocery;

use App\Domain\Inventory\InventoryService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Markdown;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockBatch;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Ean13;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Near-expiry markdowns (owner decision 2026-10-16: the system suggests, a manager applies). Each markdown is a
 * sticker with its own EAN-13 code ("29…" by default): the till charges the sticker price and the stock comes off that
 * batch. Expired batches are written off as shrink.
 */
final class MarkdownService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly Settings $settings,
        private readonly PriceResolver $prices, private readonly InventoryService $inventory) {}

    /** "7:30,3:50" → [[3, 5000], [7, 3000]] (days left, basis points), nearest expiry first. */
    public function rules(string $tenantId): array
    {
        $rules = [];
        foreach (explode(',', (string) $this->settings->get('grocery.markdown_rules', $tenantId)) as $r) {
            if (preg_match('/^\s*(\d+)\s*:\s*(\d{1,2})\s*$/', $r, $m)) {
                $rules[] = [(int) $m[1], (int) $m[2] * 100];
            }
        }
        usort($rules, fn ($a, $b) => $a[0] <=> $b[0]);

        return $rules;
    }

    /**
     * Batches in these warehouses expiring within the longest rule, not yet marked down, with the suggested percentage.
     *
     * @return list<object>
     */
    public function suggestions(string $tenantId, array $warehouseIds): array
    {
        $rules = $this->rules($tenantId);
        if (! $rules) {
            return [];
        }
        $today = now()->toDateString();
        $rows = DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->whereIn('l.warehouse_id', $warehouseIds)->where('l.on_hand', '>', 0)->whereNotNull('b.expires_on')
            ->whereBetween('b.expires_on', [$today, now()->addDays(end($rules)[0])->toDateString()])
            ->whereNotExists(fn ($q) => $q->from('markdowns as md')->whereColumn('md.batch_id', 'l.batch_id')->whereColumn('md.warehouse_id', 'l.warehouse_id')->where('md.status', 'active'))
            ->orderBy('b.expires_on')->get(['l.warehouse_id', 'w.name as warehouse', 'l.batch_id', 'b.batch_no', 'b.expires_on', 'l.on_hand', 'p.id as product_id', 'p.name', 'p.sku']);
        foreach ($rows as $r) {
            $r->days_left = (int) Carbon::parse($today)->diffInDays(Carbon::parse($r->expires_on));
            $r->suggested_bp = collect($rules)->first(fn ($rule) => $r->days_left <= $rule[0])[1] ?? end($rules)[1];
        }

        return $rows->all();
    }

    public function apply(Actor $actor, Warehouse $warehouse, string $batchId, int $percentBp, int $labels = 1): Markdown
    {
        $this->require($actor, 'grocery.markdowns', $warehouse);
        if ($percentBp <= 0 || $percentBp >= 10000) {
            throw new DomainException('Choose a markdown between 1 and 99 %.');
        }
        $batch = StockBatch::findOrFail($batchId);
        $product = Product::findOrFail($batch->product_id);
        if ($batch->expires_on && $batch->expires_on->lt(now()->startOfDay())) {
            throw new DomainException("Batch {$batch->batch_no} has expired: write it off instead of marking it down.");
        }
        $unit = ProductUom::where('product_id', $product->id)->where('is_base', true)->firstOrFail();
        $list = PriceList::where('is_default', true)->first() ?? throw new DomainException('There is no default price list.');
        $listPrice = $this->prices->resolve($list, $product->id, $unit->id, 1000, now())?->amount_minor
            ?? throw new DomainException("{$product->name} has no {$list->name} price.");
        $price = $listPrice - Qty::divRound($listPrice * $percentBp, 10000);

        return $this->recorder->transaction(function () use ($actor, $warehouse, $batch, $product, $unit, $percentBp, $labels, $listPrice, $price) {
            $md = (new Markdown)->forceFill(['tenant_id' => $actor->tenantId, 'code' => $this->newCode($actor->tenantId), 'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'batch_id' => $batch->id, 'percent_bp' => $percentBp,
                'list_price_minor' => $listPrice, 'price_minor' => $price, 'valid_until' => ($batch->expires_on ?? now()->addDays(7))->toDateString(),
                'labels' => max(1, $labels), 'status' => 'active', 'created_by_name' => $actor->name]);

            return $this->recorder->save($actor, $md, 'markdown.applied', branchId: $warehouse->branch_id);
        });
    }

    public function withdraw(Actor $actor, Markdown $md, string $reason): Markdown
    {
        $this->require($actor, 'grocery.markdowns', Warehouse::findOrFail($md->warehouse_id));
        $md->status = 'withdrawn';

        return $this->recorder->save($actor, $md, 'markdown.withdrawn', $reason, $md->branch_id);
    }

    /** An expired batch comes off the shelf: written off as shrink (adjustment, reason "expired"). */
    public function writeOffExpired(Actor $actor, Warehouse $warehouse, string $batchId): void
    {
        $batch = StockBatch::findOrFail($batchId);
        if (! $batch->expires_on || $batch->expires_on->gte(now()->startOfDay())) {
            throw new DomainException("Batch {$batch->batch_no} has not expired.");
        }
        $onHand = Qty::toMilli((string) DB::table('stock_batch_levels')->where('warehouse_id', $warehouse->id)->where('batch_id', $batch->id)->value('on_hand'));
        if ($onHand <= 0) {
            throw new DomainException('Nothing left of this batch here.');
        }
        $product = Product::findOrFail($batch->product_id);
        $unit = ProductUom::where('product_id', $product->id)->where('is_base', true)->firstOrFail();
        $this->inventory->adjust($actor, $warehouse, $product, $unit, Qty::format(-$onHand), 'adjustment', 'expired', "batch {$batch->batch_no}", null, $batch->batch_no);
        Markdown::where('batch_id', $batch->id)->where('warehouse_id', $warehouse->id)->where('status', 'active')->get()
            ->each(fn ($m) => $this->recorder->save($actor, $m->forceFill(['status' => 'withdrawn']), 'markdown.withdrawn', 'Batch expired', $m->branch_id));
    }

    /** Active stickers a branch's tills must recognise. */
    public static function activeFor(string $branchId): array
    {
        return Markdown::where('branch_id', $branchId)->where('status', 'active')->where('valid_until', '>=', now()->toDateString())
            ->get(['id', 'code', 'product_id', 'product_uom_id', 'price_minor', 'percent_bp', 'valid_until'])
            ->map(fn ($m) => ['id' => $m->id, 'code' => $m->code, 'product_id' => $m->product_id, 'product_uom_id' => $m->product_uom_id,
                'price_minor' => $m->price_minor, 'percent_bp' => $m->percent_bp, 'valid_until' => $m->valid_until->toDateString()])->all();
    }

    private function newCode(string $tenantId): string
    {
        $prefix = substr(preg_replace('/\D/', '', (string) $this->settings->get('grocery.markdown_prefix', $tenantId)), 0, 2) ?: '29';
        do {
            $code = Ean13::complete($prefix.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT));
        } while (Markdown::where('code', $code)->exists());

        return $code;
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
    }
}

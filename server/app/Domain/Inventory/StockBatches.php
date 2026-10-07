<?php

namespace App\Domain\Inventory;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Outbox;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Warehouse;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Batches (lots) with expiry dates for products that track them (owner decision 2026-10-16: sell the oldest expiry
 * first — FEFO; expired stock cannot be sold). A batch's id is derived from the company, product and batch number,
 * so the head office and branch servers always agree on it.
 */
final class StockBatches
{
    private const NAMESPACE = '6f5d2a4e-8c1b-4f7a-9e3d-2b1c0a9f8e7d';

    public function __construct(private readonly Outbox $outbox) {}

    public static function idFor(string $tenantId, string $productId, string $batchNo): string
    {
        return Uuid::uuid5(self::NAMESPACE, $tenantId.'|'.$productId.'|'.mb_strtoupper(trim($batchNo)))->toString();
    }

    /** Find or record a batch (inside the stock transaction). */
    public function ensure(Actor $actor, Product $product, string $batchNo, ?string $expiresOn): string
    {
        $batchNo = mb_strtoupper(trim($batchNo));
        if ($batchNo === '' || mb_strlen($batchNo) > 60) {
            throw new DomainException("{$product->name}: enter the batch number.");
        }
        $id = self::idFor($product->tenant_id, $product->id, $batchNo);
        $existing = StockBatch::withoutGlobalScopes()->find($id);
        if ($existing) {
            if ($expiresOn && $existing->expires_on && $existing->expires_on->toDateString() !== Carbon::parse($expiresOn)->toDateString()) {
                throw new DomainException("{$product->name}: batch {$batchNo} is already recorded with expiry ".$existing->expires_on->format('j M Y').'.');
            }

            return $id;
        }
        $b = (new StockBatch)->forceFill(['id' => $id, 'tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'batch_no' => $batchNo,
            'expires_on' => $expiresOn ? Carbon::parse($expiresOn)->toDateString() : null]);
        $b->save();
        $this->outbox->publish($actor, $b, 'stock_batch.created');

        return $id;
    }

    /** The batch new stock without its own batch joins: the one with the latest expiry already here (or an "unbatched" batch). */
    public function latest(Actor $actor, Warehouse $warehouse, Product $product): string
    {
        $id = DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.warehouse_id', $warehouse->id)->where('l.product_id', $product->id)
            ->orderByRaw('b.expires_on DESC NULLS LAST')->orderByDesc('b.created_at')->value('b.id');

        return $id ?? $this->ensure($actor, $product, 'UNBATCHED', null);
    }

    public static function today(): string
    {
        return now()->toDateString();
    }

    /**
     * Split an outgoing quantity (base units, thousandths) across batches, earliest expiry first.
     *
     * @param  bool  $sellable  sales and deliveries: expired batches are not used
     * @param  bool  $alreadyHappened  offline sale: the goods are gone — use expired stock too rather than refuse
     * @return list<array{0: string, 1: int}> [batch id, base qty]
     */
    public function fefo(Actor $actor, Warehouse $warehouse, Product $product, int $needBase, bool $sellable, bool $alreadyHappened): array
    {
        $rows = DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.warehouse_id', $warehouse->id)->where('l.product_id', $product->id)->where('l.on_hand', '>', 0)
            ->orderByRaw('b.expires_on ASC NULLS LAST')->orderBy('b.created_at')->lockForUpdate()
            ->get(['b.id', 'b.expires_on', 'l.on_hand']);
        $today = self::today();
        $expired = fn ($r) => $r->expires_on !== null && $r->expires_on < $today;
        $out = [];
        $take = function ($list) use (&$needBase, &$out) {
            foreach ($list as $r) {
                if ($needBase <= 0) {
                    return;
                }
                $q = min($needBase, Qty::toMilli((string) $r->on_hand));
                if ($q > 0) {
                    $out[] = [$r->id, $q];
                    $needBase -= $q;
                }
            }
        };
        $take($sellable ? $rows->reject($expired) : $rows);
        if ($needBase > 0 && $sellable) {
            $old = $rows->filter($expired);
            if ($old->isNotEmpty() && ! $alreadyHappened) {
                throw new DomainException("Only expired stock of {$product->name} is left here; it cannot be sold.", 'expired_stock');
            }
            $take($old);
        }
        if ($needBase > 0) {
            // More than recorded is going out (stock may go negative for sales): it comes off the newest batch.
            $last = $rows->reject($expired)->last() ?? $rows->last();
            $out[] = [$last->id ?? $this->latest($actor, $warehouse, $product), $needBase];
        }

        return $out;
    }

    /** Batches whose level disagrees with the ledger, and batch-tracked stock not fully in batches (should always be empty). */
    public static function check(?string $tenantId = null): array
    {
        $levels = DB::select(<<<'SQL'
            SELECT l.warehouse_id, l.batch_id, l.product_id, l.on_hand, COALESCE(SUM(m.qty_base), 0) AS ledger
            FROM stock_batch_levels l LEFT JOIN stock_movements m ON m.batch_id = l.batch_id AND m.warehouse_id = l.warehouse_id
            WHERE (?::uuid IS NULL OR l.tenant_id = ?::uuid)
            GROUP BY l.warehouse_id, l.batch_id, l.product_id, l.on_hand
            HAVING l.on_hand <> COALESCE(SUM(m.qty_base), 0)
        SQL, [$tenantId, $tenantId]);
        $unbatched = DB::select(<<<'SQL'
            SELECT s.warehouse_id, NULL AS batch_id, s.product_id, s.on_hand, COALESCE(SUM(l.on_hand), 0) AS ledger
            FROM stock_levels s JOIN products p ON p.id = s.product_id AND p.track_batches
            LEFT JOIN stock_batch_levels l ON l.warehouse_id = s.warehouse_id AND l.product_id = s.product_id
            WHERE (?::uuid IS NULL OR s.tenant_id = ?::uuid)
            GROUP BY s.warehouse_id, s.product_id, s.on_hand
            HAVING s.on_hand <> COALESCE(SUM(l.on_hand), 0)
        SQL, [$tenantId, $tenantId]);

        return array_merge($levels, $unbatched);
    }
}

<?php

namespace App\Domain\Purchasing;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\LandedCost;
use App\Models\LandedCostCharge;
use App\Models\LandedCostLine;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Landed costs: what it took to get goods to the shelf (freight, import duty, clearing, insurance) spread over the
 * deliveries it belongs to, by value (usual) or by quantity, to the pesewa.
 *
 * For each delivery line, the share for the part still in the warehouse raises the product's average cost; the share
 * for the part already sold goes to cost of sales (it is too late to put it in stock). The books then move the total
 * out of "Freight, duty and clearing to spread" (where the freight or customs bill was entered) into stock and cost of
 * sales. Posted once; a mistake is put right with another landed cost (a negative charge).
 */
final class LandedCostService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers) {}

    /**
     * @param  list<string>  $receiptIds  posted goods received notes, of one branch
     * @param  list<array{kind?: string, description: string, amount_minor: int}>  $charges
     */
    public function post(Actor $actor, array $receiptIds, array $charges, string $method = 'value', ?string $note = null): LandedCost
    {
        $receipts = GoodsReceipt::whereIn('id', $receiptIds)->get();
        if ($receipts->isEmpty()) {
            throw new DomainException('Choose the deliveries (goods received) the charges belong to.');
        }
        if ($receipts->contains(fn (GoodsReceipt $g) => $g->status !== 'posted')) {
            throw new DomainException('Only posted deliveries can take landed costs.');
        }
        if ($receipts->pluck('branch_id')->unique()->count() > 1) {
            throw new DomainException('Choose deliveries of one branch at a time.');
        }
        $branch = Branch::findOrFail($receipts->first()->branch_id);
        if (! $actor->user || ! $this->authz->allows($actor->user, 'payables.manage', $branch)) {
            throw new AccessDenied;
        }
        if (! array_key_exists($method, LandedCost::METHODS)) {
            throw new DomainException('Choose how to spread the charges.');
        }
        $charges = array_values(array_filter($charges, fn ($c) => (int) ($c['amount_minor'] ?? 0) !== 0));
        if ($charges === []) {
            throw new DomainException('Enter at least one charge.');
        }
        $total = array_sum(array_map(fn ($c) => (int) $c['amount_minor'], $charges));
        if ($total === 0) {
            throw new DomainException('The charges add up to nothing.');
        }
        $lines = DB::table('goods_receipt_lines')->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->whereIn('goods_receipt_lines.goods_receipt_id', $receipts->pluck('id'))
            ->get(['goods_receipt_lines.*', 'goods_receipts.warehouse_id']);
        $weights = $lines->mapWithKeys(fn ($l) => [$l->id => $method === 'value' ? (int) $l->net_minor : Qty::toMilli((string) $l->qty) * (int) round((float) $l->factor * 1000)]);
        if ($weights->sum() <= 0) {
            throw new DomainException($method === 'value' ? 'These deliveries have no value to spread over: spread by quantity instead.' : 'These deliveries have no quantity.');
        }
        $shares = self::spread($total, $weights->all());

        return $this->recorder->transaction(function () use ($actor, $branch, $charges, $method, $note, $total, $lines, $shares) {
            $doc = new LandedCost;
            $doc->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'landed_cost'), 'branch_id' => $branch->id,
                'method' => $method, 'total_minor' => $total, 'to_stock_minor' => 0, 'to_cogs_minor' => 0, 'currency_code' => Tenant::find($actor->tenantId)->currency_code,
                'note' => $note ? mb_substr(trim($note), 0, 255) : null, 'created_by' => $actor->id, 'created_by_name' => $actor->name, 'posted_at' => now()]);
            $doc->save();
            foreach ($charges as $c) {
                (new LandedCostCharge)->forceFill(['landed_cost_id' => $doc->id, 'kind' => array_key_exists($c['kind'] ?? '', LandedCost::KINDS) ? $c['kind'] : 'other',
                    'description' => mb_substr(trim((string) ($c['description'] ?? '')) ?: 'Charge', 0, 255), 'amount_minor' => (int) $c['amount_minor']])->save();
            }
            $toStock = 0;
            foreach ($lines as $l) {
                $alloc = $shares[$l->id] ?? 0;
                if ($alloc === 0) {
                    continue;
                }
                $level = DB::table('stock_levels')->where('warehouse_id', $l->warehouse_id)->where('product_id', $l->product_id)->lockForUpdate()->first();
                $onHand = $level ? Qty::toMilli((string) $level->on_hand) : 0;
                $received = intdiv(Qty::toMilli((string) $l->qty) * (int) round((float) $l->factor * 1000), 1000); // in base units (milli)
                $remaining = (int) max(0, min($received, $onHand));
                $stock = $received > 0 ? Qty::divRound($alloc * $remaining, (int) $received) : 0;
                $avgBefore = $level?->avg_cost_minor === null ? null : (string) $level->avg_cost_minor;
                $avgAfter = $avgBefore;
                if ($stock !== 0 && $onHand > 0) {
                    // The share for what is still here, over everything of this product here: per base unit, in minor units.
                    $avgAfter = bcadd($avgBefore ?? '0', bcdiv((string) ($stock * 1000), (string) $onHand, 4), 4);
                    DB::table('stock_levels')->where('warehouse_id', $l->warehouse_id)->where('product_id', $l->product_id)->update(['avg_cost_minor' => $avgAfter, 'updated_at' => now()]);
                }
                $toStock += $stock;
                (new LandedCostLine)->forceFill(['landed_cost_id' => $doc->id, 'goods_receipt_id' => $l->goods_receipt_id, 'goods_receipt_line_id' => $l->id,
                    'product_id' => $l->product_id, 'warehouse_id' => $l->warehouse_id, 'allocated_minor' => $alloc, 'to_stock_minor' => $stock,
                    'to_cogs_minor' => $alloc - $stock, 'avg_before' => $avgBefore, 'avg_after' => $avgAfter])->save();
            }
            $doc->forceFill(['to_stock_minor' => $toStock, 'to_cogs_minor' => $total - $toStock]);

            return $this->recorder->save($actor, $doc, 'landed_cost.posted', "{$doc->number}: ".$charges[0]['description'], $branch->id);
        });
    }

    /**
     * Split $total over $weights so the parts add up exactly (largest remainder).
     *
     * @param  array<string, int>  $weights
     * @return array<string, int>
     */
    public static function spread(int $total, array $weights): array
    {
        if ($total < 0) { // a correction: spread the amount, then turn it negative
            return array_map(fn (int $v) => -$v, self::spread(-$total, $weights));
        }
        $sum = array_sum($weights);
        $out = [];
        $rest = [];
        foreach ($weights as $k => $w) {
            $exact = $total * $w / $sum;
            $out[$k] = (int) floor($exact);
            $rest[$k] = $exact - $out[$k];
        }
        arsort($rest);
        $left = $total - array_sum($out);
        foreach (array_keys($rest) as $k) {
            if ($left <= 0) {
                break;
            }
            $out[$k]++;
            $left--;
        }

        return $out;
    }
}

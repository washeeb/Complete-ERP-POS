<?php

namespace App\Domain\Purchasing;

use App\Domain\Analytics\DemandForecaster;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Minimum and maximum worked out from each store's sales (the demand forecast: the last 12 weeks, weekday and payday
 * patterns, the supplier's lead time and a safety margin for about 95 % of days). Recalculated weekly. Setting
 * stock.smart_levels: "suggest" (default) lists them on Purchasing → Suggested levels to accept; "auto" applies them
 * itself, but never over a product's own figure; "off" does nothing.
 */
final class SmartReorderLevels
{
    /** A suggestion differing from the level in use by less than this share is not worth showing or applying. */
    private const MIN_CHANGE = 0.2;

    public function __construct(private readonly DemandForecaster $forecaster, private readonly ReorderLevels $levels, private readonly ReorderService $reorder,
        private readonly Settings $settings, private readonly Authorizer $authz) {}

    /**
     * @param  list<array<string, mixed>>|null  $forecast  DemandForecaster rows (null = work them out now)
     * @return array{suggested: int, applied: int}
     */
    public function recalculate(Warehouse $warehouse, ?array $forecast = null): array
    {
        $mode = (string) $this->settings->get('stock.smart_levels', $warehouse->tenant_id);
        if ($mode === 'off') {
            DB::table('reorder_level_suggestions')->where('warehouse_id', $warehouse->id)->delete();

            return ['suggested' => 0, 'applied' => 0];
        }
        $rows = [];
        foreach ($forecast ?? $this->forecaster->forWarehouse($warehouse->id) as $f) {
            if ($f['avg_daily'] <= 0) {
                continue;
            }
            $min = Qty::toMilli((string) max(1, (int) ceil($f['suggested_min'])));
            $max = max(Qty::toMilli((string) (int) ceil($f['suggested_max'])), $min + 1000);
            $rows[$f['product_id']] = ['id' => (string) Str::uuid7(), 'tenant_id' => $warehouse->tenant_id, 'warehouse_id' => $warehouse->id, 'product_id' => $f['product_id'],
                'min_qty' => Qty::format($min), 'max_qty' => Qty::format($max), 'avg_daily' => number_format((float) $f['avg_daily'], 3, '.', ''),
                'lead_days' => (int) $f['lead_days'], 'computed_at' => now()];
        }
        $current = $this->levels->forWarehouse($warehouse, array_keys($rows));
        $rows = array_filter($rows, fn ($r) => self::differs($current[$r['product_id']] ?? null, Qty::toMilli($r['min_qty'])));

        DB::transaction(function () use ($warehouse, $rows) {
            DB::table('reorder_level_suggestions')->where('warehouse_id', $warehouse->id)->delete(); // a working list, rebuilt each week
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                DB::table('reorder_level_suggestions')->insert($chunk);
            }
        });
        $applied = 0;
        if ($mode === 'auto') {
            $actor = Actor::system($warehouse->tenant_id, 'Smart reorder levels', 'system');
            foreach ($rows as $productId => $r) {
                if (($current[$productId]['source'] ?? null) !== 'own') {
                    $this->reorder->store($actor, $warehouse, $productId, $r['min_qty'], $r['max_qty'], 'smart');
                    DB::table('reorder_level_suggestions')->where('id', $r['id'])->delete();
                    $applied++;
                }
            }
        }

        return ['suggested' => count($rows) - $applied, 'applied' => $applied];
    }

    /**
     * Accept suggestions (ids): each becomes that product's level in the store, replacing an own figure too.
     *
     * @param  list<string>  $ids
     */
    public function accept(Actor $actor, array $ids): int
    {
        $rows = DB::table('reorder_level_suggestions')->whereIn('id', $ids)->where('tenant_id', $actor->tenantId)->get();
        if ($rows->isEmpty()) {
            throw new DomainException('Tick the suggestions to accept.');
        }
        foreach ($rows->groupBy('warehouse_id') as $warehouseId => $list) {
            $warehouse = Warehouse::findOrFail($warehouseId);
            if (! $actor->user || ! $this->authz->allowsAny($actor->user, ['warehouse.manage', 'stock.levels'], $warehouse)) {
                throw new AccessDenied;
            }
        }

        return DB::transaction(function () use ($actor, $rows) {
            foreach ($rows as $r) {
                $this->reorder->store($actor, Warehouse::findOrFail($r->warehouse_id), $r->product_id, (string) $r->min_qty, (string) $r->max_qty, 'smart');
            }
            DB::table('reorder_level_suggestions')->whereIn('id', $rows->pluck('id'))->delete();

            return $rows->count();
        });
    }

    private static function differs(?array $current, int $min): bool
    {
        if ($current === null || $current['min'] === 0) {
            return true;
        }

        return abs($min - $current['min']) / $current['min'] >= self::MIN_CHANGE;
    }
}

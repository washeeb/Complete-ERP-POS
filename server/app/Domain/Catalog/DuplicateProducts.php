<?php

namespace App\Domain\Catalog;

use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\Recorder;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cleans up products created twice by importing the same "create" file more than once. Copies are matched by name
 * (spaces and case ignored), industry and base unit, and by import run: the copies from the earliest run (or added by
 * hand) are kept; each later run's copies are folded into them in the same order, so two genuinely different products
 * with the same name in one file stay two products.
 *
 * A later copy gives its barcodes to the kept product (same unit), then is removed when nothing refers to it (no sale,
 * stock, order or other record). A copy that is in use is switched off instead, and on a company with branch servers
 * copies are only switched off, so every server keeps the same products. Everything is in the audit log.
 */
final class DuplicateProducts
{
    /** Rows that go with the product itself; anything else referring to it (or its units) means it is in use. */
    private const OWN_COLUMNS = ['product_uoms.product_id', 'barcodes.product_id', 'barcodes.product_uom_id', 'prices.product_id', 'prices.product_uom_id'];

    public function __construct(private readonly Recorder $recorder, private readonly AuditLog $audit) {}

    /**
     * What a clean-up would do: kept product => its later copies.
     *
     * @return list<array{keep: Product, copies: list<Product>}>
     */
    public function plan(string $tenantId): array
    {
        $imported = DB::table('audit_events')->where('tenant_id', $tenantId)->where('subject_type', 'product')->where('action', 'product.created')
            ->where('reason', 'CSV import')->pluck('subject_id')->flip();
        $runs = DB::table('data_imports')->where('tenant_id', $tenantId)->where('type', 'products')->where('status', 'imported')
            ->orderBy('completed_at')->pluck('completed_at')->map(fn ($t) => (string) $t)->all();
        $runOf = function (Product $p) use ($imported, $runs): int {
            if (! $imported->has($p->id)) {
                return -1; // added by hand: always kept
            }
            foreach ($runs as $i => $completed) {
                if ((string) $p->created_at <= $completed) {
                    return $i;
                }
            }

            return count($runs);
        };

        $plan = [];
        Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('created_at')->orderBy('sku')->get()
            ->groupBy(fn (Product $p) => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $p->name))).'|'.$p->industry_id.'|'.$p->base_uom_id)
            ->each(function (Collection $group) use (&$plan, $runOf) {
                $byRun = $group->groupBy($runOf)->sortKeys();
                if ($byRun->count() < 2) {
                    return;
                }
                $keepers = $byRun->first()->values();
                $kept = [];
                foreach ($byRun->slice(1) as $run => $copies) {
                    if ($run < 0) {
                        continue;
                    }
                    foreach ($copies->values() as $i => $copy) {
                        $keep = $keepers[min($i, $keepers->count() - 1)];
                        $kept[$keep->id] ??= ['keep' => $keep, 'copies' => []];
                        $kept[$keep->id]['copies'][] = $copy;
                    }
                }
                array_push($plan, ...array_values($kept));
            });

        return $plan;
    }

    /**
     * Fold the copies into the kept products.
     *
     * @return array{groups: int, removed: int, switched_off: int, barcodes_moved: int}
     */
    public function apply(string $tenantId, array $plan): array
    {
        $actor = Actor::system($tenantId, 'Duplicate products clean-up', 'cli');
        $onlySwitchOff = DB::table('sync_peers')->exists(); // branch servers must keep the same products
        $totals = ['groups' => 0, 'removed' => 0, 'switched_off' => 0, 'barcodes_moved' => 0];
        foreach ($plan as ['keep' => $keep, 'copies' => $copies]) {
            DB::transaction(function () use ($actor, $keep, $copies, $onlySwitchOff, &$totals) {
                $removed = [];
                $off = [];
                $moved = [];
                foreach ($copies as $copy) {
                    foreach (DB::table('product_uoms')->where('product_id', $copy->id)->get() as $unit) {
                        $target = DB::table('product_uoms')->where('product_id', $keep->id)->where('uom_id', $unit->uom_id)->value('id');
                        if ($target) {
                            $codes = DB::table('barcodes')->where('product_uom_id', $unit->id)->pluck('code')->all();
                            DB::table('barcodes')->where('product_uom_id', $unit->id)->update(['product_uom_id' => $target, 'product_id' => $keep->id]);
                            array_push($moved, ...$codes);
                        }
                    }
                    if (! $onlySwitchOff && ! $this->inUse($copy)) {
                        DB::table('prices')->where('product_id', $copy->id)->delete();
                        DB::table('barcodes')->where('product_id', $copy->id)->delete();
                        DB::table('product_uoms')->where('product_id', $copy->id)->delete();
                        $this->audit->record($actor, 'product.deleted', $copy, ['sku' => [$copy->sku, null], 'name' => [$copy->name, null]], "Duplicate of {$keep->sku}");
                        DB::table('products')->where('id', $copy->id)->delete();
                        $removed[] = $copy->sku;
                    } elseif ($copy->is_active) {
                        $copy->is_active = false;
                        $this->recorder->save($actor, $copy, 'product.updated', "Duplicate of {$keep->sku}; in use, so switched off rather than removed");
                        $off[] = $copy->sku;
                    }
                }
                $this->audit->record($actor, 'product.duplicates_merged', $keep, array_filter(['removed' => [null, $removed], 'switched_off' => [null, $off],
                    'barcodes_moved' => [null, $moved]], fn ($c) => $c[1] !== []), 'Products imported more than once');
                $totals['groups']++;
                $totals['removed'] += count($removed);
                $totals['switched_off'] += count($off);
                $totals['barcodes_moved'] += count($moved);
            });
        }

        return $totals;
    }

    /** Anything besides the product's own units, barcodes and prices refers to it (sales, stock, orders, recipes …). */
    private function inUse(Product $product): bool
    {
        static $refs = null;
        $refs ??= collect(DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tbl, a.attname AS col, c.confrelid::regclass::text AS target
            FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.confrelid IN ('products'::regclass, 'product_uoms'::regclass)
            SQL));
        $units = DB::table('product_uoms')->where('product_id', $product->id)->pluck('id')->all();
        foreach ($refs as $r) {
            $table = preg_replace('/^public\./', '', $r->tbl);
            if (in_array("{$table}.{$r->col}", self::OWN_COLUMNS, true)) {
                continue;
            }
            $ids = $r->target === 'products' ? [$product->id] : $units;
            if ($ids && DB::table($table)->whereIn($r->col, $ids)->exists()) {
                return true;
            }
        }

        return false;
    }
}

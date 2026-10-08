<?php

namespace App\Domain\Inventory;

use App\Domain\Support\DomainException;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Serial / IMEI numbers (Electronics, owner decision 2026-10-19): for products that track them, every unit is received,
 * moved and sold by its number, inside the stock ledger's transaction. A serial's id is derived from the company,
 * product and number, so head office and branch servers agree on it.
 *
 * Numbers are required where a person handles the unit (receipts, opening stock, adjustments, transfers, till sales and
 * returns, trade-ins). Where the unit cannot be named (counts, deliveries, online orders, offline sales typed without
 * one) the movement is kept and the gap is reported, so the serial register can be put right.
 */
final class SerialRegistry
{
    private const NAMESPACE = '0e8d9a3b-6c4f-4b2a-9d1e-5f7a8c2b3e41';

    public const REQUIRED = ['receipt', 'opening', 'adjustment', 'sale', 'sale_return', 'transfer_out', 'transfer_in', 'trade_in'];

    /** Status a serial takes when it leaves the warehouse this way. */
    private const OUT = ['sale' => 'sold', 'delivery' => 'sold', 'transfer_out' => 'in_transit', 'receipt_reversal' => 'returned_to_supplier'];

    /** @var list<string> problems met while posting already-happened (offline) work */
    private array $anomalies = [];

    /** @var array{rows: array<string, array>, links: list<array>} what changed, for the outbox (other nodes) */
    private array $changes = ['rows' => [], 'links' => []];

    public static function idFor(string $tenantId, string $productId, string $serial): string
    {
        return Uuid::uuid5(self::NAMESPACE, $tenantId.'|'.$productId.'|'.$serial)->toString();
    }

    /** " 3567 8901-2345, ab12 " → ["356789012345", "AB12"]: upper case, no spaces or dashes. */
    public static function normalise(array|string|null $list): array
    {
        $items = is_array($list) ? $list : preg_split('/[\s,;]+/', (string) $list);

        return array_values(array_unique(array_filter(array_map(fn ($s) => mb_substr(strtoupper(preg_replace('/[\s\-]+/', '', (string) $s)), 0, 80), $items))));
    }

    public function begin(): void
    {
        $this->anomalies = [];
        $this->changes = ['rows' => [], 'links' => []];
    }

    /** @return list<string> */
    public function anomalies(): array
    {
        return $this->anomalies;
    }

    public function changes(): array
    {
        return ['rows' => array_values($this->changes['rows']), 'links' => $this->changes['links']];
    }

    /**
     * Record which units a ledger entry moved.
     *
     * @param  array{product: Product, unit: mixed, qty_milli: int, serials?: array|string|null, customer_id?: ?string, sale_id?: ?string}  $e
     */
    public function apply(Warehouse $warehouse, string $type, array $e, string $movementId, bool $alreadyHappened, $occurredAt): void
    {
        $product = $e['product'];
        if (! $product->track_serials || in_array($type, ['putaway', 'batch_assign'], true)) {
            return;
        }
        $base = Qty::toBaseMilli(abs($e['qty_milli']), Qty::factorMicro($e['unit']->factor));
        if ($base % 1000 !== 0) {
            throw new DomainException("{$product->name} is counted in whole units (each has its own serial number).", 'serial_required');
        }
        $n = intdiv($base, 1000);
        $serials = self::normalise($e['serials'] ?? []);
        if ($serials === []) {
            if (in_array($type, self::REQUIRED, true) && ! $alreadyHappened) {
                throw new DomainException("{$product->name}: scan or type the serial number of each unit ({$n}).", 'serial_required');
            }
            $this->anomalies[] = "{$product->name}: {$n} unit(s) moved without serial numbers ({$type})";

            return;
        }
        if (count($serials) !== $n) {
            if (! $alreadyHappened) {
                throw new DomainException("{$product->name}: {$n} unit(s) but ".count($serials).' serial number(s).', 'serial_required');
            }
            $this->anomalies[] = "{$product->name}: {$n} unit(s) but ".count($serials).' serial number(s)';
        }

        $incoming = $e['qty_milli'] > 0;
        foreach ($serials as $s) {
            $id = self::idFor($product->tenant_id, $product->id, $s);
            $row = DB::table('serial_numbers')->where('id', $id)->lockForUpdate()->first();
            $problem = null;
            if ($incoming && $row && $row->status === 'in_stock' && $row->warehouse_id !== null) {
                $problem = "{$product->name} {$s} is already in stock";
            } elseif (! $incoming && (! $row || $row->status !== 'in_stock' || $row->warehouse_id !== $warehouse->id)) {
                $problem = "{$product->name} {$s} is not in stock at {$warehouse->name}";
            }
            if ($problem) {
                if (! $alreadyHappened) {
                    throw new DomainException("{$problem}.", 'serial_conflict');
                }
                $this->anomalies[] = $problem;
            }

            $values = $incoming
                ? ['status' => 'in_stock', 'warehouse_id' => $warehouse->id]
                : ['status' => self::OUT[$type] ?? 'written_off', 'warehouse_id' => null];
            if (! $incoming && in_array($type, ['sale', 'delivery'], true)) {
                $values += ['customer_id' => $e['customer_id'] ?? null, 'sale_id' => $e['sale_id'] ?? null, 'sold_at' => $occurredAt,
                    'warranty_until' => $product->warranty_months ? $occurredAt->copy()->addMonths($product->warranty_months)->toDateString() : null];
            }
            $now = now();
            if ($row) {
                DB::table('serial_numbers')->where('id', $id)->update($values + ['version' => $row->version + 1, 'updated_at' => $now]);
            } else {
                DB::table('serial_numbers')->insert($values + ['id' => $id, 'tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'serial' => $s,
                    'origin_node' => (string) config('erp.node.id'), 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('stock_movement_serials')->insertOrIgnore(['stock_movement_id' => $movementId, 'serial_number_id' => $id]);
            $this->changes['rows'][$id] = (array) DB::table('serial_numbers')->where('id', $id)->first();
            $this->changes['links'][] = ['stock_movement_id' => $movementId, 'serial_number_id' => $id];
        }
    }

    /** The serial numbers a movement moved. */
    public static function ofMovement(string $movementId): array
    {
        return DB::table('stock_movement_serials as l')->join('serial_numbers as s', 's.id', '=', 'l.serial_number_id')
            ->where('l.stock_movement_id', $movementId)->orderBy('s.serial')->pluck('s.serial')->all();
    }

    /** Apply serial changes received from another node (after their movements). */
    public static function applyRemote(array $changes): void
    {
        foreach ($changes['rows'] ?? [] as $r) {
            $local = DB::table('serial_numbers')->where('id', $r['id'])->first();
            if (! $local) {
                DB::table('serial_numbers')->insert($r);
            } elseif ((int) $r['version'] >= (int) $local->version) {
                DB::table('serial_numbers')->where('id', $r['id'])->update($r);
            }
        }
        foreach ($changes['links'] ?? [] as $l) {
            DB::table('stock_movement_serials')->insertOrIgnore($l);
        }
    }

    /** Products whose serials in stock do not match the quantity on hand, per warehouse (should be empty). */
    public static function check(?string $tenantId = null): array
    {
        return DB::select(<<<'SQL'
            SELECT s.warehouse_id, s.product_id, p.name, s.on_hand, COALESCE(c.n, 0) AS serials
            FROM stock_levels s JOIN products p ON p.id = s.product_id AND p.track_serials
            LEFT JOIN (SELECT warehouse_id, product_id, COUNT(*) AS n FROM serial_numbers WHERE status = 'in_stock' GROUP BY 1, 2) c
              ON c.warehouse_id = s.warehouse_id AND c.product_id = s.product_id
            WHERE (?::uuid IS NULL OR s.tenant_id = ?::uuid) AND s.on_hand <> COALESCE(c.n, 0)
        SQL, [$tenantId, $tenantId]);
    }
}

<?php

namespace App\Domain\Sync;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\Loyalty;
use App\Domain\Customers\StoredValues;
use App\Domain\Hr\AttendanceService;
use App\Domain\Inventory\SerialRegistry;
use App\Domain\Inventory\StockProjection;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Models\Customer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applies one event from another node to this node's database. Every path is idempotent:
 * rows are upserted by id (a newer version wins; equal versions are a no-op), immutable rows
 * (stock movements, sale lines, payments) are inserted once, and the stock projection moves
 * only for movements actually inserted. Runs inside the caller's transaction and writes a
 * local audit row naming the origin node — sync never pretends to be a person.
 */
final class ReplicationApplier
{
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(private readonly AuditLog $audit, private readonly ReviewQueue $reviews, private readonly StockProjection $projection, private readonly CustomerLedger $customers) {}

    public function apply(array $event): void
    {
        LicenseManager::bypass(fn () => $this->applyOne($event)); // receiving sync is never blocked: it only brings what was already recorded
    }

    private function applyOne(array $event): void
    {
        $type = $event['aggregate_type'];
        $payload = is_string($event['payload']) ? json_decode($event['payload'], true) : $event['payload'];
        $actor = Actor::system($event['tenant_id'], 'Sync from '.$event['origin_node'], 'sync');

        if ($type === 'stock_movement_batch') {
            $this->applyMovements($actor, $payload['movements'] ?? []);
            if (! empty($payload['serials'])) {
                SerialRegistry::applyRemote($payload['serials']); // which units moved (serial-numbered products)
            }
        } elseif ($type === 'serial_batch') {
            SerialRegistry::applyRemote($payload['serials'] ?? []); // serials registered for stock already on hand
        } elseif ($type === 'customer_ledger_batch') {
            $this->customers->apply(array_map(fn ($r) => array_intersect_key($r, array_flip($this->columnsOf('customer_ledger'))), $payload['entries'] ?? []),
                array_map(fn ($r) => array_intersect_key($r, array_flip($this->columnsOf('receivable_allocations'))), $payload['allocations'] ?? []));
        } elseif ($type === 'loyalty_batch') {
            app(Loyalty::class)->apply(array_map(fn ($r) => array_intersect_key($r, array_flip($this->columnsOf('loyalty_ledger'))), $payload['entries'] ?? []));
        } elseif ($type === 'attendance_batch') {
            app(AttendanceService::class)->apply(array_map(fn ($r) => array_intersect_key($r, array_flip($this->columnsOf('attendance_events'))), $payload['events'] ?? []));
        } elseif ($type === 'stored_value_batch') {
            app(StoredValues::class)->apply(array_map(fn ($r) => array_intersect_key($r, array_flip($this->columnsOf('stored_value_ledger'))), $payload['entries'] ?? []),
                $payload['touch'] ?? []);
        } elseif ($type === 'customer') {
            $this->mergeCustomer($actor, $payload['snapshot']['row'] ?? throw new DomainException('Event has no snapshot.', 'malformed'), $event['origin_node']);
        } else {
            $table = Replication::tableOf($type) ?? throw new DomainException("This node does not know how to apply “{$type}”. Update it to the same version as head office.", 'unknown_type');
            $snapshot = $payload['snapshot'] ?? throw new DomainException('Event has no snapshot.', 'malformed');
            $this->upsert($table, $snapshot['row']);
            $this->children($type, $snapshot);
        }

        $this->audit->recordRef($actor, 'sync.'.$event['event_type'], $type, $event['aggregate_id'], [],
            "Applied event {$event['id']} (#{$event['origin_seq']} from {$event['origin_node']})");
    }

    private function upsert(string $table, array $row): void
    {
        $row = array_intersect_key($row, array_flip($this->columnsOf($table)));
        $existing = DB::table($table)->where('id', $row['id'])->lockForUpdate()->first();
        if (! $existing) {
            DB::table($table)->insert($row);

            return;
        }
        if (isset($row['version'], $existing->version) && (int) $row['version'] < (int) $existing->version) {
            return; // we already have a newer state
        }
        DB::table($table)->where('id', $row['id'])->update($row);
    }

    private function children(string $type, array $snapshot): void
    {
        $c = $snapshot['children'] ?? [];
        $id = $snapshot['row']['id'];
        switch ($type) {
            case 'product':
                $this->replaceSet('product_uoms', 'product_id', $id, $c['product_uoms'] ?? [], deleteMissing: false); // units with history stay
                $this->replaceSet('barcodes', 'product_id', $id, $c['barcodes'] ?? []);
                $this->replaceSet('prices', 'product_id', $id, $c['prices'] ?? []);
                if (array_key_exists('recipe_lines', $c)) {
                    $this->replaceSet('recipe_lines', 'product_id', $id, $c['recipe_lines']);
                }
                break;
            case 'role':
                $keys = $c['permission_keys'] ?? [];
                $unknown = array_diff($keys, DB::table('permissions')->whereIn('key', $keys)->pluck('key')->all());
                if ($unknown) {
                    throw new DomainException('Permissions unknown on this node ('.implode(', ', $unknown).'). Update this node to the same version as head office.', 'version_mismatch');
                }
                DB::table('permission_role')->where('role_id', $id)->delete();
                DB::table('permission_role')->insert(array_map(fn ($k) => ['role_id' => $id, 'permission_key' => $k], $keys));
                break;
            case 'tax_group':
                DB::table('tax_group_rates')->where('tax_group_id', $id)->delete();
                DB::table('tax_group_rates')->insert(array_map(fn ($r) => ['tax_group_id' => $id, 'tax_rate_id' => $r], $c['tax_rate_ids'] ?? []));
                break;
            case 'customer_order':
            case 'delivery':
            case 'invoice':
            case 'supplier':
            case 'purchase_order':
            case 'goods_receipt':
            case 'stock_transfer':
            case 'stock_count':
            case 'modifier_group':
            case 'dining_order':
            case 'repair_job':
                $table = array_key_first($c);
                if ($table) {
                    $this->replaceSet($table, "{$type}_id", $id, $c[$table]); // lines belong to their document
                }
                break;
            case 'sale':
                foreach (['sale_lines' => $c['sale_lines'] ?? [], 'sale_payments' => $c['sale_payments'] ?? []] as $table => $rows) {
                    if ($rows) {
                        DB::table($table)->insertOrIgnore(array_map(fn ($r) => array_intersect_key($r, array_flip($this->columnsOf($table))), $rows));
                    }
                }
                break;
        }
    }

    /**
     * Customers merge field by field: for each field the later change wins (time, then node id), so two branches editing
     * different fields both keep their edits. Each stamp also records the stamp its editor had seen ("base"); when the
     * other side's change was not built on ours, the same field was edited in two places at once, and the losing value is
     * logged for review. Credit fields are head office's: changes to them coming from a branch are ignored at HQ.
     */
    private function mergeCustomer(Actor $actor, array $in, string $originNode): void
    {
        $in = array_intersect_key($in, array_flip($this->columnsOf('customers')));
        $local = DB::table('customers')->where('id', $in['id'])->lockForUpdate()->first();
        if (! $local) {
            DB::table('customers')->insert($in);

            return;
        }
        $inStamps = is_string($in['field_stamps'] ?? null) ? json_decode($in['field_stamps'], true) : ($in['field_stamps'] ?? []);
        $loStamps = json_decode($local->field_stamps ?? '{}', true) ?: [];
        $hqHere = config('erp.node.role') !== 'branch';
        $update = [];
        $conflicts = [];
        foreach (Customer::MERGED_FIELDS as $f) {
            if (! array_key_exists($f, $in) || ($hqHere && in_array($f, Customer::CREDIT_FIELDS, true))) {
                continue; // credit is set at HQ only (sync never applies it here)
            }
            $a = (string) ($inStamps[$f]['at'] ?? '');
            $b = (string) ($loStamps[$f]['at'] ?? '');
            if ($a === $b) {
                continue;
            }
            $same = json_encode($in[$f]) === json_encode($local->{$f}) || (string) $in[$f] === (string) $local->{$f};
            if ($a > $b) {
                $update[$f] = $in[$f];
                $loStamps[$f] = $inStamps[$f];
                if (! $same && $b !== '' && ($inStamps[$f]['base'] ?? '') !== $b) {
                    $conflicts[] = "{$f} — kept ".json_encode($in[$f]).' (later), replaced '.json_encode($local->{$f});
                }
            } elseif (! $same && $a !== '' && ($loStamps[$f]['base'] ?? '') !== $a) {
                $conflicts[] = "{$f} — kept ".json_encode($local->{$f}).' (later), did not apply '.json_encode($in[$f]).' from '.$originNode;
            }
        }
        if ($update) {
            $update['field_stamps'] = json_encode($loStamps);
            $update['version'] = max((int) $local->version, (int) ($in['version'] ?? 1));
            $update['updated_at'] = now();
            DB::table('customers')->where('id', $in['id'])->update($update);
        }
        if ($conflicts) {
            $this->reviews->add($actor, 'customer_conflict', 'customer', $in['id'], "Customer {$local->name} was edited on two servers at once: ".implode('; ', $conflicts),
                ['from' => $originNode], $local->home_branch_id);
        }
    }

    private function replaceSet(string $table, string $fk, string $parentId, array $rows, bool $deleteMissing = true): void
    {
        $ids = array_column($rows, 'id');
        if ($deleteMissing) {
            DB::table($table)->where($fk, $parentId)->when($ids, fn ($q) => $q->whereNotIn('id', $ids))->delete();
        }
        foreach ($rows as $r) {
            $this->upsert($table, $r);
        }
    }

    private function applyMovements(Actor $actor, array $movements): void
    {
        $touched = [];
        foreach ($movements as $m) {
            $m = array_intersect_key($m, array_flip($this->columnsOf('stock_movements')));
            $inserted = DB::table('stock_movements')->insertOrIgnore($m);
            if ($inserted === 0) {
                continue; // already have this movement: the projection already includes it
            }
            $this->projection->apply([$m]);
            $after = Qty::toMilli((string) DB::table('stock_levels')->where('warehouse_id', $m['warehouse_id'])->where('product_id', $m['product_id'])->value('on_hand'));
            $touched[$m['warehouse_id'].'|'.$m['product_id']] = [$m, $after];
        }
        foreach ($touched as [$m, $after]) {
            if ($after < 0) {
                $name = DB::table('products')->where('id', $m['product_id'])->value('name');
                $wh = DB::table('warehouses')->where('id', $m['warehouse_id'])->value('code');
                $this->reviews->add($actor, 'negative_stock', 'product', $m['product_id'],
                    "{$name} is at ".Qty::format($after)." in {$wh} after merging movements from {$m['origin_node']} — count it",
                    ['movement_id' => $m['id']], $m['branch_id']);
            }
        }
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumnListing($table);
    }
}

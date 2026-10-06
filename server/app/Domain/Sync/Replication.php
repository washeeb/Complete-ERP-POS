<?php

namespace App\Domain\Sync;

use App\Models\Device;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\TaxGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What a node shares about a record: a full snapshot of its row (plus owned child rows), so the
 * receiver can upsert it idempotently, in any node, any number of times.
 *
 * Node-local secrets never travel: 2FA secrets and recovery codes are encrypted with each node's
 * own APP_KEY, remember tokens are per node, device enrolment codes are for the node the device
 * enrols on. Password and PIN *hashes* do travel, so people can sign in on a branch server while
 * HQ is unreachable.
 */
final class Replication
{
    /** Sync groups, the tables each owns, and which way they flow by default (from the HQ's view). */
    public const GROUPS = [
        'org' => ['label' => 'Company, branches, tills, modules, settings', 'direction' => 'down',
            'tables' => ['tenant' => 'tenants', 'legal_entity' => 'legal_entities', 'branch' => 'branches', 'warehouse' => 'warehouses',
                'till' => 'tills', 'branch_module' => 'branch_modules', 'setting' => 'settings']],
        'people' => ['label' => 'Users, roles and access', 'direction' => 'down',
            'tables' => ['user' => 'users', 'role' => 'roles', 'role_assignment' => 'role_assignments']],
        'catalog' => ['label' => 'Products, units, barcodes, categories, brands', 'direction' => 'down',
            'tables' => ['product' => 'products', 'category' => 'categories', 'brand' => 'brands', 'uom' => 'uoms']],
        'pricing' => ['label' => 'Prices, price lists and tax', 'direction' => 'down',
            'tables' => ['price_list' => 'price_lists', 'tax_rate' => 'tax_rates', 'tax_group' => 'tax_groups']],
        'stock' => ['label' => 'Stock movements', 'direction' => 'both', 'tables' => ['stock_movement_batch' => null]],
        'sales' => ['label' => 'Till sales, shifts and devices', 'direction' => 'up',
            'tables' => ['device' => 'devices', 'shift' => 'shifts', 'sale' => 'sales']],
        'reviews' => ['label' => 'Items needing review', 'direction' => 'up', 'tables' => ['review_item' => 'review_items']],
    ];

    /** Master data: owned by HQ, read-only on branch nodes (conflict policy "HQ wins", spec §4.2). */
    public const MASTER_GROUPS = ['org', 'people', 'catalog', 'pricing'];

    private const NEVER = ['app_authentication_secret', 'app_authentication_recovery_codes', 'remember_token',
        'enrollment_code_hash', 'enrollment_lookup', 'enrollment_expires_at'];

    public static function groupOf(string $aggregateType): ?string
    {
        foreach (self::GROUPS as $group => $def) {
            if (array_key_exists($aggregateType, $def['tables'])) {
                return $group;
            }
        }

        return null;
    }

    public static function tableOf(string $aggregateType): ?string
    {
        $group = self::groupOf($aggregateType);

        return $group ? self::GROUPS[$group]['tables'][$aggregateType] : null;
    }

    public static function snapshot(Model $model): array
    {
        $row = $model->getAttributes(); // raw column values (hashes, json strings) — not casts, not hidden-filtered
        foreach (self::NEVER as $field) {
            unset($row[$field]);
        }
        $snapshot = ['row' => $row];

        if ($model instanceof Product) {
            $snapshot['children'] = [
                'product_uoms' => $model->units()->get()->map->getAttributes()->all(),
                'barcodes' => $model->barcodes()->get()->map->getAttributes()->all(),
                'prices' => $model->prices()->get()->map->getAttributes()->all(),
            ];
        } elseif ($model instanceof Role) {
            $snapshot['children'] = ['permission_keys' => $model->permissions()->pluck('key')->all()];
        } elseif ($model instanceof TaxGroup) {
            $snapshot['children'] = ['tax_rate_ids' => $model->rates()->pluck('tax_rates.id')->all()];
        } elseif ($model instanceof Sale) {
            $snapshot['children'] = [
                'sale_lines' => $model->lines()->get()->map->getAttributes()->all(),
                'sale_payments' => $model->payments()->get()->map->getAttributes()->all(),
            ];
        } elseif ($model instanceof SalesOrder) {
            $snapshot['children'] = ['sales_order_lines' => $model->lines()->get()->map->getAttributes()->all()];
        } elseif ($model instanceof Device) {
            $snapshot['row']['public_key'] = $model->getAttributes()['public_key'] ?? null; // lets HQ verify imported queues
        } elseif ($model instanceof User) {
            // keep password + pin_hash (hashes) for local sign-in on branch servers
        }

        return $snapshot;
    }
}

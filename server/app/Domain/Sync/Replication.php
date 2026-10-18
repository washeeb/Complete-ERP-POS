<?php

namespace App\Domain\Sync;

use App\Models\Bom;
use App\Models\CustomerOrder;
use App\Models\Delivery;
use App\Models\Device;
use App\Models\DiningOrder;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\RepairJob;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesRoute;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\TaxGroup;
use App\Models\User;
use App\Models\VanSettlement;
use App\Models\WorkOrder;
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
            'tables' => ['tenant' => 'tenants', 'legal_entity' => 'legal_entities', 'industry' => 'industries', 'branch' => 'branches', 'warehouse' => 'warehouses',
                'till' => 'tills', 'branch_module' => 'branch_modules', 'setting' => 'settings', 'license' => 'licenses', 'message_template' => 'message_templates', 'brand_asset' => 'brand_assets',
                'shift_template' => 'shift_templates', 'vault' => 'vaults']],
        'people' => ['label' => 'Users, roles and access', 'direction' => 'down',
            'tables' => ['user' => 'users', 'role' => 'roles', 'role_assignment' => 'role_assignments']],
        'catalog' => ['label' => 'Products, units, barcodes, categories, brands', 'direction' => 'down',
            'tables' => ['product' => 'products', 'category' => 'categories', 'brand' => 'brands', 'uom' => 'uoms',
                'kitchen_station' => 'kitchen_stations', 'dining_table' => 'dining_tables', 'modifier_group' => 'modifier_groups', 'style' => 'styles',
                'membership_plan' => 'membership_plans']],
        'pricing' => ['label' => 'Prices, price lists and tax', 'direction' => 'down',
            'tables' => ['price_list' => 'price_lists', 'tax_rate' => 'tax_rates', 'tax_group' => 'tax_groups', 'promotion' => 'promotions', 'markdown' => 'markdowns', 'drug_interaction' => 'drug_interactions']],
        'stock' => ['label' => 'Stock movements and batches', 'direction' => 'both', 'tables' => ['stock_movement_batch' => null, 'stock_batch' => 'stock_batches', 'serial_batch' => null,
            'licence_key' => 'licence_keys']],
        'sales' => ['label' => 'Till sales, shifts and devices', 'direction' => 'up',
            'tables' => ['device' => 'devices', 'shift' => 'shifts', 'sale' => 'sales', 'prescription' => 'prescriptions', 'dining_order' => 'dining_orders',
                'repair_job' => 'repair_jobs', 'alteration' => 'alterations', 'consignment_sale' => 'consignment_sales',
                'appointment' => 'appointments', 'commission' => 'commissions', 'visit' => 'visits']],
        'cash' => ['label' => 'Till vaults, cash handovers and cash differences', 'direction' => 'both',
            'tables' => ['vault_session' => 'vault_sessions', 'cash_handover' => 'cash_handovers', 'cash_variance' => 'cash_variances', 'cash_movement' => 'cash_movements']],
        'reviews' => ['label' => 'Items needing review and pharmacist sign-offs', 'direction' => 'up', 'tables' => ['review_item' => 'review_items', 'pharmacy_signoff' => 'pharmacy_signoffs']],
        'attendance' => ['label' => 'Clock-in and clock-out', 'direction' => 'up', 'tables' => ['attendance_batch' => null]],
        'purchasing' => ['label' => 'Suppliers, purchase orders and goods received', 'direction' => 'both',
            'tables' => ['supplier' => 'suppliers', 'purchase_order' => 'purchase_orders', 'goods_receipt' => 'goods_receipts']],
        'warehouse' => ['label' => 'Bins, transfers, stock requests, counts and reorder levels', 'direction' => 'both',
            'tables' => ['bin' => 'bins', 'stock_transfer' => 'stock_transfers', 'stock_request' => 'stock_requests', 'stock_count' => 'stock_counts', 'reorder_rule' => 'reorder_rules',
                'stock_adjustment' => 'stock_adjustments', 'supplier_return' => 'supplier_returns', 'van_settlement' => 'van_settlements']],
        'customers' => ['label' => 'Customers and what they owe', 'direction' => 'both',
            'tables' => ['customer' => 'customers', 'customer_ledger_batch' => null, 'loyalty_batch' => null, 'stored_value' => 'stored_values', 'stored_value_batch' => null, 'patient_profile' => 'patient_profiles',
                'reservation' => 'reservations', 'deposit_movement' => 'deposit_movements',
                'service_pass' => 'service_passes', 'service_pass_use' => 'service_pass_uses', 'membership' => 'memberships',
                'sales_route' => 'routes', 'rep_target' => 'rep_targets']],
        'receivables' => ['label' => 'Quotations, customer orders, deliveries, invoices, payments, credit notes', 'direction' => 'both',
            'tables' => ['customer_order' => 'customer_orders', 'delivery' => 'deliveries', 'invoice' => 'invoices', 'credit_note' => 'credit_notes',
                'customer_payment' => 'customer_payments']],
        'production' => ['label' => 'Work centres, bills of materials, work orders and quality checks', 'direction' => 'both',
            'tables' => ['work_centre' => 'work_centres', 'bom' => 'boms', 'work_order' => 'work_orders', 'work_order_cost' => 'work_order_costs',
                'qc_inspection' => 'qc_inspections']],
    ];

    /** Master data: owned by HQ, read-only on branch nodes (conflict policy "HQ wins", spec §4.2). */
    public const MASTER_GROUPS = ['org', 'people', 'catalog', 'pricing'];

    /** Two-way groups head office passes on: what one branch server sends reaches the other branch servers too. */
    public const RELAY_GROUPS = ['stock', 'purchasing', 'warehouse', 'customers', 'receivables', 'production'];

    /** Documents whose lines travel inside their snapshot: class => [relation, table]. */
    public const CHILDREN = [
        Supplier::class => ['products', 'supplier_products'],
        PurchaseOrder::class => ['lines', 'purchase_order_lines'],
        GoodsReceipt::class => ['lines', 'goods_receipt_lines'],
        StockTransfer::class => ['lines', 'stock_transfer_lines'],
        StockRequest::class => ['lines', 'stock_request_lines'],
        StockCount::class => ['lines', 'stock_count_lines'],
        StockAdjustment::class => ['lines', 'stock_adjustment_lines'],
        SupplierReturn::class => ['lines', 'supplier_return_lines'],
        VanSettlement::class => ['lines', 'van_settlement_lines'],
        Bom::class => ['lines', 'bom_lines'],
        WorkOrder::class => ['materials', 'work_order_materials'],
        SalesRoute::class => ['stops', 'route_stops'],
        ModifierGroup::class => ['options', 'modifier_options'],
        DiningOrder::class => ['items', 'dining_order_items'],
        RepairJob::class => ['lines', 'repair_job_lines'],
        CustomerOrder::class => ['lines', 'customer_order_lines'],
        Delivery::class => ['lines', 'delivery_lines'],
        Invoice::class => ['lines', 'invoice_lines'],
    ];

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
                'recipe_lines' => $model->recipe()->get()->map->getAttributes()->all(),
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
        } elseif (isset(self::CHILDREN[$model::class])) {
            [$relation, $table] = self::CHILDREN[$model::class];
            $snapshot['children'] = [$table => $model->{$relation}()->get()->map->getAttributes()->all()];
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

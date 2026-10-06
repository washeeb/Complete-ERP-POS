<?php

namespace App\Security;

/**
 * Seeded role templates (spec §9.2). Permissions listed here are attached only when the
 * permission itself is first created, so removing one from a role in the admin stays removed.
 * Roles whose departments ship later start empty and gain permissions as their modules land.
 */
final class RoleCatalog
{
    public const ROLES = [
        'owner' => ['Owner', 6, false, ['*']],
        'general_manager' => ['General Manager', 5, false, ['setup.company.manage', 'audit.view', 'pos.access', 'catalog.view', 'catalog.manage', 'pricing.manage', 'stock.view', 'stock.adjust', 'sales.view', 'sync.review', 'orders.view', 'orders.manage', 'channels.manage', 'suppliers.manage', 'purchasing.view', 'purchasing.manage', 'purchasing.approve', 'stock.receive', 'stock.receive.approve', 'stock.transfer', 'stock.count', 'stock.count.approve', 'warehouse.manage']],
        'finance_manager' => ['Finance Manager', 5, false, ['audit.view', 'catalog.view', 'stock.view', 'sales.view', 'sync.review', 'purchasing.view', 'purchasing.approve', 'stock.count.approve']],
        'accountant' => ['Accountant', 4, false, ['catalog.view', 'stock.view', 'sales.view', 'purchasing.view']],
        'auditor' => ['Auditor (read-only)', 1, false, ['audit.view', 'catalog.view', 'stock.view', 'purchasing.view']],
        'hr_manager' => ['HR Manager', 4, false, []],
        'payroll_officer' => ['Payroll Officer', 3, false, []],
        'purchasing_officer' => ['Purchasing Officer', 2, false, ['catalog.view', 'stock.view', 'suppliers.manage', 'purchasing.view', 'purchasing.manage', 'purchasing.approve']],
        'purchase_approver' => ['Purchase Approver', 4, false, ['catalog.view', 'stock.view', 'purchasing.view', 'purchasing.approve']],
        'warehouse_manager' => ['Warehouse Manager', 4, false, ['catalog.view', 'catalog.manage', 'stock.view', 'stock.adjust', 'purchasing.view', 'purchasing.manage', 'purchasing.approve', 'stock.receive', 'stock.receive.approve', 'stock.transfer', 'stock.count', 'stock.count.approve', 'warehouse.manage']],
        'storekeeper' => ['Storekeeper', 2, false, ['catalog.view', 'stock.view', 'purchasing.view', 'stock.receive', 'stock.transfer', 'stock.count']],
        'picker_packer' => ['Picker / Packer', 2, false, ['catalog.view', 'stock.view', 'stock.count']],
        'branch_manager' => ['Branch Manager', 4, false, ['audit.view', 'pos.access', 'catalog.view', 'stock.view', 'stock.adjust', 'sales.view', 'sync.review', 'orders.view', 'orders.manage', 'purchasing.view', 'purchasing.manage', 'purchasing.approve', 'stock.receive', 'stock.receive.approve', 'stock.transfer', 'stock.count', 'stock.count.approve', 'warehouse.manage']],
        'supervisor' => ['Supervisor', 3, false, ['pos.access', 'catalog.view', 'stock.view', 'sales.view', 'orders.view', 'orders.manage', 'purchasing.view', 'stock.receive', 'stock.transfer', 'stock.count']],
        'cashier' => ['Cashier', 2, false, ['pos.access']],
        'sales_rep' => ['Sales Rep', 2, false, ['catalog.view', 'stock.view']],
        'van_sales_rep' => ['Van Sales Rep', 2, false, []],
        'customer_service' => ['Customer Service', 2, false, ['orders.view']],
        'marketing' => ['Marketing', 3, false, []],
        'ecommerce_manager' => ['E-commerce Manager', 4, false, ['catalog.view', 'catalog.manage', 'stock.view', 'orders.view', 'orders.manage', 'channels.manage']],
        'delivery_rider' => ['Delivery Rider', 2, false, []],
        // Technical only: may hold technical permissions and nothing else (enforced in AccessService).
        'it_admin' => ['IT Admin', 4, true, ['@technical']],
    ];

    /** A limit key belongs to one permission; it is added to grants when that permission is first introduced. */
    public const LIMIT_PERMISSIONS = ['po_approve_minor' => 'purchasing.approve'];

    /** Default limits in the company currency's minor units (owner decision 2026-10-09: tiered PO approval). */
    public const LIMITS = [
        'owner' => ['po_approve_minor' => 999_999_999_999],
        'general_manager' => ['po_approve_minor' => 999_999_999_999],
        'finance_manager' => ['po_approve_minor' => 999_999_999_999],
        'purchase_approver' => ['po_approve_minor' => 999_999_999_999],
        'branch_manager' => ['po_approve_minor' => 2_000_000],     // GHS 20,000.00
        'warehouse_manager' => ['po_approve_minor' => 2_000_000],
        'purchasing_officer' => ['po_approve_minor' => 500_000],   // GHS 5,000.00
    ];

    /** @param list<array{key: string, is_technical: bool}> $permissions */
    public static function defaultsFor(string $roleKey, array $permissions): array
    {
        [, , , $spec] = self::ROLES[$roleKey];
        $keys = [];
        foreach ($permissions as $p) {
            if (in_array('*', $spec, true)
                || (in_array('@technical', $spec, true) && $p['is_technical'])
                || in_array($p['key'], $spec, true)) {
                $keys[] = $p['key'];
            }
        }

        return $keys;
    }
}

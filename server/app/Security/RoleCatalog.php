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
        'general_manager' => ['General Manager', 5, false, ['setup.company.manage', 'audit.view', 'pos.access', 'catalog.view', 'catalog.manage', 'pricing.manage', 'stock.view', 'stock.adjust', 'sales.view']],
        'finance_manager' => ['Finance Manager', 5, false, ['audit.view', 'catalog.view', 'stock.view', 'sales.view']],
        'accountant' => ['Accountant', 4, false, ['catalog.view', 'stock.view', 'sales.view']],
        'auditor' => ['Auditor (read-only)', 1, false, ['audit.view', 'catalog.view', 'stock.view']],
        'hr_manager' => ['HR Manager', 4, false, []],
        'payroll_officer' => ['Payroll Officer', 3, false, []],
        'purchasing_officer' => ['Purchasing Officer', 2, false, ['catalog.view', 'stock.view']],
        'purchase_approver' => ['Purchase Approver', 4, false, []],
        'warehouse_manager' => ['Warehouse Manager', 4, false, ['catalog.view', 'catalog.manage', 'stock.view', 'stock.adjust']],
        'storekeeper' => ['Storekeeper', 2, false, ['catalog.view', 'stock.view']],
        'picker_packer' => ['Picker / Packer', 2, false, []],
        'branch_manager' => ['Branch Manager', 4, false, ['audit.view', 'pos.access', 'catalog.view', 'stock.view', 'stock.adjust', 'sales.view']],
        'supervisor' => ['Supervisor', 3, false, ['pos.access', 'catalog.view', 'stock.view', 'sales.view']],
        'cashier' => ['Cashier', 2, false, ['pos.access']],
        'sales_rep' => ['Sales Rep', 2, false, ['catalog.view', 'stock.view']],
        'van_sales_rep' => ['Van Sales Rep', 2, false, []],
        'customer_service' => ['Customer Service', 2, false, []],
        'marketing' => ['Marketing', 3, false, []],
        'ecommerce_manager' => ['E-commerce Manager', 4, false, ['catalog.view', 'catalog.manage', 'stock.view']],
        'delivery_rider' => ['Delivery Rider', 2, false, []],
        // Technical only: may hold technical permissions and nothing else (enforced in AccessService).
        'it_admin' => ['IT Admin', 4, true, ['@technical']],
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

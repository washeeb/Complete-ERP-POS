<?php

namespace App\Security;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Modules\ModuleService;

/**
 * Seeded role templates (spec §9.2). Permissions listed here are attached only when the
 * permission itself is first created, so removing one from a role in the admin stays removed.
 * Roles whose departments ship later start empty and gain permissions as their modules land.
 */
final class RoleCatalog
{
    /** Roles that only make sense with an industry module (shown only where it is switched on). */
    public const MODULE_ROLES = ['pharmacist' => 'pharmacy', 'pharmacy_technician' => 'pharmacy', 'superintendent_pharmacist' => 'pharmacy',
        'medicine_counter_assistant' => 'pharmacy', 'doctor' => 'pharmacy', 'physician_assistant' => 'pharmacy', 'nurse' => 'pharmacy', 'midwife' => 'pharmacy',
        'lab_technician' => 'pharmacy', 'waiter' => 'restaurant', 'kitchen_staff' => 'restaurant',
        'technician' => 'electronics', 'tailor' => 'fashion', 'service_staff' => 'services', 'sales_rep' => 'distribution', 'van_sales_rep' => 'distribution',
        'production_manager' => 'manufacturing', 'machine_operator' => 'manufacturing', 'qc_inspector' => 'manufacturing', 'delivery_rider' => 'logistics'];

    /** Roles that only make sense with a licensed feature. */
    public const FEATURE_ROLES = ['hr_manager' => 'hr', 'payroll_officer' => 'hr'];

    /** @return list<string> role keys to leave out of pick lists here (industry not switched on, feature not licensed) */
    public static function hiddenKeys(): array
    {
        $licence = app(LicenseManager::class);
        $hidden = [];
        foreach (self::MODULE_ROLES as $role => $module) {
            if (! ModuleService::enabledAnywhere($module)) {
                $hidden[] = $role;
            }
        }
        foreach (self::FEATURE_ROLES as $role => $feature) {
            if (! $licence->allowsFeature($feature)) {
                $hidden[] = $role;
            }
        }

        return $hidden;
    }

    public const ROLES = [
        'owner' => ['Owner', 6, false, ['*']],
        'general_manager' => ['General Manager', 5, false, ['setup.company.manage', 'audit.view', 'pos.access', 'catalog.view', 'catalog.manage', 'pricing.manage', 'stock.view', 'stock.expiry', 'stock.adjust', 'stock.adjust.approve', 'sales.view', 'sync.review', 'orders.view', 'orders.manage', 'channels.manage', 'suppliers.manage', 'purchasing.view', 'purchasing.manage', 'purchasing.approve', 'stock.receive', 'stock.receive.approve', 'stock.transfer', 'stock.count', 'stock.count.approve', 'warehouse.manage', 'customers.view', 'customers.manage', 'customers.credit.manage', 'customers.credit.override', 'sales.orders.view', 'sales.orders.manage', 'sales.deliver', 'receivables.view', 'receivables.collect', 'receivables.credit_note', 'promotions.manage', 'pos.discount', 'pos.drawer', 'pos.return.no_receipt', 'giftcards.manage', 'finance.view', 'finance.reopen', 'payables.view', 'payables.approve', 'payables.pay', 'expenses.claim', 'expenses.approve', 'budgets.manage', 'hr.view', 'leave.approve', 'payroll.approve', 'leave.request', 'grocery.markdowns', 'pharmacy.manage', 'pharmacy.patients', 'restaurant.serve', 'restaurant.void', 'restaurant.manage', 'restaurant.waste', 'electronics.repairs', 'electronics.trade_in', 'fashion.alterations', 'fashion.consignment', 'services.appointments', 'services.passes', 'services.memberships', 'services.commissions', 'services.licence_keys', 'distribution.routes', 'distribution.settle', 'manufacturing.setup', 'manufacturing.produce', 'manufacturing.qc', 'insights.view', 'insights.loss_prevention', 'ai.copilot', 'ai.usage.view', 'reports.build', 'reports.schedule', 'messaging.view', 'messaging.templates', 'api.manage', 'golive.manage', 'cash.view', 'cash.setup', 'cash.vault.issue', 'cash.vault.receive', 'cash.handover', 'cash.control', 'cash.variance.approve', 'cash.deposit', 'stock.request', 'stock.request.approve']],
        'finance_manager' => ['Finance Manager', 5, false, ['audit.view', 'catalog.view', 'stock.view', 'sales.view', 'sync.review', 'purchasing.view', 'purchasing.approve', 'stock.count.approve', 'customers.view', 'customers.credit.manage', 'customers.credit.override', 'sales.orders.view', 'receivables.view', 'receivables.collect', 'receivables.credit_note', 'giftcards.manage', 'finance.view', 'finance.journal', 'finance.accounts.manage', 'finance.close', 'payables.view', 'payables.manage', 'payables.approve', 'payables.pay', 'banking.manage', 'expenses.claim', 'expenses.approve', 'expenses.pay', 'assets.manage', 'budgets.manage', 'hr.view', 'payroll.approve', 'payroll.pay', 'leave.request', 'insights.view', 'insights.loss_prevention', 'ai.copilot', 'ai.usage.view', 'reports.build', 'reports.schedule', 'messaging.view', 'cash.view', 'cash.control', 'cash.variance.approve', 'cash.deposit']],
        'accountant' => ['Accountant', 4, false, ['catalog.view', 'stock.view', 'sales.view', 'purchasing.view', 'customers.view', 'sales.orders.view', 'receivables.view', 'receivables.collect', 'receivables.credit_note', 'finance.view', 'finance.journal', 'payables.view', 'payables.manage', 'banking.manage', 'expenses.claim', 'expenses.pay', 'assets.manage', 'leave.request', 'reports.build']],
        'auditor' => ['Auditor (read-only)', 1, false, ['audit.view', 'catalog.view', 'stock.view', 'purchasing.view', 'customers.view', 'sales.orders.view', 'receivables.view', 'finance.view', 'payables.view', 'cash.view']],
        'hr_manager' => ['HR Manager', 4, false, ['expenses.claim', 'hr.view', 'hr.manage', 'attendance.manage', 'leave.approve', 'loans.manage', 'payroll.prepare', 'leave.request']],
        'payroll_officer' => ['Payroll Officer', 3, false, ['hr.view', 'payroll.prepare', 'leave.request']],
        'purchasing_officer' => ['Purchasing Officer', 2, false, ['catalog.view', 'stock.view', 'suppliers.manage', 'purchasing.view', 'purchasing.manage', 'purchasing.approve', 'payables.view', 'expenses.claim', 'leave.request']],
        'purchase_approver' => ['Purchase Approver', 4, false, ['catalog.view', 'stock.view', 'purchasing.view', 'purchasing.approve', 'leave.request']],
        'warehouse_manager' => ['Warehouse Manager', 4, false, ['catalog.view', 'catalog.manage', 'stock.view', 'stock.expiry', 'stock.adjust', 'stock.adjust.approve', 'purchasing.view', 'purchasing.manage', 'purchasing.approve', 'stock.receive', 'stock.receive.approve', 'stock.transfer', 'stock.count', 'stock.count.approve', 'warehouse.manage', 'sales.orders.view', 'sales.deliver', 'expenses.claim', 'expenses.approve', 'leave.approve', 'leave.request', 'grocery.markdowns', 'distribution.settle', 'insights.view', 'ai.copilot', 'reports.build', 'stock.request', 'stock.request.approve']],
        'storekeeper' => ['Storekeeper', 2, false, ['catalog.view', 'stock.view', 'stock.expiry', 'purchasing.view', 'stock.receive', 'stock.transfer', 'stock.count', 'sales.orders.view', 'sales.deliver', 'expenses.claim', 'leave.request', 'stock.request']],
        'picker_packer' => ['Picker / Packer', 2, false, ['catalog.view', 'stock.view', 'stock.count', 'sales.orders.view', 'sales.deliver', 'leave.request']],
        'branch_manager' => ['Branch Manager', 4, false, ['audit.view', 'pos.access', 'catalog.view', 'stock.view', 'stock.expiry', 'stock.adjust', 'stock.adjust.approve', 'sales.view', 'sync.review', 'orders.view', 'orders.manage', 'purchasing.view', 'purchasing.manage', 'purchasing.approve', 'stock.receive', 'stock.receive.approve', 'stock.transfer', 'stock.count', 'stock.count.approve', 'warehouse.manage', 'customers.view', 'customers.manage', 'customers.credit.override', 'sales.orders.view', 'sales.orders.manage', 'sales.deliver', 'receivables.view', 'receivables.collect', 'receivables.credit_note', 'pos.discount', 'pos.drawer', 'pos.return.no_receipt', 'giftcards.manage', 'expenses.claim', 'expenses.approve', 'expenses.pay', 'hr.view', 'attendance.manage', 'leave.approve', 'leave.request', 'grocery.markdowns', 'pharmacy.patients', 'restaurant.serve', 'restaurant.void', 'restaurant.manage', 'restaurant.waste', 'electronics.repairs', 'electronics.trade_in', 'fashion.alterations', 'services.appointments', 'services.passes', 'services.memberships', 'services.commissions', 'services.licence_keys', 'distribution.routes', 'distribution.settle', 'manufacturing.setup', 'manufacturing.produce', 'manufacturing.qc', 'insights.view', 'insights.loss_prevention', 'ai.copilot', 'reports.build', 'reports.schedule', 'messaging.view', 'cash.view', 'cash.setup', 'stock.request', 'stock.request.approve']],
        'supervisor' => ['Supervisor', 3, false, ['pos.access', 'catalog.view', 'stock.view', 'sales.view', 'orders.view', 'orders.manage', 'purchasing.view', 'stock.receive', 'stock.transfer', 'stock.count', 'customers.view', 'customers.manage', 'customers.credit.override', 'sales.orders.view', 'receivables.collect', 'pos.discount', 'pos.drawer', 'pos.return.no_receipt', 'expenses.claim', 'attendance.manage', 'leave.request', 'grocery.markdowns', 'restaurant.serve', 'restaurant.void', 'restaurant.waste', 'electronics.repairs', 'electronics.trade_in', 'fashion.alterations', 'services.appointments', 'services.passes', 'distribution.settle', 'manufacturing.produce', 'stock.request']],
        'pharmacist' => ['Pharmacist', 4, false, ['pos.access', 'catalog.view', 'stock.view', 'stock.expiry', 'sales.view', 'customers.view', 'customers.manage', 'stock.receive', 'stock.count', 'expenses.claim', 'leave.request', 'pharmacy.dispense', 'pharmacy.patients', 'pharmacy.manage', 'stock.request']],
        'pharmacy_technician' => ['Pharmacy Technician', 2, false, ['pos.access', 'catalog.view', 'stock.view', 'stock.expiry', 'customers.view', 'stock.receive', 'stock.count', 'leave.request', 'pharmacy.patients']],
        // Pharmacy and clinic staff (owner request 2026-10-16). Only pharmacists approve prescription-only and controlled
        // medicines (pharmacy.dispense); prescribers and nurses see patients' records but do not dispense.
        'superintendent_pharmacist' => ['Superintendent Pharmacist', 4, false, ['pos.access', 'catalog.view', 'catalog.manage', 'stock.view', 'stock.expiry', 'stock.adjust.approve', 'stock.adjust', 'stock.receive',
            'stock.receive.approve', 'stock.count', 'stock.count.approve', 'stock.request', 'stock.request.approve', 'purchasing.view', 'purchasing.manage', 'sales.view',
            'customers.view', 'customers.manage', 'pharmacy.dispense', 'pharmacy.patients', 'pharmacy.manage', 'insights.view', 'expenses.claim', 'leave.request']],
        'medicine_counter_assistant' => ['Medicine Counter Assistant (MCA)', 2, false, ['pos.access', 'catalog.view', 'stock.view', 'customers.view', 'pharmacy.patients', 'leave.request']],
        'doctor' => ['Doctor / Medical Officer', 3, false, ['customers.view', 'customers.manage', 'pharmacy.patients', 'leave.request']],
        'physician_assistant' => ['Physician Assistant', 3, false, ['customers.view', 'customers.manage', 'pharmacy.patients', 'leave.request']],
        'nurse' => ['Nurse', 2, false, ['pos.access', 'customers.view', 'pharmacy.patients', 'leave.request']],
        'midwife' => ['Midwife', 2, false, ['pos.access', 'customers.view', 'pharmacy.patients', 'leave.request']],
        'lab_technician' => ['Laboratory Technician', 2, false, ['pos.access', 'catalog.view', 'customers.view', 'pharmacy.patients', 'leave.request']],
        'waiter' => ['Waiter / Bartender', 2, false, ['pos.access', 'customers.view', 'leave.request', 'restaurant.serve', 'restaurant.waste']],
        'kitchen_staff' => ['Kitchen Staff', 1, false, ['leave.request', 'restaurant.waste']],
        'technician' => ['Repair Technician', 2, false, ['pos.access', 'catalog.view', 'stock.view', 'customers.view', 'leave.request', 'electronics.repairs']],
        'tailor' => ['Tailor', 2, false, ['pos.access', 'customers.view', 'leave.request', 'fashion.alterations']],
        'service_staff' => ['Stylist / Therapist', 2, false, ['pos.access', 'customers.view', 'leave.request', 'services.appointments']],
        'teller' => ['Teller', 2, false, ['pos.access', 'customers.view', 'leave.request']], // sells at a till (was "Cashier" before 2026-10-12)
        // Cash chain (owner decision 2026-10-12): the Cashier issues floats, receives the tellers' vaults and hands the day to
        // the Income Controller (accounts). Neither sells: whoever checks the money does not take it at a till.
        'cashier' => ['Cashier', 3, false, ['cash.view', 'cash.vault.issue', 'cash.vault.receive', 'cash.handover', 'sales.view', 'customers.view', 'leave.request']],
        'income_controller' => ['Income Controller', 4, false, ['cash.view', 'cash.control', 'cash.variance.approve', 'cash.deposit', 'sales.view', 'finance.view', 'reports.build', 'leave.request']],
        'sales_rep' => ['Sales Rep', 2, false, ['catalog.view', 'stock.view', 'customers.view', 'customers.manage', 'sales.orders.view', 'sales.orders.manage', 'receivables.view', 'expenses.claim', 'leave.request']],
        'van_sales_rep' => ['Van Sales Rep', 2, false, ['customers.view', 'sales.orders.view', 'sales.orders.manage', 'sales.deliver', 'receivables.collect', 'expenses.claim', 'leave.request', 'pos.access', 'stock.view', 'catalog.view']],
        'production_manager' => ['Production Manager', 4, false, ['catalog.view', 'stock.view', 'stock.transfer', 'stock.count', 'purchasing.view', 'sales.orders.view', 'manufacturing.setup', 'manufacturing.produce', 'manufacturing.qc', 'expenses.claim', 'leave.approve', 'leave.request', 'insights.view', 'ai.copilot', 'stock.request']],
        'machine_operator' => ['Machine Operator', 2, false, ['catalog.view', 'stock.view', 'manufacturing.produce', 'leave.request']],
        'qc_inspector' => ['Quality Inspector', 3, false, ['catalog.view', 'stock.view', 'manufacturing.qc', 'leave.request']],
        'customer_service' => ['Customer Service', 2, false, ['orders.view', 'customers.view', 'customers.manage', 'sales.orders.view', 'leave.request']],
        'marketing' => ['Marketing', 3, false, ['customers.view', 'promotions.manage', 'expenses.claim', 'leave.request', 'messaging.view', 'messaging.templates']],
        'ecommerce_manager' => ['E-commerce Manager', 4, false, ['catalog.view', 'catalog.manage', 'stock.view', 'orders.view', 'orders.manage', 'channels.manage', 'customers.view', 'promotions.manage', 'expenses.claim', 'leave.request', 'insights.view']],
        'delivery_rider' => ['Delivery Rider', 2, false, ['expenses.claim', 'leave.request']],
        // Technical only: may hold technical permissions and nothing else (enforced in AccessService).
        'it_admin' => ['IT Admin', 4, true, ['@technical']],
    ];

    /** A limit key belongs to one permission; it is added to grants when that permission is first introduced. */
    public const LIMIT_PERMISSIONS = ['po_approve_minor' => 'purchasing.approve', 'credit_override_minor' => 'customers.credit.override', 'discount_bp' => 'pos.discount', 'expense_approve_minor' => 'expenses.approve'];

    /** Default limits in the company currency's minor units (owner decision 2026-10-09: tiered PO approval). */
    public const LIMITS = [
        'owner' => ['po_approve_minor' => 999_999_999_999, 'credit_override_minor' => 999_999_999_999, 'discount_bp' => 10000, 'expense_approve_minor' => 999_999_999_999],
        'general_manager' => ['po_approve_minor' => 999_999_999_999, 'credit_override_minor' => 999_999_999_999, 'discount_bp' => 10000, 'expense_approve_minor' => 999_999_999_999],
        'finance_manager' => ['po_approve_minor' => 999_999_999_999, 'credit_override_minor' => 999_999_999_999, 'expense_approve_minor' => 999_999_999_999],
        'purchase_approver' => ['po_approve_minor' => 999_999_999_999],
        'branch_manager' => ['po_approve_minor' => 2_000_000, 'credit_override_minor' => 2_000_000, 'discount_bp' => 2500, 'expense_approve_minor' => 50_000], // GHS 20,000.00; 25 %; claims GHS 500
        'supervisor' => ['credit_override_minor' => 100_000, 'discount_bp' => 1000], // GHS 1,000.00; 10 %
        'warehouse_manager' => ['po_approve_minor' => 2_000_000, 'expense_approve_minor' => 50_000],
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

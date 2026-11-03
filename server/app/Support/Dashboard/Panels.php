<?php

namespace App\Support\Dashboard;

use App\Filament\Widgets\BusinessStats;
use App\Filament\Widgets\IndustryHighlights;
use App\Filament\Widgets\IndustryOverview;
use App\Filament\Widgets\Panels\CashPanel;
use App\Filament\Widgets\Panels\CrmPanel;
use App\Filament\Widgets\Panels\HrPanel;
use App\Filament\Widgets\Panels\MyApprovals;
use App\Filament\Widgets\Panels\PaymentMixChart;
use App\Filament\Widgets\Panels\PharmacyPanel;
use App\Filament\Widgets\Panels\ProjectsPanel;
use App\Filament\Widgets\Panels\PurchasingPanel;
use App\Filament\Widgets\Panels\ReceivablesAgingChart;
use App\Filament\Widgets\Panels\SalesByBranchChart;
use App\Filament\Widgets\Panels\SalesTrendChart;
use App\Filament\Widgets\Panels\ServicePanel;
use App\Filament\Widgets\Panels\Shortcuts;
use App\Filament\Widgets\Panels\SpendByMonthChart;
use App\Filament\Widgets\Panels\StockWatch;
use App\Filament\Widgets\Panels\TopProductsTable;
use App\Models\User;
use App\Security\RoleCatalog;
use Throwable;

/**
 * The dashboard's panels (interface stage 1b): what exists, how wide each is at first, and each role's starting
 * dashboard. A person can add, remove, order and resize the panels they may see (Dashboard → Arrange); a panel still
 * shows only when its own permissions allow (each widget's canView()), so a saved dashboard never shows more than the
 * person may see today.
 */
final class Panels
{
    /** key => [widget class, title, what it shows, default size] */
    public const CATALOGUE = [
        'figures' => [BusinessStats::class, 'Key figures', 'Sales today and this month, what customers owe and what you owe, alerts.', 'full'],
        'approvals' => [MyApprovals::class, 'Waiting for you', 'Orders, stock adjustments, expense claims and leave waiting for your approval.', 'third'],
        'shortcuts' => [Shortcuts::class, 'Shortcuts', 'Your favourite pages and the records you create most.', 'third'],
        'sales_trend' => [SalesTrendChart::class, 'Sales trend', 'Sales day by day: the last 7, 30 or 90 days.', 'two_thirds'],
        'payment_mix' => [PaymentMixChart::class, 'How customers paid', 'Cash, mobile money, card… this month.', 'third'],
        'sales_by_branch' => [SalesByBranchChart::class, 'Sales by branch', 'This month, branch by branch.', 'half'],
        'top_products' => [TopProductsTable::class, 'Best sellers', 'The ten products that brought in most this month.', 'half'],
        'stock_watch' => [StockWatch::class, 'Stock', 'Low stock, expired and expiring batches, stock value.', 'full'],
        'purchasing' => [PurchasingPanel::class, 'Buying', 'Orders waiting for approval, open orders, deliveries due, ordered this month.', 'full'],
        'spend_by_month' => [SpendByMonthChart::class, 'Spend by month', 'What was bought from suppliers, month by month.', 'half'],
        'receivables_aging' => [ReceivablesAgingChart::class, 'Customers owe, by how late', 'Not yet due, 1–30, 31–60, 61–90 and over 90 days.', 'half'],
        'cash' => [CashPanel::class, 'Cash', 'Tills open now, over and short this month.', 'full'],
        'pharmacy' => [PharmacyPanel::class, 'Pharmacy', 'Prescriptions today and this month, medicines expiring.', 'full'],
        'service' => [ServicePanel::class, 'Service desk and maintenance', 'Open and late tickets, customers’ rating, equipment not working, open work orders (Service desk add-on).', 'full'],
        'projects' => [ProjectsPanel::class, 'Projects', 'Projects in progress and over budget, time and materials not yet invoiced, your hours, timesheets to approve (Projects add-on).', 'full'],
        'crm' => [CrmPanel::class, 'Customers and deals', 'Open deals and their weighted value, won this month, new leads, your follow-ups (CRM add-on).', 'full'],
        'hr' => [HrPanel::class, 'People', 'Staff, who is on leave today, leave waiting, the last payroll.', 'full'],
        'industries' => [IndustryOverview::class, 'Industries', 'Sales of each kind of business you run.', 'full'],
        'industry_highlights' => [IndustryHighlights::class, 'Industry highlights', 'For the industry chosen at the top: what matters most there (e.g. prescriptions and expiry for a pharmacy).', 'full'],
    ];

    public const SIZES = ['third' => 'A third', 'half' => 'Half', 'two_thirds' => 'Two thirds', 'full' => 'Full width'];

    /** Grid columns (12 on a computer, one on a phone) each size takes. */
    public const SPANS = ['third' => 4, 'half' => 6, 'two_thirds' => 8, 'full' => 12];

    /** Each role's starting dashboard, most senior first; a person with several roles gets the first that applies. */
    public const ROLE_DEFAULTS = [
        'owner' => ['figures', 'industry_highlights', 'approvals', 'sales_trend', 'payment_mix', 'sales_by_branch', 'top_products', 'purchasing', 'stock_watch', 'receivables_aging', 'spend_by_month', 'industries', 'service', 'projects', 'crm', 'shortcuts'],
        'general_manager' => ['figures', 'industry_highlights', 'approvals', 'sales_trend', 'payment_mix', 'sales_by_branch', 'top_products', 'purchasing', 'stock_watch', 'receivables_aging', 'spend_by_month', 'service', 'projects', 'crm', 'shortcuts'],
        'finance_manager' => ['figures', 'approvals', 'receivables_aging', 'spend_by_month', 'sales_trend', 'payment_mix', 'purchasing', 'shortcuts'],
        'accountant' => ['figures', 'receivables_aging', 'spend_by_month', 'sales_trend', 'payment_mix', 'approvals', 'shortcuts'],
        'auditor' => ['figures', 'receivables_aging', 'spend_by_month', 'sales_trend', 'stock_watch', 'shortcuts'],
        'hr_manager' => ['hr', 'approvals', 'shortcuts'],
        'payroll_officer' => ['hr', 'shortcuts'],
        'superintendent_pharmacist' => ['pharmacy', 'stock_watch', 'figures', 'top_products', 'approvals', 'shortcuts'],
        'pharmacist' => ['pharmacy', 'stock_watch', 'top_products', 'approvals', 'shortcuts'],
        'branch_manager' => ['figures', 'approvals', 'sales_trend', 'payment_mix', 'top_products', 'stock_watch', 'cash', 'service', 'projects', 'crm', 'shortcuts'],
        'purchase_approver' => ['approvals', 'purchasing', 'spend_by_month', 'shortcuts'],
        'purchasing_officer' => ['purchasing', 'stock_watch', 'spend_by_month', 'approvals', 'shortcuts'],
        'warehouse_manager' => ['stock_watch', 'approvals', 'purchasing', 'shortcuts'],
        'stock_controller' => ['stock_watch', 'approvals', 'purchasing', 'shortcuts'],
        'storekeeper' => ['stock_watch', 'purchasing', 'shortcuts'],
        'supervisor' => ['figures', 'sales_trend', 'payment_mix', 'cash', 'approvals', 'shortcuts'],
        'income_controller' => ['cash', 'figures', 'payment_mix', 'shortcuts'],
        'cashier' => ['cash', 'shortcuts'],
        'technician' => ['service', 'shortcuts'],
        'marketing' => ['crm', 'shortcuts'],
        'customer_service' => ['service', 'crm', 'approvals', 'shortcuts'],
        'sales_rep' => ['crm', 'shortcuts'],
    ];

    public const FALLBACK = ['figures', 'approvals', 'shortcuts'];

    /** @return list<class-string> every panel's widget (registered with the panel so Livewire can find them) */
    public static function classes(): array
    {
        return array_values(array_unique(array_column(self::CATALOGUE, 0)));
    }

    /**
     * The person's dashboard: their own arrangement, else their role's; only panels they may see.
     *
     * @return list<array{key: string, size: string}>
     */
    public static function layoutFor(?User $user): array
    {
        $rows = $user?->dashboard ?: self::roleDefault($user);

        return array_values(array_filter(self::clean($rows), fn ($p) => self::canView($p['key'])));
    }

    /** @return list<array{key: string, size: string}> */
    public static function roleDefault(?User $user): array
    {
        $keys = self::FALLBACK;
        if ($user) {
            $roles = $user->roleAssignments()->active()->with('role:id,key')->get()->pluck('role.key')->filter()->all();
            foreach (self::ROLE_DEFAULTS as $role => $panels) {
                if (in_array($role, $roles, true)) {
                    $keys = $panels;
                    break;
                }
            }
        }

        return array_map(fn ($k) => ['key' => $k, 'size' => self::CATALOGUE[$k][3]], $keys);
    }

    /** Known panels only, each once, with a known size. */
    public static function clean(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $key = is_array($r) ? (string) ($r['key'] ?? '') : (string) $r;
            if (! isset(self::CATALOGUE[$key]) || isset($out[$key])) {
                continue;
            }
            $size = is_array($r) && isset(self::SIZES[$r['size'] ?? '']) ? $r['size'] : self::CATALOGUE[$key][3];
            $out[$key] = ['key' => $key, 'size' => $size];
        }

        return array_values($out);
    }

    public static function canView(string $key): bool
    {
        try {
            return isset(self::CATALOGUE[$key]) && self::CATALOGUE[$key][0]::canView();
        } catch (Throwable) {
            return false;
        }
    }

    /** Roles named in the defaults must exist (a renamed role would silently fall back). */
    public static function unknownRoles(): array
    {
        return array_values(array_diff(array_keys(self::ROLE_DEFAULTS), array_keys(RoleCatalog::ROLES)));
    }
}

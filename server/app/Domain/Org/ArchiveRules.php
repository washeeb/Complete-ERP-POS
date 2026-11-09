<?php

namespace App\Domain\Org;

use App\Models;
use App\Models\Branch;
use App\Security\RoleCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What archiving means for each kind of record (owner 2026-11-09: "anything that cannot be deleted for record keeping
 * can be archived so it does not mess with the system"): its name, who may do it, what its on/off switch does, and
 * what must happen first. Documents (sales, invoices, orders, journals) are not here: they are cancelled or voided.
 *
 * Switch:
 *  - replaced: the switch only meant "in use"; archiving turns it off and restoring turns it back on.
 *  - kept: the switch means something else (for sale, running, offered); archiving turns it off, restoring leaves it
 *    off for the person to decide.
 *  - none: no switch; the record's own lists and pickers leave archived ones out.
 */
final class ArchiveRules
{
    /** class => [label, audit name, permission, switch, scoped to a branch]. */
    public const TYPES = [
        Branch::class => ['branch', 'branch', 'setup.company.manage', 'replaced', true],
        Models\Industry::class => ['industry', 'industry', 'setup.company.manage', 'replaced', false],
        Models\LegalEntity::class => ['legal entity', 'legal_entity', 'setup.company.manage', 'replaced', false],
        Models\Device::class => ['device', 'device', 'admin.devices.manage', 'none', true],
        Models\Warehouse::class => ['store', 'warehouse', 'setup.company.manage', 'replaced', true],
        Models\Till::class => ['till', 'till', 'setup.company.manage', 'replaced', true],
        Models\Bin::class => ['bin', 'bin', 'warehouse.manage', 'replaced', true],
        Models\Product::class => ['product', 'product', 'catalog.manage', 'kept', false],
        Models\Category::class => ['category', 'category', 'catalog.manage', 'replaced', false],
        Models\PriceList::class => ['price list', 'price_list', 'pricing.manage', 'replaced', false],
        Models\PricingRule::class => ['pricing rule', 'pricing_rule', 'pricing.manage', 'kept', false],
        Models\Promotion::class => ['promotion', 'promotion', 'promotions.manage', 'kept', false],
        Models\Style::class => ['style', 'style', 'pricing.manage', 'kept', false],
        Models\User::class => ['user', 'user', 'admin.users.manage', 'replaced', false],
        Models\Employee::class => ['employee', 'employee', 'hr.manage', 'none', false],
        Models\Department::class => ['department', 'department', 'hr.manage', 'none', false],
        Models\Role::class => ['role', 'role', 'admin.roles.manage', 'none', false],
        Models\Customer::class => ['customer', 'customer', 'customers.manage', 'replaced', false],
        Models\Supplier::class => ['supplier', 'supplier', 'suppliers.manage', 'replaced', false],
        Models\Account::class => ['account', 'account', 'finance.accounts.manage', 'replaced', false],
        Models\BankAccount::class => ['bank account', 'bank_account', 'banking.manage', 'replaced', false],
        Models\TaxRate::class => ['tax rate', 'tax_rate', 'pricing.manage', 'replaced', false],
        Models\DiningTable::class => ['table', 'dining_table', 'restaurant.manage', 'replaced', true],
        Models\KitchenStation::class => ['kitchen station', 'kitchen_station', 'restaurant.manage', 'replaced', true],
        Models\ModifierGroup::class => ['choice group', 'modifier_group', 'restaurant.manage', 'kept', false],
        Models\MembershipPlan::class => ['membership plan', 'membership_plan', 'services.memberships', 'kept', false],
        Models\MessageTemplate::class => ['message', 'message_template', 'messaging.templates', 'kept', false],
        Models\SalesChannel::class => ['sales channel', 'sales_channel', 'channels.manage', 'none', false],
        Models\SalesRoute::class => ['route', 'route', 'distribution.routes', 'replaced', false],
        Models\WorkCentre::class => ['work centre', 'work_centre', 'manufacturing.setup', 'replaced', false],
        Models\MaintenanceAsset::class => ['equipment', 'maintenance_asset', 'maintenance.work', 'none', false],
        Models\IotSensor::class => ['sensor', 'iot_sensor', 'iot.manage', 'replaced', false],
        Models\CrmStage::class => ['stage', 'crm_stage', 'crm.setup', 'replaced', false],
    ];

    public static function supports(Model $r): bool
    {
        return isset(self::TYPES[$r::class]);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: bool} */
    public static function of(Model $r): array
    {
        return self::TYPES[$r::class] ?? throw new \InvalidArgumentException($r::class.' is not archived this way.');
    }

    /** The branch a branch-bound record belongs to, for the permission check. */
    public static function branchOf(Model $r): ?Branch
    {
        return match (true) {
            $r instanceof Branch => $r,
            $r instanceof Models\Bin => $r->warehouse?->branch,
            isset($r->branch_id) => Branch::find($r->branch_id),
            default => null,
        };
    }

    /** Why it cannot be archived now, in plain words; empty when it can. */
    public static function blockers(Model $r): array
    {
        $why = [];
        $add = function (bool $when, string $text) use (&$why) {
            if ($when) {
                $why[] = $text;
            }
        };
        $open = fn (string $table, string $column, array $statuses) => DB::table($table)->where($column, $r->getKey())->whereIn('status', $statuses)->count();
        $stockIn = fn (array $warehouses) => $warehouses && DB::table('stock_levels')->whereIn('warehouse_id', $warehouses)->where('on_hand', '!=', 0)->exists();

        match (true) {
            $r instanceof Models\Device => $add($r->status !== 'revoked',
                'It is still enrolled. Once the till has sent its sales, revoke it, then archive it.'),
            $r instanceof Models\Warehouse => [
                $add($stockIn([$r->id]), 'It still holds stock. Transfer the stock to another store, or count it to zero, first.'),
                $add(DB::table('stock_transfers')->where('status', 'in_transit')->where(fn ($q) => $q->where('from_warehouse_id', $r->id)->orWhere('to_warehouse_id', $r->id))->exists(),
                    'Stock is on the way to or from it. Receive or cancel those transfers first.'),
                $add(($tills = Models\Till::where('warehouse_id', $r->id)->where('is_active', true)->pluck('code'))->isNotEmpty(),
                    'Tills take their stock from it ('.$tills->implode(', ').'). Give them another store first.'),
            ],
            $r instanceof Models\Till => [
                $add(DB::table('shifts')->where('till_id', $r->id)->where('status', 'open')->exists(), 'A shift is still open on it. Close it first.'),
                $add(Models\Device::where('till_id', $r->id)->where('status', 'active')->exists(),
                    'A device is still enrolled on it. Once it has sent its sales, revoke it under System → Devices.'),
            ],
            $r instanceof Models\Bin => $add(DB::table('stock_bin_levels')->where('bin_id', $r->id)->where('on_hand', '!=', 0)->exists(),
                'It still holds stock. Move the stock to another bin first.'),
            $r instanceof Models\Product => $add(DB::table('stock_levels')->where('product_id', $r->id)->where('on_hand', '!=', 0)->exists(),
                'It still has stock. Sell, transfer or count it to zero first.'),
            $r instanceof Models\Category => $add(($n = Models\Product::where('category_id', $r->id)->whereNull('archived_at')->count()) > 0,
                "{$n} product(s) are in it. Move them to another category first (Move products)."),
            $r instanceof Models\PriceList => $add((bool) $r->is_default, 'It is the default price list. Make another one the default first.'),
            $r instanceof Models\User => [
                $add($r->id === auth()->id(), 'You cannot archive yourself.'),
                $add(self::onlyOwner($r), 'They are the only owner. Give the owner role to someone else first.'),
            ],
            $r instanceof Models\Employee => $add(! $r->termination_date, 'They still work here. Record their leaving date first.'),
            $r instanceof Models\Department => $add(($n = Models\Employee::where('department_id', $r->id)->whereNull('termination_date')->count()) > 0,
                "{$n} employee(s) are still in it. Move them to another department first."),
            $r instanceof Models\Role => [
                $add(isset(RoleCatalog::ROLES[$r->key]), 'It is one of the standard roles.'),
                $add(($n = self::holders($r)) > 0, "{$n} person(s) still hold it. Take it off them first."),
            ],
            $r instanceof Models\Customer => $add($r->balanceMinor() !== 0, 'Their account still has a balance. Settle it first.'),
            $r instanceof Models\Supplier => $add((int) DB::table('supplier_balances')->where('supplier_id', $r->id)->value('balance_minor') !== 0,
                'Their account still has a balance. Settle it first.'),
            $r instanceof Models\Account => [
                $add((bool) $r->control, 'It belongs to a sub-ledger (customers, suppliers, stock and similar) and stays.'),
                $add((bool) $r->system_key, 'The system posts to it. Point that setting at another account first.'),
                $add(Models\Account::where('parent_id', $r->id)->whereNull('archived_at')->exists(), 'Accounts in use sit under it. Archive or move them first.'),
                $add(Models\BankAccount::where('account_id', $r->id)->whereNull('archived_at')->exists(), 'A bank account in use books to it. Archive the bank account first.'),
                $add($r->isBalanceSheet() && (int) DB::table('journal_lines')->where('account_id', $r->id)->sum('amount_minor') !== 0,
                    'It still has a balance. Move the balance with a journal first.'),
            ],
            $r instanceof Models\BankAccount => $add((int) DB::table('journal_lines')->where('account_id', $r->account_id)->sum('amount_minor') !== 0,
                'It still has money in the books. Transfer the balance first.'),
            $r instanceof Models\TaxRate => $add(($groups = DB::table('tax_group_rates as r')->join('tax_groups as g', 'g.id', '=', 'r.tax_group_id')
                ->where('r.tax_rate_id', $r->id)->pluck('g.code'))->isNotEmpty(), 'Tax groups use it ('.$groups->implode(', ').'). Take it out of them first.'),
            $r instanceof Models\DiningTable => $add($open('dining_orders', 'dining_table_id', ['open']) > 0, 'An order is still open on it. Close it first.'),
            $r instanceof Models\SalesChannel => $add($r->status === 'active', 'It is still connected and selling. Pause it first.'),
            $r instanceof Models\MaintenanceAsset => $add(($n = $open('maintenance_orders', 'asset_id', ['open', 'working', 'waiting'])) > 0,
                "{$n} job(s) on it are not finished. Finish or cancel them first."),
            $r instanceof Models\CrmStage => $add(($n = $open('crm_deals', 'stage_id', ['open'])) > 0, "{$n} deal(s) in progress sit in it. Move them first."),
            default => null,
        };

        return $why;
    }

    private static function holders(Models\Role $role): int
    {
        return DB::table('role_assignments')->where('role_id', $role->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->distinct()->count('user_id');
    }

    private static function onlyOwner(Models\User $user): bool
    {
        $owner = Models\Role::where('key', 'owner')->value('id');
        if (! $owner) {
            return false;
        }
        $owners = DB::table('role_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.role_id', $owner)
            ->whereNull('a.revoked_at')->where('u.is_active', true)->distinct()->pluck('a.user_id');

        return $owners->count() === 1 && $owners->first() === $user->id;
    }
}

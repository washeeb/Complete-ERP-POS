<?php

namespace App\Domain\Finance;

use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starter chart of accounts for a Ghanaian SME trading business (IFRS for SMEs layout; owner decision 2026-10-12).
 * NOT VERIFIED by an accountant: names and codes can be changed under Finance → Chart of accounts; the system keys
 * (what automatic posting uses each account for) can be moved to another account.
 *
 * Rows: [code, name, type, parent code, header, control, system key]
 */
final class ChartTemplate
{
    public const GHANA_SME = [
        ['1000', 'Assets', 'asset', null, true, null, null],
        ['1100', 'Cash and bank', 'asset', '1000', true, null, null],
        ['1110', 'Cash on hand – tills', 'asset', '1100', false, null, 'cash_till'],
        ['1115', 'Cash with cashiers (received from tills)', 'asset', '1100', false, null, 'cash_cashier'],
        ['1120', 'Cash on hand – office and safe', 'asset', '1100', false, null, 'cash_office'],
        ['1125', 'Cash differences awaiting approval', 'asset', '1100', false, null, 'cash_differences'],
        ['1130', 'Bank – current account', 'asset', '1100', false, null, 'bank'],
        ['1140', 'Mobile money wallets', 'asset', '1100', false, null, 'momo'],
        ['1150', 'Card settlements due', 'asset', '1100', false, null, 'card_clearing'],
        ['1160', 'Online payments due', 'asset', '1100', false, null, 'online_clearing'],
        ['1170', 'Petty cash', 'asset', '1100', false, null, 'petty_cash'],
        ['1180', 'Trade-ins awaiting stock (clearing)', 'asset', '1100', false, null, 'trade_in_clearing'],
        ['1200', 'Receivables', 'asset', '1000', true, null, null],
        ['1210', 'Trade receivables (customer accounts)', 'asset', '1200', false, 'receivables', 'receivables'],
        ['1220', 'Staff advances and loans', 'asset', '1200', false, null, 'staff_loans'],
        ['1230', 'Other receivables', 'asset', '1200', false, null, null],
        ['1240', 'Due from delivery apps', 'asset', '1200', false, null, 'aggregator_receivable'],
        ['1300', 'Inventory', 'asset', '1000', true, null, null],
        ['1310', 'Stock – goods for resale', 'asset', '1300', false, 'inventory', 'inventory'],
        ['1320', 'Stock in transit between branches', 'asset', '1300', false, null, 'stock_in_transit'],
        ['1330', 'Returnable containers with customers', 'asset', '1300', false, null, 'containers_out'],
        ['1340', 'Work in progress (production)', 'asset', '1300', false, null, 'wip'],
        ['1400', 'Tax assets', 'asset', '1000', true, null, null],
        ['1410', 'Input VAT', 'asset', '1400', false, null, 'input_tax:VAT'],
        ['1411', 'Input NHIL', 'asset', '1400', false, null, 'input_tax:NHIL'],
        ['1412', 'Input GETFund levy', 'asset', '1400', false, null, 'input_tax:GETFUND'],
        ['1419', 'Other input tax', 'asset', '1400', false, null, 'input_tax'],
        ['1420', 'Withholding tax suffered', 'asset', '1400', false, null, null],
        ['1500', 'Prepayments and deposits', 'asset', '1000', false, null, null],
        ['1600', 'Property, plant and equipment', 'asset', '1000', true, null, null],
        ['1610', 'Furniture and fittings', 'asset', '1600', false, null, null],
        ['1620', 'Equipment and computers', 'asset', '1600', false, null, null],
        ['1630', 'Motor vehicles', 'asset', '1600', false, null, null],
        ['1690', 'Accumulated depreciation', 'asset', '1600', false, null, 'accumulated_depreciation'],

        ['2000', 'Liabilities', 'liability', null, true, null, null],
        ['2100', 'Trade payables (supplier accounts)', 'liability', '2000', false, 'payables', 'payables'],
        ['2110', 'Goods received not yet billed', 'liability', '2000', false, null, 'grni'],
        ['2200', 'Tax liabilities', 'liability', '2000', true, null, null],
        ['2210', 'Output VAT', 'liability', '2200', false, null, 'output_tax:VAT'],
        ['2211', 'NHIL payable', 'liability', '2200', false, null, 'output_tax:NHIL'],
        ['2212', 'GETFund levy payable', 'liability', '2200', false, null, 'output_tax:GETFUND'],
        ['2219', 'Other taxes on sales', 'liability', '2200', false, null, 'output_tax'],
        ['2220', 'PAYE payable', 'liability', '2200', false, null, 'paye'],
        ['2230', 'SSNIT payable (Tier 1)', 'liability', '2200', false, null, 'ssnit'],
        ['2231', 'Tier 2 pension payable', 'liability', '2200', false, null, 'tier2'],
        ['2232', 'Provident fund (Tier 3) payable', 'liability', '2200', false, null, 'tier3'],
        ['2233', 'Staff welfare dues payable', 'liability', '2200', false, null, 'welfare'],
        ['2240', 'Withholding tax payable', 'liability', '2200', false, null, null],
        ['2300', 'Owed to customers', 'liability', '2000', true, null, null],
        ['2310', 'Gift cards and store credit', 'liability', '2300', false, 'stored_value', 'stored_value'],
        ['2320', 'Loyalty points', 'liability', '2300', false, 'loyalty', 'loyalty'],
        ['2330', 'Customer deposits', 'liability', '2300', false, null, 'customer_deposits'],
        ['2340', 'Container deposits held', 'liability', '2300', false, null, 'container_deposits'],
        ['2250', 'Salaries payable', 'liability', '2000', false, null, 'net_pay'],
        ['2260', 'Tips owed to staff', 'liability', '2000', false, null, 'tips_payable'],
        ['2400', 'Accrued expenses', 'liability', '2000', false, null, null],
        ['2500', 'Loans and borrowings', 'liability', '2000', false, null, null],

        ['3000', 'Equity', 'equity', null, true, null, null],
        ['3100', "Owner's capital", 'equity', '3000', false, null, null],
        ['3200', 'Retained earnings', 'equity', '3000', false, null, 'retained_earnings'],
        ['3300', 'Opening balances (to clear)', 'equity', '3000', false, null, 'opening_equity'],
        ['3400', 'Drawings', 'equity', '3000', false, null, null],

        ['4000', 'Income', 'income', null, true, null, null],
        ['4100', 'Sales – shops (till)', 'income', '4000', false, null, 'sales_pos'],
        ['4110', 'Sales – wholesale (invoices)', 'income', '4000', false, null, 'sales_invoice'],
        ['4120', 'Sales – online store', 'income', '4000', false, null, 'sales_online'],
        ['4800', 'Other income', 'income', '4000', false, null, 'other_income'],
        ['4810', 'Unused gift cards (expired)', 'income', '4000', false, null, 'breakage'],
        ['4820', 'Gain or loss on sale of assets', 'income', '4000', false, null, 'disposal'],

        ['5000', 'Cost of sales', 'expense', null, true, null, null],
        ['5100', 'Cost of goods sold', 'expense', '5000', false, null, 'cogs'],
        ['5200', 'Stock losses and count differences', 'expense', '5000', false, null, 'stock_adjustment'],
        ['5300', 'Purchase price differences', 'expense', '5000', false, null, 'price_variance'],
        ['5400', 'Carriage inwards', 'expense', '5000', false, null, null],
        ['5500', 'Labour and overhead charged to production', 'expense', '5000', false, null, 'production_absorbed'],
        ['5600', 'Production cost differences', 'expense', '5000', false, null, 'production_variance'],

        ['6000', 'Operating expenses', 'expense', null, true, null, null],
        ['6100', 'Salaries and wages', 'expense', '6000', false, null, 'salaries'],
        ['6110', 'SSNIT – employer', 'expense', '6000', false, null, 'ssnit_employer'],
        ['6200', 'Rent', 'expense', '6000', false, null, null],
        ['6300', 'Electricity and water', 'expense', '6000', false, null, null],
        ['6400', 'Telephone and internet', 'expense', '6000', false, null, null],
        ['6500', 'Transport and fuel', 'expense', '6000', false, null, null],
        ['6600', 'Repairs and maintenance', 'expense', '6000', false, null, null],
        ['6700', 'Marketing and advertising', 'expense', '6000', false, null, 'marketing'],
        ['6710', 'Loyalty rewards', 'expense', '6000', false, null, 'loyalty_expense'],
        ['6800', 'Bank and MoMo charges', 'expense', '6000', false, null, 'bank_charges'],
        ['6810', 'Cash over and short', 'expense', '6000', false, null, 'cash_over_short'],
        ['6900', 'Depreciation', 'expense', '6000', false, null, 'depreciation'],
        ['6950', 'General and administrative', 'expense', '6000', false, null, null],
        ['7100', 'Interest and finance costs', 'expense', '6000', false, null, null],
        ['8100', 'Income tax', 'expense', '6000', false, null, null],
    ];

    /** Install the chart for a company that has none (never touches an existing chart). */
    public static function install(Tenant $tenant): int
    {
        if (Account::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return 0;
        }

        return DB::transaction(function () use ($tenant) {
            $ids = [];
            foreach (self::GHANA_SME as [$code, $name, $type, $parent, $header, $control, $key]) {
                $a = (new Account)->forceFill(['tenant_id' => $tenant->id, 'code' => $code, 'name' => $name, 'type' => $type,
                    'parent_id' => $parent ? $ids[$parent] : null, 'is_header' => $header, 'control' => $control, 'system_key' => $key]);
                $a->save();
                $ids[$code] = $a->id;
            }

            return count($ids);
        });
    }

    /** Default asset classes: [code, name, life in months, cost account code]. */
    public const ASSET_CLASSES = [['FURN', 'Furniture and fittings', 60, '1610'], ['COMP', 'Equipment and computers', 36, '1620'], ['VEH', 'Motor vehicles', 60, '1630']];

    /**
     * Add what a newer template needs to an existing chart: purposes nobody holds yet go to the template account
     * with that code (if it is free of another purpose and of the same type), or a new account is added. Default
     * asset classes are added when the company has none. Never renames, moves or removes anything.
     */
    public static function ensure(Tenant $tenant): void
    {
        if (! Account::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            self::install($tenant);
        }
        DB::transaction(function () use ($tenant) {
            $q = fn () => Account::withoutGlobalScopes()->where('tenant_id', $tenant->id);
            foreach (self::GHANA_SME as [$code, $name, $type, $parent, $header, $control, $key]) {
                if (! $key || $q()->where('system_key', $key)->exists()) {
                    continue;
                }
                $existing = $q()->where('code', $code)->first();
                if ($existing) {
                    if (! $existing->system_key && $existing->type === $type && ! $existing->is_header) {
                        $existing->forceFill(['system_key' => $key, 'version' => $existing->version + 1])->save();
                    }

                    continue;
                }
                (new Account)->forceFill(['tenant_id' => $tenant->id, 'code' => $code, 'name' => $name, 'type' => $type, 'is_header' => $header,
                    'parent_id' => $parent ? $q()->where('code', $parent)->value('id') : null, 'control' => $control, 'system_key' => $key])->save();
            }
            if (! DB::table('asset_classes')->where('tenant_id', $tenant->id)->exists()) {
                $acc = fn (string $key) => $q()->where('system_key', $key)->value('id');
                foreach (self::ASSET_CLASSES as [$code, $name, $life, $costCode]) {
                    $cost = $q()->where('code', $costCode)->value('id');
                    if ($cost && $acc('accumulated_depreciation') && $acc('depreciation')) {
                        DB::table('asset_classes')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'code' => $code,
                            'name' => $name, 'life_months' => $life, 'cost_account_id' => $cost, 'accumulated_account_id' => $acc('accumulated_depreciation'),
                            'expense_account_id' => $acc('depreciation'), 'origin_node' => (string) config('erp.node.id'), 'version' => 1,
                            'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        });
    }
}

<?php

namespace App\Domain\Imports;

use App\Models\PriceList;
use Illuminate\Support\Str;

/**
 * Where an import file comes from (Phase 14, data migration). Exports from QuickBooks and from other till systems
 * (Loyverse, Square, Lightspeed/Vend, Odoo, Excel sheets kept by hand) use their own column names: these presets match
 * them to ours, so the "match columns" step is filled in for the person. Headings are compared as CsvFile::key()
 * ("Product/Service Name" → product_service_name). A source can also tidy rows: join first and last names, take the
 * account number off "1010 Cash at bank", make a SKU from the name when the old system had none.
 */
final class ImportSources
{
    public const LABELS = [
        'excel' => 'Excel / CSV (our templates)',
        'quickbooks' => 'QuickBooks (Online or Desktop exports)',
        'other_pos' => 'Another till system (Loyverse, Square, Lightspeed, Odoo…)',
    ];

    /** source => import type => our column => headings it may have there (first found wins) */
    private const ALIASES = [
        'quickbooks' => [
            'products' => [
                'name' => ['product_service_name', 'product_service', 'item_name', 'item', 'name'],
                'sku' => ['sku', 'item_code'],
                'description' => ['sales_description', 'description'],
                'category' => ['category', 'type'],
            ],
            'prices' => [
                'sku' => ['sku', 'item_code'],
                'name' => ['product_service_name', 'product_service', 'item_name', 'item'],
                'price' => ['sales_price_rate', 'sales_price', 'rate', 'price'],
            ],
            'opening_stock' => [
                'sku' => ['sku', 'item_code'],
                'qty' => ['qty_on_hand', 'quantity_on_hand', 'on_hand'],
                'unit_cost' => ['purchase_cost', 'cost', 'avg_cost', 'average_cost'],
            ],
            'customers' => [
                'name' => ['customer', 'display_name', 'customer_name', 'full_name', 'name'],
                'phone' => ['phone', 'phone_numbers', 'main_phone', 'mobile'],
                'email' => ['email', 'main_email'],
                'contact_name' => ['full_name', 'contact', 'primary_contact'],
                'address' => ['billing_address', 'bill_to_1', 'address'],
                'tax_no' => ['tax_id', 'resale_num', 'tin'],
            ],
            'suppliers' => [
                'name' => ['vendor', 'supplier', 'display_name', 'vendor_name', 'company_name', 'name'],
                'phone' => ['phone', 'phone_numbers', 'main_phone'],
                'email' => ['email', 'main_email'],
                'contact_name' => ['full_name', 'contact', 'primary_contact'],
                'address' => ['address', 'billing_address', 'bill_from_1'],
                'tax_no' => ['tax_id', 'business_id_no', 'tin'],
            ],
            'accounts' => [
                'code' => ['account_number', 'number', 'acct_num', 'account_no', 'num'],
                'name' => ['account_name', 'account', 'name', 'full_name'],
                'type' => ['account_type', 'type'],
                'description' => ['description', 'detail_type'],
            ],
            'opening_balances' => [
                'account' => ['account', 'account_name', 'name'],
                'debit' => ['debit', 'dr'],
                'credit' => ['credit', 'cr'],
            ],
            'customer_balances' => [
                'customer' => ['customer', 'name', 'display_name'],
                'balance' => ['total', 'open_balance', 'balance', 'balance_total', 'amount'],
            ],
            'supplier_balances' => [
                'supplier' => ['vendor', 'supplier', 'name', 'display_name'],
                'balance' => ['total', 'open_balance', 'balance', 'balance_total', 'amount'],
                'due_on' => ['due_date'],
                'reference' => ['num', 'bill_no', 'ref_no'],
            ],
        ],
        'other_pos' => [
            'products' => [
                'sku' => ['sku', 'item_sku', 'handle', 'code', 'product_code', 'default_code', 'internal_reference'],
                'name' => ['name', 'item_name', 'product_name', 'title', 'product'],
                'description' => ['description', 'sales_description'],
                'category' => ['category', 'category_name', 'product_category', 'department', 'reporting_category'],
                'brand' => ['brand', 'brand_name', 'manufacturer'],
                'barcodes' => ['barcode', 'barcodes', 'gtin', 'upc', 'ean'],
            ],
            'prices' => [
                'sku' => ['sku', 'item_sku', 'handle', 'code', 'product_code', 'default_code', 'internal_reference'],
                'name' => ['name', 'item_name', 'product_name'],
                'price' => ['price', 'retail_price', 'sales_price', 'selling_price', 'default_price'],
            ],
            'opening_stock' => [
                'sku' => ['sku', 'item_sku', 'handle', 'code', 'product_code', 'default_code', 'internal_reference'],
                'qty' => ['in_stock', 'quantity', 'qty', 'on_hand', 'stock', 'current_quantity', 'inventory', 'quantity_on_hand'],
                'unit_cost' => ['cost', 'supply_price', 'unit_cost', 'cost_price', 'average_cost'],
            ],
            'customers' => [
                'code' => ['customer_code', 'code', 'reference_id'],
                'name' => ['customer_name', 'name', 'full_name', 'customer'],
                'phone' => ['phone', 'phone_number', 'mobile', 'telephone'],
                'email' => ['email', 'email_address'],
                'address' => ['address', 'street_address', 'address_1', 'street'],
                'tax_no' => ['tax_id', 'tin', 'vat'],
            ],
            'suppliers' => [
                'code' => ['supplier_code', 'code'],
                'name' => ['supplier_name', 'name', 'company', 'supplier', 'vendor'],
                'phone' => ['phone', 'phone_number', 'mobile'],
                'email' => ['email', 'email_address'],
                'contact_name' => ['contact', 'contact_name', 'contact_person'],
                'address' => ['address', 'street_address'],
            ],
            'customer_balances' => [
                'customer' => ['customer_code', 'customer_name', 'name', 'phone', 'customer'],
                'balance' => ['balance', 'amount_owed', 'debt', 'outstanding', 'account_balance'],
            ],
            'loyalty_points' => [
                'customer' => ['customer_code', 'phone', 'customer_name', 'name', 'customer'],
                'points' => ['points_balance', 'points', 'loyalty_points', 'reward_points', 'loyalty_balance'],
            ],
            'gift_cards' => [
                'code' => ['code', 'card_number', 'gift_card_number', 'number', 'gan'],
                'balance' => ['balance', 'remaining_balance', 'amount', 'current_balance', 'value'],
                'customer' => ['customer', 'customer_code', 'phone', 'customer_name'],
            ],
        ],
    ];

    /** Suggested "match columns" for a file: our column => the file's heading. */
    public static function suggest(string $source, string $type, array $headings, array $ourColumns): array
    {
        $out = [];
        $taken = [];
        foreach ($ourColumns as $col) {
            if (in_array($col, $headings, true)) {
                $taken[] = $col;
            }
        }
        foreach (self::ALIASES[$source][$type] ?? [] as $col => $aliases) {
            if (in_array($col, $headings, true)) {
                continue; // the file already has our column
            }
            foreach ($aliases as $alias) {
                if (in_array($alias, $headings, true) && ! in_array($alias, $taken, true) && $alias !== $col) {
                    $out[$col] = $alias;
                    $taken[] = $alias;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Tidy the rows read from a file from this source (after the columns were matched).
     *
     * @param  array{headings: list<string>, rows: list<array{line: int, values: array<string, string>}>}  $data
     */
    public static function prepare(string $source, string $type, array $data): array
    {
        if ($source === 'excel' || ! isset(self::LABELS[$source])) {
            return $data;
        }
        $add = [];
        $defaultList = $type === 'prices' ? PriceList::where('is_default', true)->value('code') : null;
        foreach ($data['rows'] as &$row) {
            $v = &$row['values'];
            // Old systems have one selling price: it goes on the default price list.
            if ($defaultList && trim((string) ($v['price_list'] ?? '')) === '') {
                $v['price_list'] = $defaultList;
                $add['price_list'] = true;
            }
            // First + last name (Square, Lightspeed, QuickBooks contact lists).
            if (in_array($type, ['customers', 'suppliers'], true) && trim((string) ($v['name'] ?? '')) === '') {
                $full = trim(trim((string) ($v['first_name'] ?? $v['given_name'] ?? '')).' '.trim((string) ($v['last_name'] ?? $v['surname'] ?? $v['family_name'] ?? '')));
                if ($full !== '') {
                    $v['name'] = $full;
                    $add['name'] = true;
                }
            }
            // No SKU in the old system: make one from the name, the same way every time (so prices and stock files match).
            if (in_array($type, ['products', 'prices', 'opening_stock'], true) && trim((string) ($v['sku'] ?? '')) === '') {
                $name = '';
                foreach (['name', ...self::ALIASES[$source]['products']['name']] as $col) { // a stock or price file keeps the old item-name column
                    if (trim((string) ($v[$col] ?? '')) !== '') {
                        $name = (string) $v[$col];
                        break;
                    }
                }
                if ($name !== '') {
                    $v['sku'] = self::skuFrom($source === 'quickbooks' && str_contains($name, ':') ? (string) strrchr($name, ':') : $name);
                    $add['sku'] = true;
                }
            }
            // QuickBooks sub-items "Drinks:Malta 330ml" → the last part is the item, the first the category.
            if ($source === 'quickbooks' && $type === 'products' && str_contains((string) ($v['name'] ?? ''), ':')) {
                $parts = array_map('trim', explode(':', (string) $v['name']));
                $v['name'] = (string) end($parts);
                if (trim((string) ($v['category'] ?? '')) === '' || in_array(strtolower((string) ($v['category'] ?? '')), ['inventory', 'non-inventory', 'service'], true)) {
                    $v['category'] = $parts[0];
                }
            }
            // QuickBooks "Type" (Inventory / Service) is not a category of ours.
            if ($source === 'quickbooks' && $type === 'products' && in_array(strtolower((string) ($v['category'] ?? '')), ['inventory', 'non-inventory', 'noninventory', 'service', 'bundle'], true)) {
                if (! isset($v['track_stock']) || trim((string) $v['track_stock']) === '') {
                    $v['track_stock'] = strtolower((string) $v['category']) === 'inventory' ? 'yes' : 'no';
                    $add['track_stock'] = true;
                }
                $v['category'] = '';
            }
        }
        unset($row, $v);
        $data['headings'] = array_values(array_unique(array_merge($data['headings'], array_keys($add))));

        return $data;
    }

    /** "Malta Guinness 330ml" → MALTA-GUINNESS-330ML (letters, digits, dashes; at most 40). */
    public static function skuFrom(string $name): string
    {
        $sku = trim((string) preg_replace('/[^A-Z0-9]+/', '-', strtoupper(Str::ascii($name))), '-');

        return rtrim(substr($sku, 0, 40), '-') ?: 'ITEM';
    }
}

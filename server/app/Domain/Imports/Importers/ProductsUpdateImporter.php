<?php

namespace App\Domain\Imports\Importers;

/**
 * Products — update existing: every row names an existing product by its SKU and changes only the filled-in cells.
 * It never creates a product (an unknown or missing SKU is refused), so importing the same file twice changes nothing.
 */
final class ProductsUpdateImporter extends ProductsImporter
{
    protected function mode(): string
    {
        return 'update';
    }

    public function key(): string
    {
        return 'products_update';
    }

    public function label(): string
    {
        return 'Products — update existing';
    }

    public function description(): string
    {
        return 'Changes EXISTING products, found by their SKU: start from "Download current data", change what you need and import it. Only filled-in cells '
            .'change; empty cells and missing columns stay as they are (barcodes too). A row without a known SKU is refused: nothing is ever created here, '
            .'so importing a file twice is harmless. To add products, use "Products — create new". An industry column moves a product to that industry.';
    }

    public function columns(): array
    {
        $cols = parent::columns();
        $cols[0] = ['name' => 'sku', 'required' => true, 'help' => 'The SKU of the product to change (from Download current data).', 'example' => 'PHARMA-000123'];

        return $cols;
    }
}

<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Catalog\UnitChangeService;
use App\Domain\Imports\Importer;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * Change the unit of many products from one file (owner request 2026-10-20), each with its own pack size: e.g. strips
 * of 10 and packs of 30 both to tablets. Convert (stock × per, price ÷ per) or relabel (the label was wrong).
 */
final class UnitChangesImporter extends Importer
{
    public function __construct(private readonly UnitChangeService $units) {}

    public function key(): string
    {
        return 'unit_changes';
    }

    public function label(): string
    {
        return 'Unit changes (e.g. Strip or Pack to Tablet)';
    }

    public function description(): string
    {
        return 'One row per product whose unit changes. convert: stock is multiplied by per (tablets in one strip or pack), the new unit\'s price is the old price divided by it, '
            .'and the old unit stays as a selling unit. relabel: the label was wrong, the numbers stay. Products on open documents (draft orders, transfers on the way, open counts) are refused: finish those first. '
            .'Products already in the new unit are skipped.';
    }

    public function permission(): string
    {
        return 'catalog.manage';
    }

    public function columns(): array
    {
        return [
            ['name' => 'sku', 'required' => true, 'help' => 'The product\'s SKU.', 'example' => 'PHARMA-000123'],
            ['name' => 'new_unit', 'required' => true, 'help' => 'The unit it is counted in from now on: code or name (TAB, Tablet).', 'example' => 'TAB'],
            ['name' => 'per', 'required' => false, 'help' => 'For convert: how many of the new unit in one of the current unit (10 tablets in a strip).', 'example' => '10'],
            ['name' => 'mode', 'required' => false, 'help' => 'convert (default) or relabel.', 'example' => 'convert'],
            ['name' => 'reason', 'required' => false, 'help' => 'Why (kept in the history). Default: Unit changes file.', 'example' => 'Sold in single tablets'],
        ];
    }

    public function reference(array $row): string
    {
        return (string) ($row['sku'] ?? '');
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $product = Product::where('sku', strtoupper(self::need($row, 'sku', 'the SKU')))->first() ?? throw new DomainException("Unknown SKU {$row['sku']}.");
        $unit = self::unitId(self::need($row, 'new_unit', 'the new unit')) ?? throw new DomainException("Unknown unit {$row['new_unit']}: add it under Catalog → Units of measure first.");
        if ($unit === $product->base_uom_id) {
            return 'unchanged';
        }
        $mode = strtolower(trim((string) ($row['mode'] ?? ''))) ?: 'convert';
        $reason = trim((string) ($row['reason'] ?? '')) ?: 'Unit changes file';
        if ($mode === 'relabel') {
            $this->units->relabel($actor, $product, $unit, $reason);

            return 'updated';
        }
        if ($mode !== 'convert') {
            throw new DomainException('mode: convert or relabel.');
        }
        $this->units->convert($actor, $product, $unit, self::number(self::need($row, 'per', 'how many of the new unit in one current unit (per)'), 'per'), $reason);

        return 'updated';
    }
}

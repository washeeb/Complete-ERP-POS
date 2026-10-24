<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Pharmacy → Near-expiry markdowns: medicines close to expiry to sell at a lower price, and expired stock to take off the shelf. */
class PharmacyMarkdowns extends GroceryMarkdowns
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Pharmacy';

    protected static ?string $navigationLabel = 'Near-expiry markdowns';

    protected static ?string $title = 'Near-expiry markdowns';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'pharmacy/markdowns';

    protected static string $module = 'pharmacy';
}
